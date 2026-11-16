<?php

namespace App\Support\Home;

use App\Enums\DiscoveryRejectionReason;
use App\Enums\DiscoveryStatus;
use App\Enums\JobDiscoverySource;
use App\Models\DiscoveryProviderAttempt;
use App\Models\DiscoveryRejection;
use App\Support\JobDiscovery\DiscoveryExecutionRecovery;
use App\Support\JobDiscovery\DiscoveryProviderInventory;

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
        $overrides = DiscoveryProviderInventory::overrides();
        $resolved = array_map(
            fn (JobDiscoverySource $source) => ['source' => $source, 'schedule' => DiscoveryProviderInventory::effectiveSchedule($source, $overrides)],
            DiscoveryProviderInventory::sources(),
        );

        // A provider is listed while the scheduler runs it, or once it has any history (so a paused or unavailable one
        // keeps its past visible).
        $listed = array_values(array_filter(
            $resolved,
            fn (array $entry) => $entry['schedule'] === DiscoveryProviderInventory::SCHEDULE_AUTOMATIC
                || DiscoveryProviderAttempt::query()->where('provider', $entry['source'])->exists(),
        ));

        $providers = array_map(
            fn (array $entry) => $this->forProvider($entry['source'], $entry['schedule'] === DiscoveryProviderInventory::SCHEDULE_AUTOMATIC, $entry['schedule']),
            $listed,
        );

        return [
            'providers' => $providers,
            'active_rejections' => DiscoveryRejection::query()->active()->count(),
            'stale' => array_filter($providers, fn (array $p) => $p['stale']) !== [],
            // A request still waiting in the queue, or one that never began, is not a run that happened.
            'ever_ran' => array_filter($providers, fn (array $p) => $p['latest'] !== null && ! $p['latest']['never_executed']) !== [],
        ];
    }

    /**
     * One provider's health. Shared with the Sources page, so the two never disagree about its last success, its latest
     * outcome or whether it is overdue.
     *
     * @return array<string, mixed>
     */
    public function forProvider(JobDiscoverySource $source, bool $scheduled, ?string $schedule = null): array
    {
        return $this->fromAttempts($source, $scheduled, $this->latest($source), $this->latestSuccess($source), $schedule);
    }

    /**
     * The same presentation from attempts the caller has already loaded, so a page that needs the full attempts too does
     * not query them twice.
     *
     * @return array<string, mixed>
     */
    public function fromAttempts(JobDiscoverySource $source, bool $scheduled, ?DiscoveryProviderAttempt $latest, ?DiscoveryProviderAttempt $success, ?string $schedule = null): array
    {
        $reference = $success === null ? null : ($success->finished_at ?? $success->started_at);
        $ageHours = $reference === null ? null : (int) floor($reference->diffInHours(now(), true));
        $staleAfter = (int) config('job_discovery.schedule.stale_after_hours') * self::STALE_MULTIPLIER;

        return [
            'provider' => $source->value,
            'label' => DiscoveryProviderInventory::label($source),
            'scheduled' => $scheduled,
            // The effective schedule (automatic, paused, manual_only, unavailable) when the caller resolved it. Health
            // is judged on its own: a paused provider keeps its history and is never "overdue" for being paused.
            'schedule' => $schedule ?? ($scheduled ? DiscoveryProviderInventory::SCHEDULE_AUTOMATIC : DiscoveryProviderInventory::SCHEDULE_MANUAL_ONLY),
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
                // Queued, or a failure recorded for a request that never began: no discovery happened.
                'never_executed' => $latest->status === DiscoveryStatus::Queued
                    || ($latest->status === DiscoveryStatus::Failed && in_array($latest->failure_category, DiscoveryExecutionRecovery::NEVER_EXECUTED, true)),
            ],
        ];
    }

    /**
     * A Partial attempt did real work (one tenant or page failed, the rest kept its counts), so it counts as the last
     * success; it is labelled as partial rather than passed off as a clean run.
     */
    public function latestSuccess(JobDiscoverySource $source): ?DiscoveryProviderAttempt
    {
        return $this->latest($source, [DiscoveryStatus::Succeeded, DiscoveryStatus::Partial]);
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
