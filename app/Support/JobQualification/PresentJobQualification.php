<?php

namespace App\Support\JobQualification;

use App\Enums\GenerationStatus;
use App\Enums\GenerationType;
use App\Models\GenerationAttempt;
use App\Models\JobPosting;
use App\Models\JobQualification;
use App\Support\GenerationAttempt\PresentGenerationAttempt;

/**
 * The job drawer's qualification block. Everything is derived from the stored snapshot and the latest attempt:
 *
 * - `state`: none (never run), queued/running (an attempt is in flight), ready (a result exists), failed (the latest attempt
 *   failed and no result has been written since; any earlier result is still shown, marked out of date or not);
 * - `stale`: the result no longer matches the posting, the candidate's evidence or the policy.
 *
 * It reads and shapes; it never starts anything and never touches a disposition.
 */
final class PresentJobQualification
{
    public function __construct(
        private readonly QualificationFreshness $freshness,
        private readonly PresentGenerationAttempt $attempts,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forJob(JobPosting $job): array
    {
        $latest = $this->freshness->latest($job);
        $inputs = $this->freshness->inputs($job);
        $stale = $latest !== null && ! $this->freshness->isFresh($latest, $inputs['content'], $inputs['profile']);

        $attempt = GenerationAttempt::query()
            ->where('subject_type', $job->getMorphClass())
            ->where('subject_id', $job->id)
            ->where('generation_type', GenerationType::JobQualification)
            ->orderByDesc('id')
            ->first();

        $state = match (true) {
            $attempt?->status === GenerationStatus::Queued => 'queued',
            $attempt?->status === GenerationStatus::Running => 'running',
            $attempt?->status === GenerationStatus::Failed && ($latest === null || $attempt->finished_at === null || $attempt->finished_at->gt($latest->generated_at)) => 'failed',
            $latest !== null => 'ready',
            default => 'none',
        };

        return [
            'state' => $state,
            'stale' => $stale,
            'attempt' => $attempt === null ? null : $this->attempts->present($attempt),
            'current' => $latest === null ? null : $this->present($latest),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function present(JobQualification $q): array
    {
        $a = $q->assessment;

        return [
            'id' => $q->id,
            'verdict' => $q->verdict?->value,
            'verdict_label' => $q->verdict?->label(),
            'eligibility' => $q->eligibility->value,
            'eligibility_label' => $q->eligibility->label(),
            'eligibility_reasons' => array_map(fn (array $r) => [
                'code' => $r['code'], 'severity' => $r['severity'], 'message' => $r['message'], 'evidence' => $r['evidence'] ?? null,
            ], $q->eligibility_reasons),
            'generated_at' => $q->generated_at->toIso8601String(),
            'generated_by' => $q->generated_by,
            'adjusted' => $q->model_verdict !== null && $q->model_verdict !== $q->verdict,
            'model_verdict_label' => $q->model_verdict?->label(),
            'assessment' => $a === null ? null : [
                'role_type' => $a['role_type'],
                'primary_role_focus' => $a['primary_role_focus'],
                'hands_on' => $a['hands_on'],
                'seniority' => $a['seniority'],
                'responsibilities' => $a['core_responsibilities'],
                'why_it_fits' => $a['fit_reasons'],
                'biggest_concern' => $a['biggest_concern'],
                'practical_concerns' => $a['practical_concerns'],
                // The stack concerns worth a person's attention: central, mandatory technologies the candidate has not shown.
                // (Snapshots made before `central` existed have no flag and are all shown.)
                'mandatory_stack_concerns' => array_values(array_filter(
                    $a['technologies'],
                    fn (array $t) => $t['requirement'] === 'mandatory' && $t['familiarity'] !== 'familiar' && ($t['central'] ?? true),
                )),
            ],
        ];
    }
}
