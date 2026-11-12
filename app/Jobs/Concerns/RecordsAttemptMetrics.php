<?php

namespace App\Jobs\Concerns;

use App\Enums\GenerationStatus;
use App\Models\GenerationAttempt;
use App\Models\JobPosting;
use App\Support\GenerationAttempt\GenerationMetrics;
use App\Support\TailoredResume\TailoredResumePipeline;
use Illuminate\Database\QueryException;
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

    /**
     * Takes the attempt for this worker, exactly once. Only a still-queued attempt can be claimed, and the claim is one
     * atomic UPDATE, so if two workers pop the same job (SQLite has no row locks, and two queue listeners can race for a
     * row) the second finds the attempt already Running or finished and does nothing. Without it both ran the whole job.
     */
    private function claimAttempt(): bool
    {
        $claimed = GenerationAttempt::query()
            ->whereKey($this->attempt->getKey())
            ->where('status', GenerationStatus::Queued)
            ->update(['status' => GenerationStatus::Running, 'started_at' => now(), 'updated_at' => now()]);

        if ($claimed === 0) {
            Log::warning('A generation attempt was already claimed or finished; this worker skipped it.', [
                'generation_attempt_id' => $this->attempt->id,
                'status' => GenerationAttempt::query()->whereKey($this->attempt->getKey())->value('status')?->value,
            ]);

            return false;
        }

        $this->attempt->refresh();

        return true;
    }

    /**
     * Whether the framework's failure callback should leave the attempt alone. The callback also fires when the QUEUE could
     * not reserve a job (a transient `database is locked`), which says nothing about the work: if another worker is running
     * the attempt, or it already finished, marking it failed would be false, and would let the pipeline start a second
     * generation of the same job. A real failure of this worker's own run is still recorded.
     */
    private function shouldIgnoreFailure(Throwable $exception): bool
    {
        $status = GenerationAttempt::query()->whereKey($this->attempt->getKey())->value('status');

        if ($status === GenerationStatus::Succeeded) {
            return true;
        }

        $busy = $exception instanceof QueryException && preg_match('/database (?:table )?is locked|SQLITE_BUSY/i', $exception->getMessage()) === 1;

        if ($busy && $status === GenerationStatus::Running && $this->jobStartedAt === 0) {
            Log::warning('A transient database lock hit the queue while another worker is running this attempt; it was left running.', ['generation_attempt_id' => $this->attempt->id]);

            return true;
        }

        return false;
    }

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
            // A run that finishes clears any failure recorded for it while it was in flight.
            'failure_category' => null,
            'failure_message' => null,
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
