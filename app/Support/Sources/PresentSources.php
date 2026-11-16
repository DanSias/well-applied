<?php

namespace App\Support\Sources;

use App\Enums\DiscoveryStatus;
use App\Enums\JobDiscoverySource;
use App\Models\DiscoveryProviderAttempt;
use App\Models\DiscoveryRun;
use App\Models\JobPosting;
use App\Support\CurrentCareerProfile;
use App\Support\Home\PresentDiscoveryHealth;
use App\Support\JobDiscovery\DiscoveryExecutionRecovery;
use App\Support\JobDiscovery\DiscoveryProviderInventory;
use App\Support\JobDiscovery\Providers\UsajobsDiscoveryProvider;
use App\Support\JobDiscovery\SchedulerHeartbeat;
use Carbon\CarbonInterface;

/**
 * Read-only presentation for the Sources page: every discovery provider, what discovery did recently, and one
 * provider's detail and history. It only reads. Registration and schedule come from DiscoveryProviderInventory, and
 * health (last success, latest outcome, overdue) from PresentDiscoveryHealth, the same code Overview uses, so the two
 * pages cannot disagree.
 *
 * Counters are reported per attempt and never added up across providers or runs: they mean different things per
 * provider (see docs/job-discovery.md "Counters"). A null counter is "not recorded" and stays null; 0 is a real zero. A
 * Succeeded attempt with zero candidates is a quiet source, not a failure. The only cross-provider figure is the number
 * of discovered postings created in the reporting window, counted from the postings themselves.
 *
 * Every list is bounded: fixed page sizes, a clamped page number, and attempts eager-loaded with their runs.
 */
final class PresentSources
{
    public const WINDOW_DAYS = 7;

    public const ACTIVITY_PER_PAGE = 10;

    public const HISTORY_PER_PAGE = 10;

