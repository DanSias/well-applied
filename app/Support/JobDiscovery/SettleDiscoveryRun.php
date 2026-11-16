<?php

namespace App\Support\JobDiscovery;

use App\Enums\DiscoveryStatus;
use App\Models\DiscoveryRun;

/**
 * Derives a run's final status from its provider attempts, the one rule RunJobDiscovery and stale-run recovery share:
 * Succeeded when every attempt did; Failed when all failed (or there were none to succeed); Partial otherwise.
 */
final class SettleDiscoveryRun
{
    public static function settle(DiscoveryRun $discoveryRun, bool $orchestrationFailed = false): DiscoveryRun
    {
        $attempts = $discoveryRun->providerAttempts()->get();
        $failures = $attempts->where('status', DiscoveryStatus::Failed)->count();
        // A provider that only partly succeeded (some Eightfold tenants failed)
        // is not a clean success either.
        $degraded = $failures + $attempts->where('status', DiscoveryStatus::Partial)->count();
        $status = $degraded === 0 && ! $orchestrationFailed ? DiscoveryStatus::Succeeded
            : ($failures < $attempts->count() ? DiscoveryStatus::Partial : DiscoveryStatus::Failed);
        // Only while the run is still open: a run recovery already settled keeps that outcome.
        DiscoveryRun::query()->whereKey($discoveryRun->id)->whereIn('status', [DiscoveryStatus::Queued, DiscoveryStatus::Running])
            ->update(['status' => $status, 'finished_at' => now(), 'updated_at' => now()]);

        return $discoveryRun->fresh('providerAttempts') ?? $discoveryRun;
    }
}
