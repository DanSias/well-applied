<?php

namespace App\Support\TailoredResume;

use App\Enums\DescriptionCompleteness;
use App\Enums\GenerationStatus;
use App\Enums\GenerationType;
use App\Models\GenerationAttempt;
use App\Models\JobPosting;
use App\Models\ResumeProfileSynthesis;
use App\Models\ResumeSelectionCheckpoint;
use App\Models\ResumeVariant;
use App\Support\Clarification\ClarificationGate;
use App\Support\Clarification\PresentClarification;
use App\Support\ResumeRevision\JobResumes;
use Illuminate\Support\Collection;

/**
 * The single user-facing status of "Generate Tailored Resume", derived
 * on every call from the persisted attempts of the posting's latest
 * run plus what is currently valid (TailoredResumePipeline::inspect).
 *
 * Only stage-level truth is reported: which stage is current, which are
 * done, how long the run has taken. There is deliberately no percentage,
 * because stage durations are not known in advance.
 */
final class PresentTailoredResume
{
    private const STAGES = [
        'analysis' => ['type' => GenerationType::JobAnalysis, 'label' => 'Analyzing requirements'],
        'match' => ['type' => GenerationType::JobMatch, 'label' => 'Matching career evidence'],
        'resume' => ['type' => GenerationType::ResumeVariant, 'label' => 'Composing resume'],
        'pdf' => ['type' => GenerationType::ResumePdf, 'label' => 'Rendering and validating PDF'],
    ];

