<?php

namespace App\Support\Clarification;

use App\Enums\ProposalStatus;
use App\Enums\ResponsibilityScope;
use App\Models\CareerLimitation;
use App\Models\ClarificationQuestion;
use App\Models\ClarificationSession;
use App\Models\EvidenceProposal;
use App\Models\PresentationPreference;
use App\Models\Project;
use App\Models\Role;

/**
 * The persisted clarification state for the job page: ranked questions
 * with why each was asked, the proposal each answer produced, and the
 * limitations and preferences already on record. Derived entirely from
 * the database, so a reload or navigation shows exactly the same thing.
 */
final class PresentClarification
{
    /**
     * @return array<string, mixed>
     */
    public function present(ClarificationSession $session): array
    {
        $profile = $session->jobPosting->careerProfile;
        $options = $this->attributionOptions($profile->id);

        return [
            'session_id' => $session->id,
            'mode' => $session->mode,
            'can_deepen' => $session->mode === 'standard',
            'questions' => $session->questions()->get()->map(fn (ClarificationQuestion $q) => $this->question($q, $options))->all(),
            'attribution_options' => $options,
            'scopes' => collect(ResponsibilityScope::cases())
                ->reject(fn (ResponsibilityScope $s) => $s === ResponsibilityScope::NoExperience)
                ->map(fn (ResponsibilityScope $s) => ['value' => $s->value, 'label' => $s->label()])
                ->values()->all(),
            'limitations' => CareerLimitation::query()->where('career_profile_id', $profile->id)->active()->orderBy('topic')->get()
                ->map(fn (CareerLimitation $l) => [
                    'id' => $l->id,
                    'topic' => $l->topic,
                    'status' => $l->status,
                    'confirmed_at' => $l->confirmed_at->toDateString(),
                ])->all(),
            'preferences' => PresentationPreference::query()->where('career_profile_id', $profile->id)->active()->orderBy('topic')->get()
                ->map(fn (PresentationPreference $p) => ['id' => $p->id, 'topic' => $p->topic, 'guidance' => $p->guidance])->all(),
        ];
    }

    /**
     * @param  array<int, array{type: string, id: int, label: string}>  $options
     * @return array<string, mixed>
     */
    public function question(ClarificationQuestion $question, array $options): array
    {
        $proposal = EvidenceProposal::query()
            ->whereIn('clarification_answer_id', $question->answers()->pluck('id'))
            ->where('status', '!=', ProposalStatus::Superseded)
            ->latest('id')
            ->first();

        return [
            'id' => $question->id,
            'topic_key' => $question->topic_key,
            'topic_label' => $question->topic_label,
            'text' => $question->question_text,
            'status' => $question->status,
            'score' => round($question->score, 2),
            'why' => [
                'coverage' => $question->context['coverage'] ?? null,
                'requirement' => $question->context['requirement'] ?? null,
                'strength' => $question->context['strength'] ?? null,
                'emphasis' => $question->context['emphasis'] ?? null,
                'related_evidence' => $question->context['related_evidence'] ?? [],
                // Set for Resume Profile interview questions: what the sampled postings ask.
                'market' => $question->context['market'] ?? null,
            ],
            'suggested' => [
                'attributable_type' => $question->suggested_attributable_type,
                'attributable_id' => $question->suggested_attributable_id,
                'visibility' => $question->suggested_visibility,
            ],
            'proposal' => $proposal === null ? null : $this->proposal($proposal, $options),
        ];
    }

    /**
     * @param  array<int, array{type: string, id: int, label: string}>  $options
     * @return array<string, mixed>
     */
    private function proposal(EvidenceProposal $proposal, array $options): array
    {
        $label = collect($options)->first(fn (array $o) => $o['type'] === $proposal->attributable_type && $o['id'] === $proposal->attributable_id)['label'] ?? null;

        return [
            'id' => $proposal->id,
            'kind' => $proposal->kind->value,
            'status' => $proposal->status->value,
            'statement' => $proposal->statement,
            'scopes' => $proposal->scopes,
            'attributable_type' => $proposal->attributable_type,
            'attributable_id' => $proposal->attributable_id,
            'attribution_label' => $label,
            'visibility' => $proposal->visibility->value,
            'verification' => $proposal->verification->value,
            'answer_text' => $proposal->answer?->answer_text,
            'career_fact_key' => $proposal->careerFact?->key,
            'history' => $proposal->history,
        ];
    }

    /**
     * @return array<int, array{type: string, id: int, label: string}>
     */
    public function attributionOptions(int $profileId): array
    {
        $options = [];

        foreach (Role::query()->whereHas('employer', fn ($q) => $q->where('career_profile_id', $profileId))->with('employer')->orderByDesc('start_year')->get() as $role) {
            $options[] = ['type' => 'role', 'id' => $role->id, 'label' => "{$role->employer->name}: {$role->title}"];
        }

        foreach (Project::query()->where('career_profile_id', $profileId)->with('role.employer')->orderBy('name')->get() as $project) {
            $owner = $project->role === null ? 'Independent project' : $project->role->employer->name;
            $options[] = ['type' => 'project', 'id' => $project->id, 'label' => "{$owner}: {$project->name} (project)"];
        }

        return $options;
    }
}
