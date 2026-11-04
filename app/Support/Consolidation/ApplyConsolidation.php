<?php

namespace App\Support\Consolidation;

use App\Enums\ProposalKind;
use App\Enums\ProposalStatus;
use App\Enums\SkillCategory;
use App\Enums\Verification;
use App\Enums\Visibility;
use App\Models\CareerFact;
use App\Models\CareerProfile;
use App\Models\EvidenceConsolidation;
use App\Models\EvidenceProposal;
use App\Models\Skill;
use App\Support\Clarification\EvidenceRevision;
use App\Support\Clarification\ReviewEvidenceProposal;
use App\Support\Clarification\ReviseApprovedEvidence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The only place a consolidation item changes canonical evidence, and only when the user approves it.
 * Wording changes reuse the existing revision workflow (the fact keeps its key, a new revision is
 * recorded, the old one is kept as superseded, and the evidence row quotes the original answer);
 * new facts go through the normal proposal approval with every guard it has. Nothing is deleted.
 */
final class ApplyConsolidation
{
    private const SKILL_PATTERNS = [
        'Google Cloud (general)' => '/\b(?:GCP|Google Cloud)\b/i',
        'Microsoft Azure' => '/\bAzure\b/i',
        'API integration & design' => '/\bAPIs?\b/i',
        'REST APIs' => '/\bREST\b/',
        'Docker Compose' => '/\bCompose\b/i',
    ];

    private const SKILL_CATEGORIES = [
        'REST APIs' => SkillCategory::Capability, 'Webhooks' => SkillCategory::Capability, 'Recurring billing' => SkillCategory::Capability,
        'AWS' => SkillCategory::PlatformIntegration, 'Microsoft Azure' => SkillCategory::PlatformIntegration, 'BigQuery' => SkillCategory::PlatformIntegration,
        'Google Cloud (general)' => SkillCategory::PlatformIntegration, 'Jira' => SkillCategory::PlatformIntegration, 'GitLab' => SkillCategory::PlatformIntegration,
        'API integration & design' => SkillCategory::Capability,
    ];

    public function __construct(private readonly ReviewEvidenceProposal $review, private readonly ReviseApprovedEvidence $revisions) {}

    /**
     * Edits an item that has not been decided yet: wording, place and visibility.
     *
     * @param  array<string, mixed>  $fields  statement, attributable_type, attributable_id, visibility
     */
    public function update(EvidenceConsolidation $item, array $fields): EvidenceConsolidation
    {
        $this->assertOpen($item);

        if (! in_array($item->action, ['revise', 'create'], true)) {
            throw ValidationException::withMessages(['item' => 'This item has nothing to edit.']);
        }

        $changed = [];

        if (array_key_exists('statement', $fields)) {
            $statement = trim((string) preg_replace('/\s+/u', ' ', (string) $fields['statement']));

            if ($statement === '') {
                throw ValidationException::withMessages(['statement' => 'The statement cannot be empty.']);
            }

            $item->statement = $statement;
            $changed[] = 'statement';
        }

        if (array_key_exists('attributable_type', $fields)) {
            $probe = new EvidenceProposal(['career_profile_id' => $item->career_profile_id, 'attributable_type' => $fields['attributable_type'], 'attributable_id' => $fields['attributable_id'] ?? null]);
            $this->review->resolveAttribution($probe);
            $item->attributable_type = (string) $fields['attributable_type'];
            $item->attributable_id = (int) $fields['attributable_id'];
            $changed[] = 'attribution';
        }

        if (array_key_exists('visibility', $fields)) {
            $item->visibility = Visibility::from((string) $fields['visibility'])->value;
            $changed[] = 'visibility';
        }

        if ($changed !== []) {
            $item->recordHistory('edited', ['fields' => $changed]);
            $item->save();
        }

        return $item;
    }

    public function reject(EvidenceConsolidation $item): EvidenceConsolidation
    {
        $this->assertOpen($item);
        $item->fill(['status' => 'rejected', 'decided_at' => now()]);
        $item->recordHistory('rejected');
        $item->save();

        return $item;
    }

