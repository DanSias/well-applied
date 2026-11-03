<?php

namespace App\Support\Clarification;

use App\Enums\ProposalKind;
use App\Enums\ProposalStatus;
use App\Enums\ResponsibilityScope;
use App\Models\CareerLimitation;
use App\Models\ClarificationQuestion;
use App\Models\EvidenceProposal;
use App\Models\JobPosting;
use App\Models\PresentationPreference;

/**
 * The user-confirmed evidence associated with one job, for review and
 * revision: facts that came from this job's clarification (or share a topic
 * with one of its questions), with their original answer and every
 * revision, plus the limitations and presentation guidance on those topics.
 */
final class PresentApprovedEvidence
{
    public function __construct(private readonly PresentClarification $clarification) {}

    /**
     * @return array<string, mixed>
     */
    public function present(JobPosting $job): array
    {
        $profileId = (int) $job->career_profile_id;
        $options = collect($this->clarification->attributionOptions($profileId));

        $topics = ClarificationQuestion::query()
            ->whereHas('session', fn ($q) => $q->where('job_posting_id', $job->id))
            ->pluck('topic_key')
            ->unique()
            ->all();

        $related = fn ($query) => $query->where(fn ($q) => $q->where('job_posting_id', $job->id)->orWhereIn('topic_key', $topics));

        $facts = EvidenceProposal::query()
            ->where('career_profile_id', $profileId)
            ->where('kind', ProposalKind::Fact)
            ->where('status', ProposalStatus::Approved)
            ->whereNotNull('career_fact_id')
            ->tap($related)
            ->with(['careerFact', 'answer'])
            ->orderBy('topic_label')
            ->get()
            ->map(fn (EvidenceProposal $p) => [
                'fact_id' => $p->career_fact_id,
                'fact_key' => $p->careerFact?->key,
                'topic' => $p->topic_label,
                'statement' => $p->statement,
                'scopes' => $p->scopes,
                'attributable_type' => $p->attributable_type,
                'attributable_id' => $p->attributable_id,
                'attribution_label' => $options->first(fn (array $o) => $o['type'] === $p->attributable_type && $o['id'] === $p->attributable_id)['label'] ?? null,
                'visibility' => $p->visibility->value,
                'verification' => $p->verification->value,
                'revision' => $p->revision,
                'original_answer' => $p->answer?->answer_text,
                'from_this_job' => $p->job_posting_id === $job->id,
                'revisions' => $this->revisions($p),
            ])->values()->all();

        $limitationTopics = collect($topics);

        return [
            'facts' => $facts,
            'limitations' => CareerLimitation::query()->where('career_profile_id', $profileId)->active()
                ->where(fn ($q) => $q->where('source_job_posting_id', $job->id)->orWhereIn('topic_key', $limitationTopics))
                ->orderBy('topic')->get()
                ->map(fn (CareerLimitation $l) => ['id' => $l->id, 'topic' => $l->topic, 'status' => $l->status, 'confirmed_at' => $l->confirmed_at->toDateString()])->values()->all(),
            'preferences' => PresentationPreference::query()->where('career_profile_id', $profileId)->active()
                ->where(fn ($q) => $q->where('source_job_posting_id', $job->id)->orWhereIn('topic_key', $limitationTopics))
                ->orderBy('topic')->get()
                ->map(fn (PresentationPreference $p) => ['id' => $p->id, 'topic' => $p->topic, 'guidance' => $p->guidance])->values()->all(),
            'attribution_options' => $options->values()->all(),
            'scopes' => collect(ResponsibilityScope::cases())
                ->reject(fn (ResponsibilityScope $s) => $s === ResponsibilityScope::NoExperience)
                ->map(fn (ResponsibilityScope $s) => ['value' => $s->value, 'label' => $s->label()])
                ->values()->all(),
        ];
    }

    /**
     * What each earlier revision said, oldest first, ending with the current one.
     *
     * @return array<int, array<string, mixed>>
     */
    private function revisions(EvidenceProposal $current): array
    {
        $chain = [];
        $node = $current;

        while ($node !== null && count($chain) < 50) {
            $created = collect($node->history ?? [])->first();
            $chain[] = [
                'revision' => $node->revision,
                'statement' => $node->statement,
                'visibility' => $node->visibility->value,
                'verification' => $node->verification->value,
                'at' => $created['at'] ?? $node->created_at?->toIso8601String(),
                'changed' => collect($node->history ?? [])->firstWhere('action', 'revised')['fields'] ?? [],
                'current' => $node->is($current),
            ];
            $node = $node->revises;
        }

        return array_reverse($chain);
    }
}
