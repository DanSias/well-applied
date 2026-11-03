<?php

namespace App\Support\Consolidation;

use App\Enums\ProposalKind;
use App\Enums\ProposalStatus;
use App\Enums\ResponsibilityScope;
use App\Enums\Visibility;
use App\Models\CareerFact;
use App\Models\ClarificationAnswer;
use App\Models\EvidenceConsolidation;
use App\Models\EvidenceProposal;
use App\Support\Clarification\PresentClarification;
use App\Support\ResumeProfile\CapabilityCoverage;
use Illuminate\Support\Collection;

/**
 * The consolidation review queue as the user sees it: for every item, the active fact as it is now,
 * the proposed change, where the wording comes from, what happens to attribution, skills, claim
 * identity and visibility, and how the matcher would read the new wording.
 */
final class PresentConsolidations
{
    public const WHOLE_CAREER = 'Whole career (not tied to one employer)';

    public function __construct(private readonly PresentClarification $clarification, private readonly CapabilityCoverage $coverage) {}

    /**
     * @return array<string, mixed>
     */
    public function present(int $careerProfileId): array
    {
        $items = EvidenceConsolidation::query()->where('career_profile_id', $careerProfileId)->orderBy('sort_order')->get();

        if ($items->isEmpty()) {
            return ['items' => [], 'counts' => ['proposed' => 0, 'approved' => 0, 'rejected' => 0], 'options' => []];
        }

        $options = collect($this->clarification->attributionOptions($careerProfileId))
            ->push(['type' => 'career_profile', 'id' => $careerProfileId, 'label' => self::WHOLE_CAREER]);
        $labels = $options->mapWithKeys(fn (array $o) => ["{$o['type']}:{$o['id']}" => $o['label']]);
        $facts = CareerFact::query()->where('career_profile_id', $careerProfileId)->with('skills')->get()->keyBy('id');

        return [
            'items' => $items->map(fn (EvidenceConsolidation $item) => $this->item($item, $facts, $labels))->values()->all(),
            'counts' => ['proposed' => $items->where('status', 'proposed')->count(), 'approved' => $items->where('status', 'approved')->count(), 'rejected' => $items->where('status', 'rejected')->count()],
            'options' => $options->values()->all(),
        ];
    }

    /**
     * @param  Collection<int, CareerFact>  $facts
     * @param  Collection<string, string>  $labels
     * @return array<string, mixed>
     */
    private function item(EvidenceConsolidation $item, Collection $facts, Collection $labels): array
    {
        $target = $item->target_fact_id === null ? null : $facts->get($item->target_fact_id);
        $place = fn (?string $type, ?int $id) => $type === null || $id === null ? null : ['type' => $type, 'id' => $id, 'label' => $labels->get("{$type}:{$id}") ?? "{$type} #{$id}"];
        $current = $target === null ? null : $place($target->attributable_type, $target->attributable_id);
        $proposed = $place($item->attributable_type, $item->attributable_id);
        $before = $target === null ? [] : $this->coverage->capabilitiesIn($target->statement);
        $after = $item->statement === null ? [] : $this->coverage->capabilitiesIn($item->statement);

        return [
            'id' => $item->id, 'code' => $item->code, 'theme' => $item->theme, 'action' => $item->action, 'status' => $item->status,
            'title' => $item->title, 'summary' => $item->summary, 'notes' => (object) array_diff_key((array) $item->notes, ['topic_label' => true]),
            'original' => match (true) {
                $target !== null => $this->fact($target, $current, $facts),
                $item->action === 'advice' => $this->proposal($item->target_proposal_id),
                default => null,
            },
            'tagged' => $item->action === 'tag' ? collect($item->fact_ids ?? [])->map(fn (int $id) => $facts->get($id))->filter()->map(fn (CareerFact $f) => [
                'key' => $f->key, 'statement' => $this->visible($f), 'skills' => $f->skills->pluck('name')->all(),
            ])->values()->all() : [],
            'proposed' => $item->action === 'tag' || $item->action === 'advice' ? null : [
                'statement' => $item->statement, 'place' => $proposed,
                'visibility' => $item->visibility, 'verification' => $item->verification,
                'scopes' => array_map(fn (string $s) => ResponsibilityScope::tryFrom($s)?->label() ?? $s, (array) $item->scopes),
            ],
            'source' => $this->source($item),
            'links' => collect($item->links ?? [])->map(fn (int $id) => $facts->get($id))->filter()->map(fn (CareerFact $f) => ['key' => $f->key, 'statement' => $this->visible($f), 'place' => $this->placeLabel($f, $labels)])->values()->all(),
            'attribution_change' => $item->action === 'revise' && $current !== null && ($proposed === null || $proposed['label'] !== $current['label'])
                ? ['from' => $current['label'], 'to' => $proposed['label'] ?? null] : null,
            'skills' => $item->skills ?? [],
            'effect' => $this->effect($item, $target, $facts),
            'visibility_effect' => $this->visibilityEffect($item, $target),
            'matching' => [
                'gains' => $this->names(array_values(array_diff($after, $before))),
                'loses' => $this->names(array_values(array_diff($before, $after))),
                'direct' => $this->names($after),
            ],
            'decision' => $item->decision === null ? null : [
                'required' => (bool) $item->decision['required'], 'question' => $item->decision['question'],
                'options' => array_values(array_filter(array_map(fn (array $c) => $place($c['type'], $c['id']), (array) $item->decision['candidates']))),
            ],
            'applied' => $item->applied,
            'history' => $item->history,
        ];
    }

