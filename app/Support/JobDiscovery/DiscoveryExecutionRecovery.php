<?php

namespace App\Support\JobDiscovery;

use App\Enums\DiscoveryStatus;
use App\Enums\JobDiscoverySource;
use App\Models\DiscoveryProviderAttempt;
use App\Models\DiscoveryRun;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Tells a provider execution that is still alive from one that was abandoned, and records the second as failed. It is
 * the discovery counterpart of StaleGenerations, with the same idea: a persisted `queued` or `running` is a claim, not
 * proof of life, and a process that is killed cannot mark its own attempt.
 *
 * What counts as alive differs by state:
 *   - running: its `updated_at` is renewed on every outgoing request and candidate (ExecutionHeartbeat), so one silent for
 *     a whole lease (`job_discovery.execution.lease_seconds`) is dead, and its lease has expired with it. A running
 *     attempt whose lease is still held is never touched. This covers attempts left running by older CLI or scheduled
 *     runs, which have no lease at all, as well as queued ones.
 *   - queued: nobody has started it. After `queued_wait_seconds` without a worker it is recorded as never started.
 *
 * Recovery only ever changes an attempt that is still queued or running (each update is conditional), so it never
 * overwrites a real outcome, is safe to repeat, and keeps every posting an interrupted run had already ingested:
 * nothing here touches job postings. The parent run is then settled from its attempts like any other.
 */
final class DiscoveryExecutionRecovery
{
    public const INTERRUPTED = 'interrupted';

    public const NOT_STARTED = 'not_started';

    public const PROVIDER_UNAVAILABLE = 'provider_unavailable';

    /** Failure categories of a request that never began executing: no discovery happened, whatever the status says. */
    public const NEVER_EXECUTED = [self::NOT_STARTED, self::PROVIDER_UNAVAILABLE];

    public function __construct(
        private readonly ProviderExecutionLeases $leases = new ProviderExecutionLeases,
    ) {}

    public function runningCutoff(): CarbonInterface
    {
        return now()->subSeconds($this->leases->seconds());
    }

    public function queuedCutoff(): CarbonInterface
    {
        return now()->subSeconds(max(60, (int) config('job_discovery.execution.queued_wait_seconds', 3600)));
    }

    /** How long a scheduled run keeps deferring to a pending request (see `queued_defer_seconds`). */
    public function deferCutoff(): CarbonInterface
    {
        return now()->subSeconds(min(max(60, (int) config('job_discovery.execution.queued_defer_seconds', 3600)), max(60, (int) config('job_discovery.execution.queued_wait_seconds', 3600))));
    }

    public function orphanCutoff(): CarbonInterface
    {
        return now()->subSeconds(max(0, (int) config('job_discovery.execution.queued_orphan_grace_seconds', 300)));
    }

    /**
     * Whether a queued request's job is still in the queue. Only the database queue can be asked; for any other driver
     * the answer is "unknown" (null) and callers fall back to time alone. The job carries the attempt id as its one
     * property, so it is found in the serialized payload.
     */
    public function jobIsQueued(int $attemptId): ?bool
    {
        if (config('queue.default') !== 'database') {
            return null;
        }

        $connection = config('queue.connections.database', []);

        return DB::connection($connection['connection'] ?? null)->table($connection['table'] ?? 'jobs')
            ->where('payload', 'like', '%RunDiscoveryProviderJob%attemptId%i:'.$attemptId.';%')
            ->exists();
    }

    /**
     * A pending Run Now request a scheduled run should defer to: a queued attempt that is younger than
     * `queued_defer_seconds` and, where it can be told, whose job is still in the queue (or is too new to have been lost).
     * The request itself stays valid, and keeps Run Now from being queued twice, until `queued_wait_seconds`; but the
     * scheduler stops yielding to it after the shorter deferral window, so a stalled worker can delay a scheduled run by
     * at most that long. An expired, orphaned, failed or recovered request blocks nothing.
     */
    public function hasPendingRequest(JobDiscoverySource $source): bool
    {
        $queued = DiscoveryProviderAttempt::query()->where('provider', $source)->where('status', DiscoveryStatus::Queued)
            ->where('updated_at', '>', $this->deferCutoff())->get();

        foreach ($queued as $attempt) {
            if ($attempt->updated_at->greaterThan($this->orphanCutoff()) || $this->jobIsQueued($attempt->id) !== false) {
                return true;
            }
        }

        return false;
    }

