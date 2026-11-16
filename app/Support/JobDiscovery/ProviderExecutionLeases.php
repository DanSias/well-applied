<?php

namespace App\Support\JobDiscovery;

use App\Enums\JobDiscoverySource;
use Illuminate\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use LogicException;

/**
 * The one place that decides whether a provider may be executed right now. Every path that runs a provider (the
 * scheduler, `discovery:run`, a queued Run Now) goes through RunJobDiscovery, which takes the provider's lease here
 * before it creates any attempt, so two executions of the same provider can never overlap while different providers stay
 * independent.
 *
 * It is a cache lock (atomic on every store the app uses; the database store here), held as a renewable lease: see
 * config/job_discovery.php `execution` for why the lifetime is what it is. A holder that crashes stops renewing and the
 * lease expires; a holder never releases a lease another owner has taken over (the lock compares owner tokens).
 */
final class ProviderExecutionLeases
{
    public function seconds(): int
    {
        return max(60, (int) config('job_discovery.execution.lease_seconds', 900));
    }

    public function key(JobDiscoverySource $source): string
    {
        return 'discovery:execution:'.$source->value;
    }

    /**
     * Take the provider's lease, or null when another execution holds it. Never waits.
     *
     * @param  string|null  $owner  A stable token lets the same logical execution find its own lease again (a queued job's
     *                              failure handler, for example); null generates a random one.
     */
    public function acquire(JobDiscoverySource $source, ?string $owner = null): ?ExecutionLease
    {
        $lock = $this->lock($source, $owner);

        return $lock->get() ? new ExecutionLease($lock, $this->seconds()) : null;
    }

    /** True while any execution holds the provider's lease. */
    public function isHeld(JobDiscoverySource $source): bool
    {
        return $this->lock($source)->isLocked();
    }

    /** Release the lease only if `$owner` still holds it. A different owner's lease is left alone. */
    public function releaseIfOwned(JobDiscoverySource $source, string $owner): void
    {
        $this->restore($source, $owner)->release();
    }

    private function restore(JobDiscoverySource $source, string $owner): Lock
    {
        return $this->lock($source, $owner);
    }

    /** Every cache store the app can use returns a full Lock (refreshable, with an owner); a bare contract is a misconfiguration. */
    private function lock(JobDiscoverySource $source, ?string $owner = null): Lock
    {
        $lock = Cache::lock($this->key($source), $this->seconds(), $owner);

        return $lock instanceof Lock ? $lock : throw new LogicException('The cache store does not provide refreshable locks.');
    }
}
