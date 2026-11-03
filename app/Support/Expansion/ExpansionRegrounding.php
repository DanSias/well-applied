<?php

namespace App\Support\Expansion;

use App\Enums\ProposalStatus;
use App\Models\EvidenceExpansion;
use App\Models\EvidenceProposal;
use Illuminate\Support\Facades\DB;

/**
 * Applies the attribution grounding rules (ExpansionValidator::ground) to claims that already exist
 * and are still undecided, without another model call. A place the question and answer do not point to
 * is rejected with the reason in its history; approved facts and decided proposals are never touched.
 * A rejected place can be added back by the user at any time.
 */
final class ExpansionRegrounding
{
    public function __construct(private readonly ExpansionContext $context, private readonly ExpansionValidator $validator) {}

    /**
     * @return array<int, array<string, mixed>> one entry per claim group that changed or was checked
     */
    public function apply(EvidenceExpansion $expansion, bool $dryRun = false): array
    {
        $ctx = $this->context->build($expansion->answer);
        $report = [];

        foreach (EvidenceProposal::query()->where('expansion_id', $expansion->id)->where('status', ProposalStatus::Proposed)->orderBy('id')->get()->groupBy('claim_group') as $group => $members) {
            $targets = $members->map(fn (EvidenceProposal $p) => "{$p->attributable_type}:{$p->attributable_id}")->all();
            $grounded = $this->validator->ground($targets, $ctx);
            $dropped = array_values(array_diff($targets, array_keys($grounded['kept'])));
            $report[] = [
                'group' => $group, 'statement' => $members->first()->statement, 'before' => array_map(fn (string $t) => $ctx['targets'][$t]['label'] ?? $t, $targets),
                'kept' => array_map(fn (string $t) => $ctx['targets'][$t]['label'], array_keys($grounded['kept'])), 'basis' => $grounded['kept'],
                'dropped' => array_map(fn (string $t) => $ctx['targets'][$t]['label'] ?? $t, $dropped),
            ];

            if ($dryRun || ($dropped === [] && ! in_array('inferred', $grounded['kept'], true))) {
                continue;
            }

            DB::transaction(function () use ($members, $dropped, $grounded, $ctx) {
                foreach ($members as $member) {
                    $key = "{$member->attributable_type}:{$member->attributable_id}";

                    if (in_array($key, $dropped, true)) {
                        $member->fill(['status' => ProposalStatus::Rejected, 'decided_at' => now()]);
                        $member->recordHistory('ungrounded_attribution', ['note' => 'Neither the question nor the answer points to this place; it was left out. Add it back if it applies.']);
                        $member->save();

                        continue;
                    }

                    if (($grounded['kept'][$key] ?? null) === 'inferred' || in_array('inferred', $grounded['kept'], true)) {
                        $inferred = array_map(fn (string $k) => $ctx['targets'][$k]['label'], array_keys(array_filter($grounded['kept'], fn ($b) => $b === 'inferred')));
                        $member->confidence = $member->confidence === 'high' ? 'medium' : $member->confidence;

                        if ($inferred !== [] && ! str_contains((string) $member->rationale, 'Inferred from')) {
                            $member->rationale = trim($member->rationale.' Inferred from the answer\'s wording for: '.implode(', ', $inferred).'. Check it.');
                        }

                        $member->save();
                    }
                }
            });
        }

        if (! $dryRun) {
            $this->noteLeftOut($expansion, $ctx);
        }

        return $report;
    }

    /**
     * Tells the user, on the claim itself, which places were left out and why, so the model's original
     * rationale ("spans every role") is not left standing next to a narrower attribution.
     *
     * @param  array<string, mixed>  $ctx
     */
    private function noteLeftOut(EvidenceExpansion $expansion, array $ctx): void
    {
        $left = EvidenceProposal::query()->where('expansion_id', $expansion->id)->where('status', ProposalStatus::Rejected)->get()
            ->filter(fn (EvidenceProposal $p) => collect($p->history)->contains('action', 'ungrounded_attribution'))->groupBy('claim_group');

        foreach ($left as $group => $rejected) {
            $labels = $rejected->map(fn (EvidenceProposal $p) => $ctx['targets']["{$p->attributable_type}:{$p->attributable_id}"]['label'] ?? null)->filter()->values()->all();

            foreach (EvidenceProposal::query()->where('claim_group', $group)->where('status', ProposalStatus::Proposed)->get() as $member) {
                if (! str_contains((string) $member->rationale, 'Left out')) {
                    $member->rationale = trim($member->rationale.' Left out, because neither the question nor your answer points to them (add them back if they apply): '.implode('; ', $labels).'.');
                    $member->save();
                }
            }
        }
    }
}