    public function __construct(
        private readonly DiscoveryProviderInventory $inventory = new DiscoveryProviderInventory,
        private readonly PresentDiscoveryHealth $health = new PresentDiscoveryHealth,
        private readonly DiscoveryExecutionRecovery $executions = new DiscoveryExecutionRecovery,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function present(int $activityPage = 1): array
    {
        $providers = $this->providers();

        return [
            'summary' => $this->summary($providers),
            'providers' => $providers,
            'activity' => $this->activity($activityPage),
        ];
    }

    /**
     * @param  list<array<string, mixed>>|null  $providers
     * @return array<string, mixed>
     */
    public function summary(?array $providers = null): array
    {
        $providers ??= $this->providers();
        $since = $this->since();
        $attention = array_values(array_map(
            fn (array $p) => $p['provider'],
            array_filter($providers, fn (array $p) => $p['needs_attention']),
        ));

        $neverRun = array_values(array_map(
            fn (array $p) => $p['provider'],
            array_filter($providers, fn (array $p) => $p['never_run']),
        ));

        $lastRun = DiscoveryRun::query()->orderByDesc('id')->first();
        $count = fn (string $schedule) => count(array_filter($providers, fn (array $p) => $p['effective_schedule'] === $schedule));

        return [
            'window_days' => self::WINDOW_DAYS,
            'since' => $since->toIso8601String(),
            'total' => count($providers),
            'configured' => count(array_filter($providers, fn (array $p) => $p['configured'])),
            'automatic' => $count(DiscoveryProviderInventory::SCHEDULE_AUTOMATIC),
            'manual_only' => $count(DiscoveryProviderInventory::SCHEDULE_MANUAL_ONLY),
            'paused' => $count(DiscoveryProviderInventory::SCHEDULE_PAUSED),
            'unavailable' => $count(DiscoveryProviderInventory::SCHEDULE_UNAVAILABLE),
            'needs_attention' => count($attention),
            'attention_providers' => $attention,
            'never_run' => count($neverRun),
            'never_run_providers' => $neverRun,
            'runs_in_window' => DiscoveryRun::query()->where('started_at', '>=', $since)->count(),
            'last_run_at' => $lastRun === null ? null : ($lastRun->finished_at ?? $lastRun->started_at)?->toIso8601String(),
            'ever_ran' => $lastRun !== null,
            'jobs_added' => array_sum(array_column($providers, 'jobs_added')),
            // Whether a scheduler has actually been seen ticking. Separate from queue workers and from every provider.
            'scheduler' => SchedulerHeartbeat::status(),
        ];
    }

    /**
     * All five providers, in a stable order.
     *
     * @return list<array<string, mixed>>
     */
    public function providers(): array
    {
        $added = $this->jobsAddedBySource();
        $since = $this->since();
        $windowAttempts = DiscoveryProviderAttempt::query()
            ->where('started_at', '>=', $since)
            ->selectRaw('provider, count(*) as attempts')
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as failed', [DiscoveryStatus::Failed->value])
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as partial', [DiscoveryStatus::Partial->value])
            ->groupBy('provider')
            ->get()
            ->keyBy(fn ($row) => (string) $row->getRawOriginal('provider'));

        return array_map(function (array $described) use ($added, $windowAttempts) {
            $source = JobDiscoverySource::from($described['provider']);
            $window = $windowAttempts->get($source->value);

            return $this->provider($source, $described) + [
                'jobs_added' => $added[$source->value] ?? 0,
                'window' => [
                    'attempts' => (int) ($window->attempts ?? 0),
                    'failed' => (int) ($window->failed ?? 0),
                    'partial' => (int) ($window->partial ?? 0),
                ],
            ];
        }, $this->inventory->all());
    }

    /**
     * Recent runs across providers, newest first, each with its per-provider attempts.
     *
     * @return array{runs: list<array<string, mixed>>, pagination: array<string, int>}
     */
    public function activity(int $page = 1): array
    {
        $total = DiscoveryRun::query()->count();
        $page = $this->clampPage($page, $total, self::ACTIVITY_PER_PAGE);

        $runs = DiscoveryRun::query()->with(['providerAttempts' => fn ($q) => $q->orderBy('id')])
            ->orderByDesc('id')->forPage($page, self::ACTIVITY_PER_PAGE)->get();

        return [
            'runs' => array_values($runs->map(fn (DiscoveryRun $run) => [
                'id' => $run->id,
                'status' => $run->status->value,
                'started_at' => $run->started_at?->toIso8601String(),
                'finished_at' => $run->finished_at?->toIso8601String(),
                'duration_seconds' => $this->duration($run->started_at, $run->finished_at),
                'needs_attention' => in_array($run->status, [DiscoveryStatus::Failed, DiscoveryStatus::Partial], true),
                'attempts' => array_values($run->providerAttempts->map(fn (DiscoveryProviderAttempt $a) => $this->attempt($a))->all()),
            ])->all()),
            'pagination' => $this->pagination($page, $total, self::ACTIVITY_PER_PAGE),
        ];
    }

    /**
     * One provider's attempts, newest first.
     *
     * @return array{attempts: list<array<string, mixed>>, pagination: array<string, int>}
     */
    public function history(JobDiscoverySource $source, int $page = 1): array
    {
        $query = DiscoveryProviderAttempt::query()->where('provider', $source);
        $total = (clone $query)->count();
        $page = $this->clampPage($page, $total, self::HISTORY_PER_PAGE);

        $attempts = $query->orderByDesc('started_at')->orderByDesc('id')->forPage($page, self::HISTORY_PER_PAGE)->get();

        return [
            'attempts' => array_values($attempts->map(fn (DiscoveryProviderAttempt $a) => $this->attempt($a))->all()),
            'pagination' => $this->pagination($page, $total, self::HISTORY_PER_PAGE),
        ];
    }

    /**
     * The drawer's lazily loaded content: read-only configuration and a page of the provider's history. Its summary and
     * latest attempts are already in `providers()`, so they are not fetched a second time.
     *
     * @return array<string, mixed>
     */
    public function detail(JobDiscoverySource $source, int $historyPage = 1): array
    {
        return [
            'provider' => $source->value,
            'configuration' => $this->configuration($source),
            'history' => $this->history($source, $historyPage),
        ];
    }

    /**
     * @param  array<string, mixed>  $described
     * @return array<string, mixed>
     */
    private function provider(JobDiscoverySource $source, array $described): array
    {
        $latest = $this->health->latest($source);
        $success = $this->health->latestSuccess($source);
        $failure = $latest?->status === DiscoveryStatus::Failed ? $latest : $this->health->latest($source, [DiscoveryStatus::Failed]);
        $health = $this->health->fromAttempts($source, $described['effective_schedule'] === DiscoveryProviderInventory::SCHEDULE_AUTOMATIC, $latest, $success, $described['effective_schedule']);

        // Overview's health block, unchanged, so the pages agree; the full attempts sit beside it.
        return $described + [
            'stale' => $health['stale'],
            'health' => $health,
            'last_attempt' => $latest === null ? null : $this->attempt($latest),
            'last_success' => $success === null ? null : $this->attempt($success),
            'last_failure' => $failure === null ? null : $this->attempt($failure),
            // A source that has never run is not in trouble, it is new; overdue only means something once it has succeeded.
            'overdue' => $health['stale'] && $success !== null,
            // Never run: an automatic, configured source with no attempt at all. Not a failure, so it stays out of
            // needs_attention, but it is reported on its own so a scheduler that never fired does not go unnoticed.
            'never_run' => $latest === null
                && $described['configured']
                && $described['effective_schedule'] === DiscoveryProviderInventory::SCHEDULE_AUTOMATIC,
            'run_now' => $this->runNow($source, $described),
            'needs_attention' => ($health['stale'] && $success !== null)
                || ($latest !== null && in_array($latest->status, [DiscoveryStatus::Failed, DiscoveryStatus::Partial], true)),
        ];
    }

    /**
     * Whether Run Now can be offered, and the queued or running execution that rules it out. Independent of scheduling:
     * a paused or manual-only provider can be run by hand whenever it is registered and configured.
     *
     * @param  array<string, mixed>  $described
     * @return array{available: bool, active: 'queued'|'running'|null, attempt_id: int|null, since: string|null, unavailable_reason: string|null}
     */
    private function runNow(JobDiscoverySource $source, array $described): array
    {
        $active = $this->executions->activeAttempt($source);

        return [
            'available' => $described['manual_run_available'] === true && $active === null,
            'active' => match ($active?->status) {
                DiscoveryStatus::Queued => 'queued',
                DiscoveryStatus::Running => 'running',
                default => null,
            },
            'attempt_id' => $active?->id,
            'since' => $active?->started_at?->toIso8601String(),
            'unavailable_reason' => $described['manual_run_available'] === true ? null : ($described['unavailable_reason'] ?? 'Not configured.'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function attempt(DiscoveryProviderAttempt $attempt): array
    {
        $isFailure = $attempt->status !== DiscoveryStatus::Succeeded
            && ($attempt->failure_category !== null || $attempt->failure_message !== null);

        return [
            'id' => $attempt->id,
            'run_id' => $attempt->discovery_run_id,
            'provider' => $attempt->provider->value,
            'status' => $attempt->status->value,
            'started_at' => $attempt->started_at?->toIso8601String(),
            'finished_at' => $attempt->finished_at?->toIso8601String(),
            'duration_seconds' => $this->duration($attempt->started_at, $attempt->finished_at),
            // null = not recorded; 0 = recorded as none.
            'retrieved' => $attempt->candidates_retrieved,
            'accepted' => $attempt->candidates_accepted,
            'created' => $attempt->jobs_created,
            'updated' => $attempt->jobs_updated,
            'canonicalized' => $attempt->jobs_canonicalized,
            'filtered' => $attempt->rejection_counts === null ? null : array_sum($attempt->rejection_counts),
            'filtered_by_reason' => $this->health->reasons($attempt->rejection_counts),
            'retrieved_basis' => $attempt->provider === JobDiscoverySource::Eightfold ? 'after_prefilter' : 'feed',
            'retrieval_diagnostics' => $attempt->retrieval_diagnostics,
            'failure' => $isFailure ? [
                'category' => $attempt->failure_category,
                'message' => $attempt->failure_message,
                'exception_class' => $attempt->failure_exception_class,
            ] : null,
        ];
    }

    /**
     * Discovered postings created in the window, by provider. Counted from the postings themselves (Manual excluded),
     * not summed from attempt counters; a posting later dismissed or shortlisted still counts as discovered.
     *
     * @return array<string, int>
     */
    private function jobsAddedBySource(): array
    {
        $profile = CurrentCareerProfile::tryResolve();
        if ($profile === null) {
            return [];
        }

        return JobPosting::query()
            ->where('career_profile_id', $profile->id)
            ->where('discovery_source', '!=', JobDiscoverySource::Manual->value)
            ->where('created_at', '>=', $this->since())
            ->selectRaw('discovery_source, count(*) as total')
            ->groupBy('discovery_source')
            ->pluck('total', 'discovery_source')
            ->map(fn ($total) => (int) $total)
            ->all();
    }

    /**
     * Read-only, safe settings for the drawer. Credentials are reported only as present or absent; the USAJOBS user
     * agent (a registered email address) and every key and id are never read into the output.
     *
     * @return array<string, mixed>
     */
    public function configuration(JobDiscoverySource $source): array
    {
        $host = fn (string $key) => parse_url((string) config($key), PHP_URL_HOST) ?: null;

        return match ($source) {
            JobDiscoverySource::Himalayas => ['feed_host' => $host('services.himalayas.base_url')],
            JobDiscoverySource::Jobicy => ['feed_host' => $host('services.jobicy.base_url')],
            JobDiscoverySource::Adzuna => [
                'credentials_present' => DiscoveryProviderInventory::isConfigured($source),
                'country' => config('services.adzuna.country'),
            ],
            JobDiscoverySource::Usajobs => [
                'credentials_present' => DiscoveryProviderInventory::isConfigured($source),
                'scheduled_by_config' => config('job_discovery.usajobs.scheduled') === true,
                'feed_host' => $host('services.usajobs.base_url'),
                'category' => config('job_discovery.usajobs.category'),
                'hiring_path' => config('job_discovery.usajobs.hiring_path'),
                'per_page' => config('job_discovery.usajobs.per_page'),
                'max_pages' => config('job_discovery.usajobs.max_pages'),
                'hybrid_regions' => UsajobsDiscoveryProvider::hybridRegions(),
            ],
            JobDiscoverySource::Eightfold => [
                'enabled' => config('job_discovery.eightfold.enabled') === true,
                'scheduled_by_config' => config('job_discovery.eightfold.scheduled') === true,
                'tenants' => $this->eightfoldTenants(),
                'budget' => $this->eightfoldBudget(),
            ],
            JobDiscoverySource::Manual => [],
        };
    }

    /**
     * @return list<array{slug: string, company: string|null, host: string|null, enabled: bool}>
     */
    private function eightfoldTenants(): array
    {
        $tenants = config('job_discovery.eightfold.tenants', []);
        $rows = [];
        foreach (is_array($tenants) ? $tenants : [] as $slug => $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $rows[] = [
                'slug' => (string) $slug,
                'company' => isset($entry['company']) ? (string) $entry['company'] : null,
                'host' => isset($entry['host']) ? (string) $entry['host'] : null,
                'enabled' => ($entry['enabled'] ?? false) === true,
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, int>
     */
    private function eightfoldBudget(): array
    {
        $budget = config('job_discovery.eightfold.budget', []);

        return array_map('intval', array_filter(is_array($budget) ? $budget : [], 'is_numeric'));
    }

    private function since(): CarbonInterface
    {
        return now()->subDays(self::WINDOW_DAYS);
    }

    private function duration(?CarbonInterface $start, ?CarbonInterface $end): ?int
    {
        return $start === null || $end === null ? null : max(0, (int) $start->diffInSeconds($end, true));
    }

    private function clampPage(int $page, int $total, int $perPage): int
    {
        return min(max(1, $page), max(1, (int) ceil($total / $perPage)));
    }

    /**
     * @return array<string, int>
     */
    private function pagination(int $page, int $total, int $perPage): array
    {
        return [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'last_page' => max(1, (int) ceil($total / $perPage)),
            'from' => $total === 0 ? 0 : (($page - 1) * $perPage) + 1,
            'to' => min($total, $page * $perPage),
        ];
    }
}
