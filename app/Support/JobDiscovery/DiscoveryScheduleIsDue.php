<?php

namespace App\Support\JobDiscovery;

use App\Enums\DiscoveryStatus;
use App\Enums\JobDiscoverySource;
use App\Models\DiscoveryProviderAttempt;
use App\Support\JobDiscovery\Providers\EightfoldDiscoveryProvider;

/**
 * The per-provider eligibility check bootstrap/app.php's schedule
 * definition gates `discovery:run --due` on — extracted to its own
 * directly-testable class rather than an inline schedule closure,
 * since Laravel's Schedule/Event internals aren't a convenient thing
 * to unit test against directly. See docs/job-discovery.md
 * "Scheduling / orchestration".
 *
 * Eligibility is derived from each provider's own most recent
 * DiscoveryProviderAttempt (already persisted — no extra scheduling
 * state), never from the overall DiscoveryRun status. A run is Partial
 * whenever any one provider fails, so gating on whole-run success made
 * a single failing provider re-run EVERY provider on every hourly tick
 * until the failure cleared — hammering healthy and quota-limited
 * providers alike.
 *
 * A provider is due when:
 *
 *   - it has never been attempted; or
 *   - its latest attempt Succeeded and finished more than
 *     `stale_after_hours` ago (the normal daily cadence); or
 *   - its latest attempt did NOT succeed (Failed, or abandoned still
 *     Running after a crash) and that attempt is more than
 *     `retry_after_hours` old (the bounded retry cadence).
 *
 * So a failed provider is retried every `retry_after_hours` — forever,
 * until it succeeds, which is how transient failures recover with no
 * manual intervention — while healthy providers stay on their normal
 * cadence and are never re-run on its account. Manual `discovery:run`
 * (no --due) ignores all of this and runs every provider.
 */
final class DiscoveryScheduleIsDue
{
    public function __invoke(): bool
    {
        return $this->dueSources() !== [];
    }

    /**
     * @return JobDiscoverySource[]
     */
    public function dueSources(): array
    {
        return array_values(array_filter(
            self::scheduledSources(),
            fn (JobDiscoverySource $source): bool => $this->isDue($source),
        ));
    }

    /**
     * Every real provider (everything but Manual, plus Eightfold only when
     * explicitly scheduled) — kept in lockstep
     * with App\Support\JobDiscovery\RunJobDiscovery's registered
     * providers by a regression test.
     *
     * @return JobDiscoverySource[]
     */
    public static function scheduledSources(): array
    {
        return array_values(array_filter(
            JobDiscoverySource::cases(),
            fn (JobDiscoverySource $source): bool => match ($source) {
                JobDiscoverySource::Manual => false,
                // Opt-in: scheduling stays off until a manual run has been reviewed.
                JobDiscoverySource::Eightfold => EightfoldDiscoveryProvider::isActive()
                    && config('job_discovery.eightfold.scheduled') === true,
                default => true,
            },
        ));
    }

    private function isDue(JobDiscoverySource $source): bool
    {
        $attempt = DiscoveryProviderAttempt::query()
            ->where('provider', $source)
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->first();

        if ($attempt === null) {
            return true;
        }

        $succeeded = $attempt->status === DiscoveryStatus::Succeeded;
        $hours = (int) config($succeeded
            ? 'job_discovery.schedule.stale_after_hours'
            : 'job_discovery.schedule.retry_after_hours');

        // An attempt abandoned mid-run has no finished_at; fall back to
        // started_at so it is still retried rather than blocking forever.
        $reference = $attempt->finished_at ?? $attempt->started_at ?? $attempt->created_at;

        return $reference === null || $reference->lt(now()->subHours($hours));
    }
}
