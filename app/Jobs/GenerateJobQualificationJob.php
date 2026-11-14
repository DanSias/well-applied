<?php

namespace App\Jobs;

use App\Enums\GenerationStatus;
use App\Exceptions\InvalidJobQualificationResponseException;
use App\Exceptions\JobQualificationProviderException;
use App\Jobs\Concerns\RecordsAttemptMetrics;
use App\Models\GenerationAttempt;
use App\Models\JobPosting;
use App\Support\JobQualification\GenerateJobQualification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The GenerationAttempt lifecycle around GenerateJobQualification, mirroring GenerateJobAnalysisJob: it owns queued ->
 * running -> succeeded/failed and nothing about prompts or policy. $tries = 1 for the same reason as the other generation
 * jobs (the transport already retries transient failures; an outer retry would multiply real model calls).
 *
 * A provider failure or an off-contract answer ends as a failed attempt and writes no qualification. Neither, nor
 * success, ever changes the job's disposition, and the one-click pipeline is never advanced from here (`chain` is not
 * used for qualification).
 */
final class GenerateJobQualificationJob implements ShouldQueue
{
    use Queueable;
    use RecordsAttemptMetrics;

    public int $tries = 1;

    public function __construct(
        public readonly GenerationAttempt $attempt,
    ) {}

    public function handle(GenerateJobQualification $generator): void
    {
        if (! $this->claimAttempt()) {
            return;
        }

        $this->beginMetrics();

        $posting = $this->attempt->subject;

        if (! $posting instanceof JobPosting) {
            $this->markFailed('unexpected_error', 'GenerationAttempt subject was not a JobPosting.');

            return;
        }

        try {
            // `regenerate` on the attempt is the person's explicit "run it again".
            $qualification = $generator->generate($posting, $this->attempt->regenerate);
        } catch (JobQualificationProviderException $e) {
            $diagnostics = $e->diagnostics;

            Log::warning('JobQualification generation failed.', [
                'job_posting_id' => $posting->id,
                'generation_attempt_id' => $this->attempt->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
                ...($diagnostics?->toLogContext() ?? []),
            ]);

            $this->markFailed('provider_error', $e->getMessage(), [
                'model' => $diagnostics?->model,
                'finish_reason' => $diagnostics?->finishReason,
                'prompt_tokens' => $diagnostics?->usage['prompt_tokens'] ?? null,
                'completion_tokens' => $diagnostics?->usage['completion_tokens'] ?? null,
                'total_tokens' => $diagnostics?->usage['total_tokens'] ?? null,
            ]);

            return;
        } catch (InvalidJobQualificationResponseException $e) {
            Log::warning('JobQualification generation failed.', [
                'job_posting_id' => $posting->id,
                'generation_attempt_id' => $this->attempt->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            $this->markFailed('validation_error', $e->getMessage());

            return;
        }

        [$provider, $model] = $qualification->generated_by !== null && str_contains($qualification->generated_by, ':')
            ? explode(':', $qualification->generated_by, 2)
            : [null, null];

        $this->attempt->update([
            'status' => GenerationStatus::Succeeded,
            'finished_at' => now(),
            'result_id' => $qualification->id,
            'provider' => $provider,
            'model' => $model,
            'prompt_version' => $qualification->prompt_version,
            'schema_version' => $qualification->schema_version,
            ...$this->successMetrics(),
        ]);
    }

    public function failed(Throwable $exception): void
    {
        if ($this->shouldIgnoreFailure($exception)) {
            return;
        }

        Log::error('JobQualification generation failed unexpectedly.', [
            'generation_attempt_id' => $this->attempt->id,
            'exception' => $exception::class,
        ]);

        $this->markFailed('unexpected_error', 'Job qualification failed unexpectedly.');
    }

    /**
     * @param  array<string, mixed>  $diagnostics
     */
    private function markFailed(string $category, string $message, array $diagnostics = []): void
    {
        $this->attempt->update([
            'status' => GenerationStatus::Failed,
            'finished_at' => now(),
            'failure_category' => $category,
            'failure_message' => $message,
            ...$this->failureMetrics(),
            ...$diagnostics,
        ]);
    }
}
