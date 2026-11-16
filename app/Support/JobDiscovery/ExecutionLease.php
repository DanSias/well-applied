<?php

namespace App\Support\JobDiscovery;

use Illuminate\Cache\Lock;

/**
 * A provider lease that one execution holds. `renew()` pushes the expiry out by a full lease again; `release()` frees it
 * only if this execution still owns it (a lease that expired and was taken by someone else is not theirs to lose).
 */
final class ExecutionLease
{
    private bool $released = false;

    public function __construct(
        private readonly Lock $lock,
        private readonly int $seconds,
    ) {}

    public function owner(): string
    {
        return $this->lock->owner();
    }

    /** False when the lease is no longer ours (it expired and another execution took it). */
    public function renew(): bool
    {
        return ! $this->released && $this->lock->refresh($this->seconds);
    }

    public function release(): void
    {
        if (! $this->released) {
            $this->released = true;
            $this->lock->release();
        }
    }
}
