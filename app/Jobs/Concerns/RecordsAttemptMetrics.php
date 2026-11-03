<?php

namespace App\Jobs\Concerns;

use App\Models\GenerationAttempt;
use App\Models\JobPosting;
use App\Support\GenerationAttempt\GenerationMetrics;
use App\Support\TailoredResume\TailoredResumePipeline;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Shared by the generation jobs: persists provider-neutral timing and
 * usage on a succeeded attempt, and advances the one-click pipeline
 * when this attempt belongs to one (`chain`). Manual stage actions
 * never chain, so debugging and advanced use are unchanged.
 *
 * @property GenerationAttempt $attempt
 */
trait RecordsAttemptMetrics
{
    private int|float $jobStartedAt = 0;

    private function beginMetrics(): void
    {
        $this->jobStartedAt = hrtime(true);
        app(GenerationMetrics::class)->reset();
    }

    /**
     * @return array<string, mixed>
     */
    private function successMetrics(): array
    {
        $metrics = app(GenerationMetrics::class);
        $queueWaitMs = $this->attempt->queued_at !== null && $this->attempt->started_at !== null
            ? max(0, (int) ($this->attempt->started_at->getTimestamp() - $this->attempt->queued_at->getTimestamp())) * 1000
            : null;

        return [
            'phase' => null,
            'queue_wait_ms' => $queueWaitMs,
            'duration_ms' => (int) round((hrtime(true) - $this->jobStartedAt) / 1_000_000),
            'selection_ms' => $metrics->stageMs('selection'),
            'wording_ms' => $metrics->stageMs('wording'),
            'wording_attempts' => $metrics->stageCalls('wording') ?: null,
            'retry_log' => $metrics->events(),
            'selection_reused' => $metrics->selectionReused(),
            ...$metrics->tokenColumns(),
        ];
    }

    /**
     * What is known about a failed run: elapsed time, per-stage time,
     * the reliability events and cumulative token usage. Failure
     * diagnostics from the provider (model, finish reason) are merged
     * over this by the caller.
     *
     * @return array<string, mixed>
     */
    private function failureMetrics(): array
    {
        // failed() can run without handle() ever starting the clock.
        if ($this->jobStartedAt === 0) {
            return [];
        }

        // The last live sub-stage (`phase`) is kept so a failure says where it stopped.
        return array_diff_key($this->successMetrics(), ['queue_wait_ms' => true, 'phase' => true]);
    }

    /**
     * Never lets a pipeline-advance problem undo a stage that already
     * succeeded: a failure to dispatch the next stage is logged and the
     * status endpoint (which re-derives state) lets the user continue.
     */
    private function advancePipeline(JobPosting $posting): void
    {
        if (! $this->attempt->chain || $this->attempt->pipeline_run_id === null) {
            return;
        }

        try {
            app(TailoredResumePipeline::class)->advance($posting, $this->attempt->pipeline_run_id);
        } catch (Throwable $e) {
            Log::error('Tailored resume pipeline could not advance.', [
                'generation_attempt_id' => $this->attempt->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
