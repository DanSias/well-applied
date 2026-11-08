<?php

namespace App\Jobs;

use App\Models\ResumeReadinessReview;
use App\Support\ReadinessReview\RunReadinessReview;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Runs one Application Readiness Review in the background; the review row carries its own status, phase and timing, so
 * the workspace shows progress from it.
 */
final class RunReadinessReviewJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public readonly int $reviewId) {}

    public function handle(RunReadinessReview $review): void
    {
        $review->run(ResumeReadinessReview::query()->findOrFail($this->reviewId));
    }

    public function failed(?\Throwable $e): void
    {
        ResumeReadinessReview::query()->whereKey($this->reviewId)->whereIn('status', ['queued', 'running'])->update([
            'status' => 'failed', 'phase' => null, 'finished_at' => now(), 'failure_message' => mb_substr($e?->getMessage() ?? 'The review stopped unexpectedly.', 0, 500),
        ]);
    }
}
