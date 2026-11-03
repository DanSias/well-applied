<?php

namespace App\Support\Clarification;

use App\Enums\ProposalKind;
use App\Enums\ProposalStatus;
use App\Enums\ResponsibilityScope;
use App\Models\CareerFact;
use App\Models\CareerLimitation;
use App\Models\ClarificationQuestion;
use App\Models\EvidenceProposal;
use App\Models\JobPosting;
use App\Models\PresentationPreference;
use App\Support\Expansion\SharedClaims;
use Illuminate\Support\Collection;

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
            ->map(fn (EvidenceProposal $p) => $this->fact($p, $options, $job->id))->values()->all();

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
     * @param  Collection<int, array{type: string, id: int, label: string}>  $options
     * @return array<string, mixed>
     */
    private function fact(EvidenceProposal $p, Collection $options, ?int $jobId): array
    {
        return [
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
            'from_this_job' => $jobId !== null && $p->job_posting_id === $jobId,
            'revisions' => $this->revisions($p),
            // The other places this same claim is attached to; a revision of its wording reaches them too.
            'shared_with' => $p->careerFact?->claim_group === null ? [] : CareerFact::query()
                ->where('claim_group', $p->careerFact->claim_group)->where('id', '!=', $p->career_fact_id)->get()
                ->map(fn (CareerFact $f) => ['fact_key' => $f->key, 'place' => SharedClaims::place($f)])->all(),
        ];
    }

    /**
     * The evidence a Resume Profile interview contributed, in the same shape as a job's.
     *
     * @param  Collection<int, EvidenceProposal>  $proposals
     * @return array<string, mixed>
     */
    public function presentProposals(int $careerProfileId, Collection $proposals): array
    {
        $options = collect($this->clarification->attributionOptions($careerProfileId));

        return [
            'facts' => $proposals->map(fn (EvidenceProposal $p) => $this->fact($p, $options, null))->values()->all(),
            'limitations' => [],
            'preferences' => [],
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