    /**
     * @param  array<string, mixed>  $fields  same keys as update()
     * @param  array{acknowledge_metric?: bool}  $options
     */
    public function approve(EvidenceConsolidation $item, array $fields = [], array $options = []): EvidenceConsolidation
    {
        $this->assertOpen($item);

        return DB::transaction(function () use ($item, $fields, $options) {
            if ($fields !== []) {
                $item = $this->update($item, $fields);
            }

            $applied = match ($item->action) {
                'revise' => $this->revise($item, $options),
                'create' => $this->create($item, $options),
                'tag' => $this->tag($item),
                'advice' => $this->advice($item),
                default => throw ValidationException::withMessages(['item' => 'Unknown consolidation action.']),
            };

            $item->fill(['status' => 'approved', 'decided_at' => now(), 'applied' => $applied]);
            $item->recordHistory('approved', ['applied' => $applied]);
            $item->save();

            return $item;
        });
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function revise(EvidenceConsolidation $item, array $options): array
    {
        $fact = CareerFact::query()->findOrFail($item->target_fact_id);
        $this->assertPlaceChosen($item);
        $this->assertGroupAgrees($item, $fact);

        $fields = array_filter([
            'statement' => $item->statement, 'scopes' => $item->scopes,
            'attributable_type' => $item->attributable_type, 'attributable_id' => $item->attributable_id,
            'visibility' => $item->visibility, 'verification' => $item->verification,
        ], fn ($v) => $v !== null);
        $revision = null;
        $options = $this->adoptOriginal($item, $fact) ? [...$options, 'acknowledge_metric' => true] : $options;

        try {
            $revision = $this->revisions->revise($fact, $fields, ['shared' => 'none', 'note' => "Evidence consolidation {$item->code}: {$item->title}.", ...array_intersect_key($options, ['acknowledge_metric' => true])]);
        } catch (ValidationException $e) {
            // Joining a group or tagging skills without a wording change is allowed; any other refusal is not.
            if (! str_contains(implode(' ', array_merge(...array_values($e->errors()))), 'Nothing changed') || ($item->claim_group === null && ($item->skills ?? []) === [])) {
                throw $e;
            }
        }

        $fact->refresh();

        if ($item->claim_group !== null && $fact->claim_group !== $item->claim_group) {
            $fact->update(['claim_group' => $item->claim_group]);
        }

        $this->attachSkills($fact, $item->skills ?? []);
        EvidenceRevision::bump(CareerProfile::query()->findOrFail($fact->career_profile_id));

        return ['fact_id' => $fact->id, 'fact_key' => $fact->key, 'proposal_id' => $revision?->id, 'revision' => $revision?->revision];
    }

    /**
     * A fact that came from the resume and portfolio has no revision chain, so it cannot be revised. Its first
     * revision is recorded here: an approved proposal that says exactly what the fact says now, tied to the user's
     * confirmation. The fact keeps its key, metric, visibility, verification and original evidence rows; its original
     * wording is kept as the superseded revision. The figure was verified by those sources, so it is not treated as a
     * newly self-reported one. Returns whether this item adopted the fact.
     */
    private function adoptOriginal(EvidenceConsolidation $item, CareerFact $fact): bool
    {
        $has = EvidenceProposal::query()->where('career_fact_id', $fact->id)->where('kind', ProposalKind::Fact)->where('status', ProposalStatus::Approved)->exists();

        if ($has) {
            return false;
        }

        if ($item->source_answer_id === null) {
            throw ValidationException::withMessages(['item' => "[{$fact->key}] has no recorded confirmation to revise it from."]);
        }

        $sources = $fact->evidence()->pluck('source')->map(fn ($s) => $s->value ?? (string) $s)->unique()->values()->all();

        EvidenceProposal::create([
            'career_profile_id' => $fact->career_profile_id, 'clarification_answer_id' => $item->source_answer_id, 'kind' => ProposalKind::Fact, 'status' => ProposalStatus::Approved,
            'topic_key' => (string) $item->topic_key, 'topic_label' => (string) ($item->notes['topic_label'] ?? $item->topic_key),
            'statement' => $fact->statement, 'scopes' => $item->scopes ?? [], 'attributable_type' => $fact->attributable_type, 'attributable_id' => $fact->attributable_id,
            'visibility' => $fact->visibility, 'verification' => $fact->verification, 'career_fact_id' => $fact->id, 'revision' => 1, 'decided_at' => now(),
            'history' => [['at' => now()->toIso8601String(), 'action' => 'adopted_original', 'consolidation' => $item->code, 'original_sources' => $sources, 'original_statement' => $fact->statement]],
        ]);

        return true;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function create(EvidenceConsolidation $item, array $options): array
    {
        $this->assertPlaceChosen($item);
        $this->assertGroupAgrees($item, null);

        $proposal = EvidenceProposal::create([
            'career_profile_id' => $item->career_profile_id, 'clarification_answer_id' => $item->source_answer_id, 'kind' => ProposalKind::Fact, 'status' => ProposalStatus::Proposed,
            'topic_key' => (string) $item->topic_key, 'topic_label' => (string) ($item->notes['topic_label'] ?? $item->topic_key),
            'statement' => (string) $item->statement, 'scopes' => $item->scopes ?? [],
            'attributable_type' => $item->attributable_type, 'attributable_id' => $item->attributable_id,
            'visibility' => Visibility::from((string) $item->visibility), 'verification' => Verification::from((string) $item->verification),
            'claim_group' => $item->claim_group, 'rationale' => "Prepared by evidence consolidation {$item->code}: {$item->title}.",
            'history' => [['at' => now()->toIso8601String(), 'action' => 'proposed', 'consolidation' => $item->code]],
        ]);

        $approved = $this->review->approve($proposal, ['visibility' => (string) $item->visibility], array_intersect_key($options, ['acknowledge_metric' => true]));
        $fact = CareerFact::query()->findOrFail($approved->career_fact_id);
        $this->attachSkills($fact, $item->skills ?? []);

        return ['fact_id' => $fact->id, 'fact_key' => $fact->key, 'proposal_id' => $approved->id, 'revision' => 1];
    }

    /**
     * @return array<string, mixed>
     */
    private function tag(EvidenceConsolidation $item): array
    {
        $facts = CareerFact::query()->whereIn('id', $item->fact_ids ?? [])->get();

        foreach ($facts as $fact) {
            $this->attachSkills($fact, $item->skills ?? []);
        }

        EvidenceRevision::bump(CareerProfile::query()->findOrFail($item->career_profile_id));

        return ['fact_ids' => $facts->pluck('id')->all(), 'skills' => $item->skills];
    }

    /**
     * @return array<string, mixed>
     */
    private function advice(EvidenceConsolidation $item): array
    {
        $proposal = EvidenceProposal::query()->findOrFail($item->target_proposal_id);
        $this->review->reject($proposal);

        return ['rejected_proposal_id' => $proposal->id];
    }

    /**
     * A skill is attached only when the fact's own wording names it. Related experience is never
     * converted into a technology the fact does not state.
     *
     * @param  array<int, string>  $names
     */
    private function attachSkills(CareerFact $fact, array $names): void
    {
        foreach ($names as $name) {
            $pattern = self::SKILL_PATTERNS[$name] ?? '/'.preg_quote($name, '/').'/i';

            if (preg_match($pattern, $fact->statement) !== 1) {
                throw ValidationException::withMessages(['skills' => "[{$fact->key}] does not mention {$name}, so the skill was not attached."]);
            }

            $skill = Skill::query()->where('career_profile_id', $fact->career_profile_id)->where('name', $name)->first() ?? Skill::create([
                'career_profile_id' => $fact->career_profile_id, 'name' => $name, 'slug' => $this->slug($fact->career_profile_id, $name),
                'category' => self::SKILL_CATEGORIES[$name] ?? SkillCategory::BuildTechnology,
            ]);

            $fact->skills()->syncWithoutDetaching([$skill->id]);
        }
    }

    private function slug(int $profileId, string $name): string
    {
        $base = Str::slug($name) ?: 'skill';
        $slug = $base;

        for ($n = 2; Skill::query()->where('career_profile_id', $profileId)->where('slug', $slug)->exists(); $n++) {
            $slug = "{$base}-{$n}";
        }

        return $slug;
    }

    private function assertPlaceChosen(EvidenceConsolidation $item): void
    {
        if ($item->attributable_type === null || $item->attributable_id === null) {
            throw ValidationException::withMessages(['attributable_type' => 'Choose where this belongs before approving. It is never assumed.']);
        }
    }

    /**
     * Places of one shared claim must say the same thing; otherwise approving would leave siblings contradicting.
     */
    private function assertGroupAgrees(EvidenceConsolidation $item, ?CareerFact $fact): void
    {
        if ($item->claim_group === null) {
            return;
        }

        $siblings = CareerFact::query()->where('claim_group', $item->claim_group)->when($fact !== null, fn ($q) => $q->where('id', '!=', $fact->id))->get();

        foreach ($siblings as $sibling) {
            if ($this->review->normalize($sibling->statement) !== $this->review->normalize((string) $item->statement)) {
                throw ValidationException::withMessages(['statement' => "This claim is shared with [{$sibling->key}], which says something different. Make the wording match it, or approve the claim once and leave this place out."]);
            }
        }
    }

    private function assertOpen(EvidenceConsolidation $item): void
    {
        if ($item->status !== 'proposed') {
            throw ValidationException::withMessages(['item' => "This item was already {$item->status}."]);
        }
    }
}
