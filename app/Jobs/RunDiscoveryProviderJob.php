<?php

namespace App\Jobs;

use App\Enums\DiscoveryStatus;
use App\Models\DiscoveryProviderAttempt;
use App\Support\JobDiscovery\DiscoveryExecutionRecovery;
use App\Support\JobDiscovery\ProviderExecutionLeases;
use App\Support\JobDiscovery\RunJobDiscovery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs one queued Run Now request: the provider attempt that RequestProviderRun recorded. All the work, locking and
 * recording are RunJobDiscovery::executeQueued's; this only asks it to, and decides what to do when it could not.
 *
 * - Delivered twice, or the request was already recovered: the attempt is no longer queued, so this does nothing.
 * - The provider's lease is held (a scheduled or CLI run got there first): put the job back for a while rather than run
 *   two at once. If it never becomes free, the failure handler records the request as never started.
 * - The worker is killed or the job throws: `failed()` records the attempt, and frees only this request's own lease.
 */
final class RunDiscoveryProviderJob implements ShouldQueue
{
    use Queueable;

    /** Each wait for a busy provider is a release, which counts as an attempt: 20 x 30s is about ten minutes of waiting. */
    public int $tries = 20;

    public int $maxExceptions = 1;

    /** Below the database queue's own `retry_after` (2 hours), so the queue never hands a still-running job to a second worker. */
    public int $timeout = 3600;

    public function __construct(
        public readonly int $attemptId,
    ) {}

    public function handle(RunJobDiscovery $discovery): void
    {
        $attempt = DiscoveryProviderAttempt::query()->find($this->attemptId);

        if ($attempt === null || $attempt->status !== DiscoveryStatus::Queued) {
            return;
        }

        if ($discovery->executeQueued($attempt) === 'busy') {
            $this->release(30);
        }
    }

    public function failed(?Throwable $exception = null): void
    {
        $attempt = DiscoveryProviderAttempt::query()->find($this->attemptId);

        if ($attempt === null) {
            return;
        }

        $recovery = new DiscoveryExecutionRecovery;

        if ($attempt->status === DiscoveryStatus::Queued) {
            // Another delivery of the same request still in the queue will start it; this one failing is not the end.
            if ($recovery->jobIsQueued($attempt->id) === true) {
                return;
            }

            $recovery->fail($attempt->id, DiscoveryStatus::Queued, null, DiscoveryExecutionRecovery::NOT_STARTED, 'This run was requested but never started: its job failed or ran out of attempts (for example while another run of this provider was in progress). Run it again.');
        } elseif ($attempt->status === DiscoveryStatus::Running) {
            $recovery->fail($attempt->id, DiscoveryStatus::Running, null, DiscoveryExecutionRecovery::INTERRUPTED, 'This run was interrupted before it finished: the worker stopped. Anything it had already added was kept.');
            // Only this request's own lease; a lease another execution has since taken is left alone.
            (new ProviderExecutionLeases)->releaseIfOwned($attempt->provider, RunJobDiscovery::queuedOwner($attempt));
        }
    }
}
