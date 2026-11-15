<?php

namespace App\Support\Home;

use App\Enums\DiscoveryRejectionReason;
use App\Enums\DiscoveryStatus;
use App\Enums\JobDiscoverySource;
use App\Models\DiscoveryProviderAttempt;
use App\Models\DiscoveryRejection;
use App\Support\JobDiscovery\DiscoveryProviderInventory;
use App\Support\JobDiscovery\DiscoveryScheduleIsDue;

/**
 * Overview's discovery-health summary: for each provider, its most recent successful attempt and whether anything has
 * happened since. A recent-attempt view, never a sum of historical counters: counters mean different things per provider
 * (see docs/job-discovery.md "Counters"), so nothing here adds them across providers or across time.
 *
 * "Stale" is judged from the last success alone, and only for providers the scheduler runs. Nothing here claims discovery
 * is running: the schedule only fires while a scheduler process is up.
 */
final class PresentDiscoveryHealth
{
    /** A scheduled provider is stale once its last success is older than this many times its normal daily cadence. */
    private const STALE_MULTIPLIER = 2;

    /**
     * @return array{providers: list<array<string, mixed>>, active_rejections: int, stale: bool, ever_ran: bool}
     */
    public function present(): array
    {
        $scheduled = DiscoveryScheduleIsDue::scheduledSources();
        $sources = array_values(array_filter(
            JobDiscoverySource::cases(),
            fn (JobDiscoverySource $source) => $source !== JobDiscoverySource::Manual
                && (in_array($source, $scheduled, true) || DiscoveryProviderAttempt::query()->where('provider', $source)->exists()),
        ));

        $providers = array_map(fn (JobDiscoverySource $source) => $this->forProvider($source, in_array($source, $scheduled, true)), $sources);

        return [
            'providers' => $providers,
            'active_rejections' => DiscoveryRejection::query()->active()->count(),
            'stale' => array_filter($providers, fn (array $p) => $p['stale']) !== [],
            'ever_ran' => array_filter($providers, fn (array $p) => $p['latest'] !== null) !== [],
        ];
    }

    /**
     * One provider's health. Shared with the Sources page, so the two never disagree about its last success, its latest
     * outcome or whether it is overdue.
     *
     * @return array<string, mixed>
     */
    public function forProvider(JobDiscoverySource $source, bool $scheduled): array
    {
        $latest = $this->latest($source);
        // A Partial attempt did real work (one tenant or page failed, the rest kept its counts), so it counts as the last
        // success; it is labelled as partial rather than passed off as a clean run.
        $success = $this->latest($source, [DiscoveryStatus::Succeeded, DiscoveryStatus::Partial]);
        $reference = $success === null ? null : ($success->finished_at ?? $success->started_at);
        $ageHours = $reference === null ? null : (int) floor($reference->diffInHours(now(), true));
        $staleAfter = (int) config('job_discovery.schedule.stale_after_hours') * self::STALE_MULTIPLIER;

        return [
            'provider' => $source->value,
            'label' => DiscoveryProviderInventory::label($source),
            'scheduled' => $scheduled,
            // Only a provider the scheduler runs can be "overdue"; a manual-only one is just "last run".
            'stale' => $scheduled && ($ageHours === null || $ageHours >= $staleAfter),
            'last_success' => $success === null ? null : [
                'status' => $success->status->value,
                'partial_summary' => $success->status === DiscoveryStatus::Partial ? $success->failure_message : null,
                'at' => $reference?->toIso8601String(),
                'age_hours' => $ageHours,
                'retrieved' => $success->candidates_retrieved,
                'accepted' => $success->candidates_accepted,
                'created' => $success->jobs_created,
                'updated' => $success->jobs_updated,
                // null = not recorded (attempt predates reason counts, or the filter stage was not observed).
                'filtered' => $success->rejection_counts === null ? null : array_sum($success->rejection_counts),
                'filtered_by_reason' => $this->reasons($success->rejection_counts),
                // Eightfold's "retrieved" is what survived the provider's own prefilter; the others' is the raw feed.
                'retrieved_basis' => $source === JobDiscoverySource::Eightfold ? 'after_prefilter' : 'feed',
                'normalization_dropped' => $success->retrieval_diagnostics['normalization_dropped'] ?? null,
            ],
            // The most recent attempt of any status, shown only when it differs from the last success (a failure since).
            'latest' => $latest === null ? null : [
                'status' => $latest->status->value,
                'at' => ($latest->finished_at ?? $latest->started_at)?->toIso8601String(),
                'failure' => $latest->status === DiscoveryStatus::Succeeded ? null : $latest->failure_message,
                'is_last_success' => $success !== null && $latest->id === $success->id,
            ],
        ];
    }

    /**
     * @param  list<DiscoveryStatus>|null  $statuses
     */
    public function latest(JobDiscoverySource $source, ?array $statuses = null): ?DiscoveryProviderAttempt
    {
        return DiscoveryProviderAttempt::query()
            ->where('provider', $source)
            ->when($statuses !== null, fn ($query) => $query->whereIn('status', $statuses))
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @param  array<string, int>|null  $counts
     * @return list<array{code: string, label: string, count: int}>
     */
    public function reasons(?array $counts): array
    {
        $rows = [];
        foreach ($counts ?? [] as $code => $count) {
            $rows[] = ['code' => (string) $code, 'label' => DiscoveryRejectionReason::tryFrom((string) $code)?->label() ?? (string) $code, 'count' => (int) $count];
        }

        return $rows;
    }
}