    /** The provider's queued or running attempt that is still alive, if any. */
    public function activeAttempt(JobDiscoverySource $source): ?DiscoveryProviderAttempt
    {
        return DiscoveryProviderAttempt::query()
            ->where('provider', $source)
            ->where(function ($query) {
                $query->where(fn ($q) => $q->where('status', DiscoveryStatus::Running)->where('updated_at', '>', $this->runningCutoff()))
                    ->orWhere(fn ($q) => $q->where('status', DiscoveryStatus::Queued)->where('updated_at', '>', $this->queuedCutoff()));
            })
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Records abandoned attempts as failed and settles their runs. Returns how many attempts were recovered.
     *
     * @param  JobDiscoverySource|null  $only  Limit to one provider (Run Now clears its own stale claim before checking).
     */
    public function recover(?JobDiscoverySource $only = null): int
    {
        $runIds = [];
        $recovered = 0;

        $running = DiscoveryProviderAttempt::query()->where('status', DiscoveryStatus::Running)
            ->where('updated_at', '<=', $this->runningCutoff())
            ->when($only !== null, fn ($q) => $q->where('provider', $only))->get();

        foreach ($running as $attempt) {
            // A lease still held means someone is on it, whatever the timestamp says.
            if ($this->leases->isHeld($attempt->provider)) {
                continue;
            }
            if ($this->fail($attempt->id, DiscoveryStatus::Running, $this->runningCutoff(), self::INTERRUPTED, 'This run was interrupted before it finished: the process stopped or restarted. Anything it had already added was kept.')) {
                $recovered++;
                $runIds[] = $attempt->discovery_run_id;
            }
        }

        $queued = DiscoveryProviderAttempt::query()->where('status', DiscoveryStatus::Queued)
            ->where('updated_at', '<=', $this->queuedCutoff())
            ->when($only !== null, fn ($q) => $q->where('provider', $only))->get();

        foreach ($queued as $attempt) {
            if ($this->fail($attempt->id, DiscoveryStatus::Queued, $this->queuedCutoff(), self::NOT_STARTED, 'This run was requested but never started: no queue worker picked it up. Start a worker and run it again.')) {
                $recovered++;
                $runIds[] = $attempt->discovery_run_id;
            }
        }

        // A queued request whose job is gone from the queue will never start; do not make it wait out the hour.
        $orphans = DiscoveryProviderAttempt::query()->where('status', DiscoveryStatus::Queued)
            ->where('updated_at', '<=', $this->orphanCutoff())->where('updated_at', '>', $this->queuedCutoff())
            ->when($only !== null, fn ($q) => $q->where('provider', $only))->get();

        foreach ($orphans as $attempt) {
            if ($this->jobIsQueued($attempt->id) === false
                && $this->fail($attempt->id, DiscoveryStatus::Queued, $this->orphanCutoff(), self::NOT_STARTED, 'This run was requested but its job is no longer in the queue, so it never started. Run it again.')) {
                $recovered++;
                $runIds[] = $attempt->discovery_run_id;
            }
        }

        $this->settle(array_values(array_unique($runIds)), $only);

        return $recovered;
    }

    /**
     * Records one attempt as failed, only if it is still in `$from` (and, when given, still silent since `$silentSince`).
     * Used by recovery and by a queued job's failure handler. Settles the parent run when it was changed.
     */
    public function fail(int $attemptId, DiscoveryStatus $from, ?CarbonInterface $silentSince, string $category, string $message): bool
    {
        $changed = DiscoveryProviderAttempt::query()->whereKey($attemptId)->where('status', $from)
            ->when($silentSince !== null, fn ($q) => $q->where('updated_at', '<=', $silentSince))
            ->update([
                'status' => DiscoveryStatus::Failed,
                'finished_at' => now(),
                'failure_category' => $category,
                'failure_message' => $message,
                'updated_at' => now(),
            ]) > 0;

        if ($changed && $silentSince === null) {
            $runId = DiscoveryProviderAttempt::query()->whereKey($attemptId)->value('discovery_run_id');
            $this->settle($runId === null ? [] : [(int) $runId]);
        }

        return $changed;
    }

    /**
     * Settles runs that are still open but have nothing left going: the given runs (which had an attempt just recovered),
     * and any open run that has no attempts at all and has been silent for a whole lease (a process that died right after
     * creating it). A run with a live attempt is never settled here.
     *
     * @param  list<int>  $runIds
     */
    private function settle(array $runIds, ?JobDiscoverySource $only = null): void
    {
        $open = [DiscoveryStatus::Queued, DiscoveryStatus::Running];

        $candidates = DiscoveryRun::query()->whereIn('status', $open)
            ->where(function ($query) use ($runIds) {
                $query->whereIn('id', $runIds)
                    ->orWhere(fn ($q) => $q->whereDoesntHave('providerAttempts')->where('updated_at', '<=', $this->runningCutoff()));
            })
            ->when($only !== null, fn ($q) => $q->whereIn('id', $runIds))
            ->get();

        foreach ($candidates as $run) {
            if ($run->providerAttempts()->whereIn('status', $open)->exists()) {
                continue;
            }
            // An empty run that never had an attempt did nothing, which is a failure rather than a success.
            SettleDiscoveryRun::settle($run, orchestrationFailed: ! $run->providerAttempts()->exists());
        }
    }
}