    public function __construct(
        private readonly TailoredResumePipeline $pipeline,
        private readonly ClarificationGate $gate,
        private readonly PresentClarification $clarification,
        private readonly JobResumes $jobResumes,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function present(JobPosting $posting): array
    {
        $latest = $this->pipeline->attemptsFor($posting)->whereNotNull('pipeline_run_id')->latest('id')->first();
        $needsConfirmation = $posting->description_completeness !== DescriptionCompleteness::Complete;

        if ($latest === null) {
            // Every earlier stage was reused from manual runs, so no attempt
            // carries a run id yet; the held run is still the thing to show.
            $held = $this->gate->pendingFor($posting);

            if ($held !== null) {
                $stages = array_map(fn (string $key) => [
                    'key' => $key,
                    'label' => self::STAGES[$key]['label'],
                    'status' => in_array($key, ['analysis', 'match'], true) ? 'reused' : 'pending',
                    'phase' => null,
                    'duration_ms' => null,
                ], array_keys(self::STAGES));

                $heldValid = $this->pipeline->inspect($posting);

                return $this->payload('needs_clarification', 'A few questions could improve this resume', $stages, $held->pipeline_run_id, $needsConfirmation, [
                    'clarification' => [
                        ...$this->clarification->present($held),
                        'resume_ready' => $heldValid['variant'] !== null && $heldValid['pdf_ready'],
                    ],
                ]);
            }

            return $this->payload('idle', 'Not started', [], null, $needsConfirmation);
        }

        $runId = $latest->pipeline_run_id;
        $regenerate = $this->pipeline->attemptsFor($posting)->where('pipeline_run_id', $runId)->where('regenerate', true)->exists();
        $attempts = $this->pipeline->attemptsFor($posting)->where('pipeline_run_id', $runId)->orderBy('id')->get();
        $synthesisId = $this->pipeline->runSynthesisId($posting, $runId);
        $synthesis = $synthesisId === null ? null : ResumeProfileSynthesis::query()->find($synthesisId);
        $valid = $synthesis === null
            ? $this->pipeline->inspect($posting, $regenerate ? $runId : null)
            : $this->pipeline->inspectProfile($posting, $synthesis, $regenerate ? $runId : null);
        // A profile-assisted run has no candidate-match stage: the approved profile and the stored job analysis replace it.
        $definitions = $synthesis === null ? self::STAGES : array_diff_key(self::STAGES, ['match' => true]);

        $stages = [];
        $current = null;
        $failure = null;

        foreach ($definitions as $key => $definition) {
            $attempt = $attempts->where('generation_type', $definition['type'])->last();
            $satisfied = match ($key) {
                'analysis' => $valid['analysis'] !== null,
                'match' => $valid['match'] !== null,
                'resume' => $valid['variant'] !== null,
                'pdf' => $valid['pdf_ready'],
            };

            $status = match (true) {
                $attempt?->status === GenerationStatus::Running => 'running',
                $attempt?->status === GenerationStatus::Queued => 'queued',
                $satisfied => $attempt?->status === GenerationStatus::Succeeded ? 'done' : 'reused',
                $attempt?->status === GenerationStatus::Failed => 'failed',
                default => 'pending',
            };

            if ($status === 'failed' && $failure === null) {
                $failure = [
                    'stage' => $key,
                    'stage_label' => $definition['label'],
                    'category' => $attempt->failure_category,
                    'message' => $attempt->failure_message,
                    // Where inside the stage it stopped (selection or
                    // wording), and whether a validated Selection is
                    // saved so a retry will not repeat it.
                    'phase' => $attempt->phase,
                    'provider_calls' => $attempt->provider_calls,
                    'selection_saved' => $key === 'resume' && $this->selectionCheckpointSaved($attempt),
                ];
            }

            if ($current === null && in_array($status, ['running', 'queued'], true)) {
                $current = $key;
            }

            $stages[] = [
                'key' => $key,
                'label' => $definition['label'],
                'status' => $status,
                'phase' => in_array($attempt?->status, [GenerationStatus::Running, GenerationStatus::Failed], true) ? $attempt->phase : null,
                'duration_ms' => $attempt?->duration_ms,
            ];
        }

        $allDone = ! in_array(false, [$valid['analysis'] !== null, $synthesis !== null || $valid['match'] !== null, $valid['variant'] !== null, $valid['pdf_ready']], true);

        // Held for optional questions: nothing is running, the run waits.
        // This also covers questions the user asked for on a finished resume.
        $pending = $current === null && $failure === null ? $this->gate->pendingFor($posting) : null;

        // The evidence changed since the last finished run, so its match is
        // no longer current. Offer a fresh run instead of an endless spinner.
        $stale = $pending === null && $current === null && $failure === null && ! $allDone && $valid['evidence_stale'];

        [$state, $label] = match (true) {
            $pending !== null => ['needs_clarification', 'A few questions could improve this resume'],
            $stale => ['idle', 'Career evidence changed since the last run. Generate again to include it.'],
            $failure !== null && $current === null => ['failed', 'Failed'],
            $allDone => ['ready', 'Ready for review'],
            $current !== null => ['running', self::STAGES[$current]['label']],
            default => ['running', 'Preparing job'],
        };

        $first = $attempts->min('queued_at');
        $end = in_array($state, ['ready', 'failed'], true) ? $attempts->max('finished_at') : now();
        $elapsed = $first === null ? null : max(0, (int) $first->diffInSeconds($end ?? now()));

        $variant = $allDone ? $valid['variant'] : null;

        // The resume being worked on is the newest generated or revised one for this job, not only the last generation result.
        if ($variant !== null && $synthesis !== null) {
            $variant = $this->jobResumes->current($posting) ?? $variant;
        }

        return $this->payload($state, $label, $stages, $runId, $needsConfirmation, [
            'current_stage' => $current,
            // While waiting for the user there is no run in progress to time.
            'elapsed_seconds' => $pending === null ? $elapsed : null,
            'failure' => $state === 'failed' ? $failure : null,
            'resume_variant_id' => $variant?->id,
            'preview_url' => $variant === null ? null : route('resume-variants.preview', $variant),
            'download_url' => $variant === null ? null : route('resume-variants.pdf', $variant),
            'layout' => $state === 'ready' ? ($variant?->edit_log['layout'] ?? $this->layout($attempts)) : null,
            'review' => $variant === null ? null : $this->review($variant),
            'profile_resume' => $synthesis === null ? null : $this->profileResume($posting, $synthesis, $variant),
            'waiting_for_worker' => $current !== null && collect($stages)->firstWhere('key', $current)['status'] === 'queued',
            'clarification' => $pending === null ? null : [...$this->clarification->present($pending), 'resume_ready' => $allDone],
            'evidence_changed' => $stale,
        ]);
    }

    /**
     * The approved profiles this job can be tailored from, the most relevant first (a profile whose name shares words
     * with the job title, then the newest). A stale profile is listed, flagged, and cannot be selected.
     *
     * @return array<int, array<string, mixed>>
     */
    public function profiles(JobPosting $posting): array
    {
        $words = fn (string $t) => array_filter(preg_split('/[^a-z0-9]+/', strtolower($t)) ?: [], fn (string $w) => strlen($w) >= 4);
        $titleWords = $words($posting->title);

        $rows = ResumeProfileSynthesis::query()->where('career_profile_id', $posting->career_profile_id)->where('status', 'approved')->with('resumeProfile')->get()
            ->map(fn (ResumeProfileSynthesis $s) => [
                'id' => $s->id, 'version' => $s->version, 'name' => (string) $s->resumeProfile->name, 'stale' => $s->isStale(),
                'approved_at' => $s->approved_at?->toIso8601String(),
                'overlap' => count(array_intersect($titleWords, $words((string) $s->resumeProfile->name))),
            ])->all();

        usort($rows, fn (array $a, array $b) => [$a['stale'], -$a['overlap'], $b['approved_at']] <=> [$b['stale'], -$b['overlap'], $a['approved_at']]);

        return array_map(fn (array $r, int $i) => [...array_diff_key($r, ['overlap' => true]), 'recommended' => $i === 0 && ! $r['stale']], $rows, array_keys($rows));
    }

    /**
     * What a person should look at before sending the resume: the
     * counts, plus every claim that was generalized, omitted or flagged,
     * with the supporting evidence. Passed claims are counted only.
     *
     * @return array<string, mixed>|null
     */
    private function review(ResumeVariant $variant): ?array
    {
        $report = $variant->review_report;

        if (! is_array($report)) {
            return null;
        }

        return [
            'counts' => $report['counts'],
            'items' => array_values(array_filter($report['items'], fn (array $item) => $item['status'] !== 'passed')),
        ];
    }

    private function selectionCheckpointSaved(GenerationAttempt $attempt): bool
    {
        return ResumeSelectionCheckpoint::query()
            ->where('job_match_id', $attempt->subject_id)
            ->whereNull('consumed_at')
            ->exists();
    }

    /**
     * @param  array<int, array<string, mixed>>  $stages
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function payload(string $state, string $label, array $stages, ?string $runId, bool $needsConfirmation, array $extra = []): array
    {
        return [
            'state' => $state,
            'label' => $label,
            'run_id' => $runId,
            'stages' => $stages,
            'needs_confirmation' => $needsConfirmation,
            'current_stage' => null,
            'elapsed_seconds' => null,
            'failure' => null,
            'resume_variant_id' => null,
            'preview_url' => null,
            'download_url' => null,
            'layout' => null,
            'review' => null,
            'waiting_for_worker' => false,
            'clarification' => null,
            'evidence_changed' => false,
            'profile_resume' => null,
            ...$extra,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function profileResume(JobPosting $posting, ResumeProfileSynthesis $synthesis, ?ResumeVariant $variant): array
    {
        $history = $variant === null ? [] : $this->jobResumes->history($posting);
        $self = $variant === null ? null : collect($history)->firstWhere('id', $variant->id);
        $preferred = $variant === null ? null : $this->jobResumes->preferred($posting);

        return [
            'profile_version' => $synthesis->version,
            'profile_id' => $synthesis->id,
            'profile_name' => (string) $synthesis->resumeProfile->name,
            'review_url' => $variant === null ? null : route('jobs.profile-resumes.show', [$posting, $variant]),
            'kind' => $self['kind'] ?? null,
            'label' => $self['label'] ?? null,
            'summary' => $self['summary'] ?? null,
            'generated_at' => $variant?->generated_at->toIso8601String(),
            'revisions' => count($history),
            // A resume marked final stays so even when the working copy moves on.
            'preferred' => $preferred === null ? null : ['id' => $preferred->id, 'is_current' => $preferred->id === $variant->id, 'url' => route('jobs.profile-resumes.show', [$posting, $preferred])],
        ];
    }

    /**
     * @param  Collection<int, GenerationAttempt>  $attempts
     * @return array<string, mixed>|null
     */
    private function layout($attempts): ?array
    {
        $pdf = $attempts->where('generation_type', GenerationType::ResumePdf)->last();

        return $pdf === null ? null : [
            'pages' => $pdf->pdf_page_count,
            'mode' => $pdf->layout_mode,
            'last_page_fill' => $pdf->layout_diagnostics['last_page_fill'] ?? null,
        ];
    }
}
