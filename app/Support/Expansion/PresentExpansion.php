<?php

namespace App\Support\Expansion;

use App\Enums\ProposalStatus;
use App\Enums\ResponsibilityScope;
use App\Jobs\ExpandAnswerClaimsJob;
use App\Models\ClarificationAnswer;
use App\Models\EvidenceExpansion;
use App\Models\EvidenceProposal;
use Illuminate\Support\Facades\Cache;

/**
 * What the profile page shows for one answer's expansion: the claims (each with its places and how
 * it relates to what is already recorded), why they were suggested, what the validation dropped, and
 * the suggested follow-ups. Read-only; derived from the stored expansion and proposals.
 */
final class PresentExpansion
{
    public function __construct(private readonly ExpansionDuplicates $duplicates) {}

    /**
     * @param  array<int, array{type: string, id: int, label: string}>  $options
     * @return array<string, mixed>
     */
    public function present(ClarificationAnswer $answer, array $options): array
    {
        $expansion = EvidenceExpansion::query()->where('clarification_answer_id', $answer->id)->latest('id')->first();
        $running = Cache::has(ExpandAnswerClaimsJob::lockKey($answer->id));
        $base = ['state' => $running ? 'running' : 'none', 'can_expand' => $answer->mode === 'experience'];

        if ($expansion === null) {
            return $base;
        }

        $labels = collect(ResponsibilityScope::cases())->mapWithKeys(fn (ResponsibilityScope $s) => [$s->value => $s->label()]);
        $byKey = collect($options)->keyBy(fn (array $o) => "{$o['type']}:{$o['id']}");
        $groups = EvidenceProposal::query()->where('expansion_id', $expansion->id)->where('status', '!=', ProposalStatus::Superseded)->with('careerFact')->orderBy('id')->get()->groupBy('claim_group');

        $claims = $groups->map(function ($members, $group) use ($labels, $byKey) {
            $lead = $members->first();

            return [
                'group' => $group,
                'capability' => $lead->topic_key, 'capability_label' => $lead->topic_label,
                'statement' => $lead->statement,
                'scopes' => (array) $lead->scopes, 'scope_labels' => array_map(fn (string $s) => $labels[$s] ?? $s, (array) $lead->scopes),
                'source_quote' => $lead->source_quote, 'confidence' => $lead->confidence, 'rationale' => $lead->rationale,
                'verification' => $lead->verification->value,
                'pending' => $members->contains(fn (EvidenceProposal $p) => $p->status === ProposalStatus::Proposed),
                'members' => $members->map(fn (EvidenceProposal $p) => [
                    'id' => $p->id, 'status' => $p->status->value, 'target' => "{$p->attributable_type}:{$p->attributable_id}",
                    'attribution' => $byKey->get("{$p->attributable_type}:{$p->attributable_id}")['label'] ?? null,
                    'visibility' => $p->visibility->value, 'fact_key' => $p->careerFact?->key, 'revision' => $p->revision,
                    'existing' => $p->status === ProposalStatus::Proposed ? $this->duplicates->relation($p) : null,
                ])->values()->all(),
            ];
        })->values()->all();

        $result = (array) $expansion->result;
        $session = $answer->question->session;
        $followups = [];

        foreach ((array) ($result['followups'] ?? []) as $i => $f) {
            $asked = $session->questions()->where('context->followup_of_answer', $answer->id)->where('question_text', $f['question'])->first();
            $followups[] = ['index' => $i, 'capability' => $f['capability'], 'question' => $f['question'], 'asked_question_id' => $asked?->id, 'asked_status' => $asked?->status];
        }

        return [
            ...$base,
            'state' => $running ? 'running' : $expansion->status,
            'expansion_id' => $expansion->id, 'failure' => $expansion->failure, 'model' => $expansion->model, 'latency_ms' => $expansion->latency_ms,
            'usage' => $expansion->usage, 'at' => $expansion->created_at?->toIso8601String(),
            'stale' => $expansion->evidence_revision !== $answer->question->session->ownerCareerProfile()->evidence_revision,
            'claims' => $claims, 'dropped' => (array) ($result['rejected'] ?? []), 'followups' => $followups,
        ];
    }
}