    /**
     * @param  Collection<int, CareerFact>  $facts
     * @param  array{type: string, id: int, label: string}|null  $current
     * @return array<string, mixed>
     */
    private function fact(CareerFact $fact, ?array $current, Collection $facts): array
    {
        $group = $fact->claim_group === null ? [] : $facts->where('claim_group', $fact->claim_group)->where('id', '!=', $fact->id)->pluck('key')->values()->all();

        return [
            'kind' => 'fact', 'key' => $fact->key, 'statement' => $this->visible($fact), 'place' => $current, 'visibility' => $fact->visibility->value, 'verification' => $fact->verification->value,
            'shared_with' => $group, 'skills' => $fact->skills->pluck('name')->all(),
            'revisions' => EvidenceProposal::query()->where('career_fact_id', $fact->id)->count(), 'evidence_rows' => $fact->evidence()->count(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function proposal(?int $id): ?array
    {
        $p = $id === null ? null : EvidenceProposal::query()->find($id);

        return $p === null ? null : [
            'kind' => 'proposal', 'key' => "proposal #{$p->id}", 'statement' => $p->statement, 'place' => null, 'visibility' => $p->visibility->value, 'verification' => $p->verification->value,
            'status' => $p->status->value, 'confidence' => $p->confidence, 'rationale' => $p->rationale,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function source(EvidenceConsolidation $item): ?array
    {
        $answer = $item->source_answer_id === null ? null : ClarificationAnswer::query()->with('question')->find($item->source_answer_id);

        return $answer === null ? null : [
            'answer_id' => $answer->id, 'topic' => $answer->question->topic_label, 'question' => $answer->question->question_text, 'answer' => $answer->answer_text,
            'revisions_kept' => $item->target_fact_id === null ? 0 : EvidenceProposal::query()->where('career_fact_id', $item->target_fact_id)->where('status', ProposalStatus::Approved)->where('kind', ProposalKind::Fact)->count(),
        ];
    }

    /**
     * @param  Collection<int, CareerFact>  $facts
     * @return array{kind: string, text: string}
     */
    private function effect(EvidenceConsolidation $item, ?CareerFact $target, Collection $facts): array
    {
        $groupKeys = $item->claim_group === null ? [] : $facts->where('claim_group', $item->claim_group)->pluck('key')->all();
        $join = $groupKeys === [] ? '' : ' It joins the shared claim with '.implode(', ', $groupKeys).', so it counts once.';

        return match ($item->action) {
            'revise' => ['kind' => $item->claim_group !== null ? 'groups' : 'replaces', 'text' => "Replaces the wording of [{$target?->key}] as a new revision. The fact keeps its key, history and evidence; the previous wording is kept as superseded.{$join}"],
            'create' => ['kind' => $item->claim_group !== null ? 'groups' : 'creates', 'text' => 'Creates one new fact from your original answer, with the answer quoted as its evidence.'.$join.($groupKeys === [] && $item->claim_group !== null ? ' It starts a shared claim for its sibling places.' : '')],
            'tag' => ['kind' => 'tags', 'text' => 'Attaches skill tags only. No wording changes.'],
            default => ['kind' => 'rejects', 'text' => 'Rejects that undecided proposal. No career fact changes.'],
        };
    }

    private function visibilityEffect(EvidenceConsolidation $item, ?CareerFact $target): string
    {
        if (in_array($item->action, ['tag', 'advice'], true)) {
            return 'No change.';
        }

        $to = (string) $item->visibility;
        $from = $target?->visibility->value;

        return match (true) {
            $target === null => "New fact, {$to}. You can change it before approving.",
            $from === $to => "Stays {$to}.",
            default => "{$from} to {$to}.",
        };
    }

    private function visible(CareerFact $fact): ?string
    {
        // Private evidence is never reproduced in the review queue.
        return $fact->visibility === Visibility::Private ? null : $fact->statement;
    }

    /**
     * @param  Collection<string, string>  $labels
     */
    private function placeLabel(CareerFact $fact, Collection $labels): ?string
    {
        return $labels->get("{$fact->attributable_type}:{$fact->attributable_id}");
    }

    /**
     * @param  array<int, string>  $keys
     * @return array<int, string>
     */
    private function names(array $keys): array
    {
        return array_map(fn (string $key) => (string) config("resume_profiles.capabilities.{$key}.label", $key), $keys);
    }
}
