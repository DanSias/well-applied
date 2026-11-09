<?php

namespace App\Support\JobDiscovery\Providers;

use App\Support\JobDiscovery\DiscoveredJobCandidate;
use App\Support\JobDiscovery\DiscoverySearchCriteria;
use App\Support\JobDiscovery\LoadSearchProfiles;
use App\Support\JobDiscovery\Providers\Eightfold\EightfoldClient;
use App\Support\JobDiscovery\Providers\Eightfold\EightfoldPositionMapper;
use App\Support\JobDiscovery\Providers\Eightfold\EightfoldRequestException;
use App\Support\JobDiscovery\Providers\Eightfold\EightfoldTenant;
use App\Support\JobDiscovery\Providers\Eightfold\TenantRunReport;
use App\Support\JobDiscovery\SearchProfile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One provider for every configured Eightfold employer
 * (config/job_discovery.php `eightfold.tenants`).
 *
 * Per tenant: search the profile's include terms (bounded queries and
 * pages), drop positions that cannot match from the search row alone
 * (title, excluded terms, non-US, work arrangement), fetch descriptions
 * only for the survivors (cached per tenant + position + posting version),
 * and normalize them. A tenant that fails ends only its own run; the rest
 * continue, and the failure is reported through ReportsPartialFailure so
 * the attempt is not recorded as fully healthy. Only when EVERY tenant
 * fails and nothing was retrieved does retrieve() throw.
 *
 * With no profile in the context, the `software_engineering` search
 * profile is the built-in default. `maxResults` is a ceiling on
 * candidates, shared evenly across enabled tenants.
 */
final class EightfoldDiscoveryProvider implements DiscoveryProviderContract, ReportsPartialFailure
{
    private const DEFAULT_PROFILE = 'software_engineering';

    private const SUMMARY_LIMIT = 900;

    /** @var array<string, TenantRunReport> */
    private array $reports = [];

    /** @var string[] */
    private array $excludedTitleKeywords = [];

    public function __construct(private readonly EightfoldPositionMapper $mapper = new EightfoldPositionMapper) {}

    /**
     * Whether the provider should be registered and scheduled at all.
     */
    public static function isActive(): bool
    {
        return config('job_discovery.eightfold.enabled') === true && EightfoldTenant::anyEnabled();
    }

    /**
     * @return DiscoveredJobCandidate[]
     */
    public function retrieve(?ProviderDiscoveryContext $context = null): array
    {
        $this->reports = [];
        $tenants = EightfoldTenant::enabled();

        if ($tenants === []) {
            return [];
        }

        $profile = $context->profile ?? LoadSearchProfiles::byId(self::DEFAULT_PROFILE);
        $this->excludedTitleKeywords = DiscoverySearchCriteria::fromConfig()->excludedTitleKeywords;
        $perTenantCap = $context?->maxResults === null ? null : max(1, (int) ceil($context->maxResults / count($tenants)));

        $candidates = [];
        foreach ($tenants as $tenant) {
            $report = new TenantRunReport($tenant->slug);
            $this->reports[$tenant->slug] = $report;

            try {
                $this->retrieveTenant($tenant, $profile, $perTenantCap, $report);
            } catch (EightfoldRequestException $e) {
                $report->fail($e);
            } catch (Throwable $e) {
                // Isolation: an unexpected bug on one tenant's data must not take the others down.
                $report->fail('Unexpected '.class_basename($e).' while processing this tenant.', 'unexpected_error');
            }

            if ($report->status === TenantRunReport::OK && $report->detailFailures > 0) {
                $report->status = TenantRunReport::DEGRADED;
            }

            array_push($candidates, ...$report->candidates);
            $this->logTenant($report);
        }

        $failed = array_filter($this->reports, fn (TenantRunReport $r) => $r->status === TenantRunReport::FAILED);

        if (count($failed) === count($this->reports) && $candidates === []) {
            throw new DiscoveryProviderException($this->boundedSummary($failed) ?? 'Every Eightfold tenant failed.');
        }

        return $candidates;
    }

    public function partialFailureSummary(): ?string
    {
        $unhealthy = array_filter($this->reports, fn (TenantRunReport $r) => ! $r->healthy());

        return $unhealthy === [] ? null : $this->boundedSummary($unhealthy);
    }

    /**
     * Structured per-tenant ledger for the last retrieve().
     *
     * @return array<string, array<string, mixed>>
     */
    public function diagnostics(): array
    {
        return array_map(fn (TenantRunReport $r) => $r->toArray(), $this->reports);
    }

    private function retrieveTenant(EightfoldTenant $tenant, SearchProfile $profile, ?int $cap, TenantRunReport $report): void
    {
        $client = new EightfoldClient($tenant);

        try {
            $plausible = $this->search($tenant, $client, $profile, $report);
            $report->plausible = count($plausible);
            $this->fetchAndMap($tenant, $client, $plausible, $cap, $report);
        } finally {
            $stats = $client->stats();
            $report->requests = $stats['requests'];
            $report->retries = $stats['retries'];
            $report->rateLimited = $stats['rate_limited'];
        }
    }

    /**
     * Run the profile's include terms within the search budget.
     *
     * Spelling variants that Eightfold treats identically ("full-stack" /
     * "full stack") share one query, and the budget is spent breadth-first:
     * first page of every distinct term, then later pages of terms that still
     * have results. When the profile wants remote_us the tenant's remote
     * location hint narrows the API's own results to remote roles.
     *
     * @return array<string, array<string, mixed>> Plausible rows keyed by position id.
     */
    private function search(EightfoldTenant $tenant, EightfoldClient $client, SearchProfile $profile, TenantRunReport $report): array
    {
        $budget = $tenant->budget;
        $terms = $this->queryTerms($profile);
        $hint = $profile->remotePreference === 'remote_us' ? $tenant->remoteLocationHint : null;
        $report->locationHint = $hint;
        $next = array_fill(0, count($terms), 0);
        $open = array_fill(0, count($terms), true);
        $seen = [];
        $plausible = [];

        for ($pass = 0; $pass < $budget['max_pages_per_query']; $pass++) {
            foreach ($terms as $i => $term) {
                if (! $open[$i]) {
                    continue;
                }
                if ($report->searchRequests >= $budget['max_search_requests']) {
                    break 2;
                }
                $report->searchRequests++;
                if ($pass === 0) {
                    $report->queriesRun++;
                }

                $result = $client->search($term, $next[$i], $hint);
                $rows = $result['positions'];
                $report->searchRows += count($rows);
                $next[$i] += 10;
                $total = $result['count'];
                $open[$i] = count($rows) >= 10 && ($total === null || $next[$i] < $total);

                foreach ($rows as $row) {
                    $id = is_scalar($row['id'] ?? null) ? (string) $row['id'] : null;
                    if ($id === null) {
                        $report->dropped['malformed_row'] = ($report->dropped['malformed_row'] ?? 0) + 1;

                        continue;
                    }
                    if (isset($seen[$id])) {
                        continue;
                    }
                    $seen[$id] = true;
                    $report->uniquePositions++;

                    $reason = $this->mapper->dropReason($row, $profile, $this->excludedTitleKeywords);
                    if ($reason !== null) {
                        $report->dropped[$reason] = ($report->dropped[$reason] ?? 0) + 1;

                        continue;
                    }
                    $plausible[$id] = $row;
                }
            }
        }

        $report->queriesSkippedByBudget = count($terms) - $report->queriesRun;

        return $plausible;
    }

    /**
     * Distinct query strings: terms that differ only by hyphen/space/case
     * return identical results, so only the first spelling is sent.
     *
     * @return list<string>
     */
    private function queryTerms(SearchProfile $profile): array
    {
        $terms = [];
        foreach ($profile->includeTerms as $term) {
            $key = trim((string) preg_replace('/[\s\-]+/u', ' ', mb_strtolower($term)));
            if ($key !== '' && ! isset($terms[$key])) {
                $terms[$key] = trim($term);
            }
        }

        return array_values($terms);
    }

    /**
     * @param  array<string, array<string, mixed>>  $plausible
     */
    private function fetchAndMap(EightfoldTenant $tenant, EightfoldClient $client, array $plausible, ?int $cap, TenantRunReport $report): void
    {
        $now = CarbonImmutable::now();

        foreach ($plausible as $id => $row) {
            if ($cap !== null && count($report->candidates) >= $cap) {
                break;
            }

            $cacheKey = $this->cacheKey($tenant, (string) $id, $row);
            $detail = Cache::get($cacheKey);

            if (is_array($detail)) {
                $report->detailsFromCache++;
            } else {
                if ($report->detailRequests >= $tenant->budget['max_detail_requests']) {
                    $report->detailsSkippedByBudget++;

                    continue;
                }
                $report->detailRequests++;

                try {
                    $detail = $client->details((string) $id);
                } catch (EightfoldRequestException $e) {
                    if ($e->endsTenantRun()) {
                        throw $e;
                    }
                    $report->detailFailures++;

                    continue;
                }

                Cache::put($cacheKey, $this->trimForCache($detail), now()->addHours($tenant->budget['detail_cache_hours']));
            }

            $candidate = $this->mapper->toCandidate($tenant, $row, $detail, $now);
            if ($candidate === null) {
                $report->dropped['unmappable'] = ($report->dropped['unmappable'] ?? 0) + 1;

                continue;
            }
            $report->candidates[] = $candidate;
        }
    }

    /**
     * A posting is re-fetched when its version (posted/created timestamp) changes.
     *
     * @param  array<string, mixed>  $row
     */
    private function cacheKey(EightfoldTenant $tenant, string $id, array $row): string
    {
        $version = $row['postedTs'] ?? $row['creationTs'] ?? 'unversioned';

        return 'eightfold:detail:'.$tenant->slug.':'.$id.':'.(is_scalar($version) ? (string) $version : 'unversioned');
    }

    /**
     * Drop bulky fields the mapper never reads before caching.
     *
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>
     */
    private function trimForCache(array $detail): array
    {
        unset($detail['positionExtraDetails'], $detail['jdHighlight'], $detail['positionUserActions']);

        return $detail;
    }

    /**
     * @param  array<string, TenantRunReport>  $reports
     */
    private function boundedSummary(array $reports): ?string
    {
        if ($reports === []) {
            return null;
        }

        $summary = 'Eightfold: '.implode('; ', array_map(fn (TenantRunReport $r) => $r->summary(), $reports)).'.';

        return mb_strlen($summary) > self::SUMMARY_LIMIT ? mb_substr($summary, 0, self::SUMMARY_LIMIT - 1).'…' : $summary;
    }

    private function logTenant(TenantRunReport $report): void
    {
        $context = $report->toArray();
        if ($report->status === TenantRunReport::OK) {
            Log::info('Eightfold discovery tenant finished.', $context);
        } else {
            Log::warning('Eightfold discovery tenant did not finish cleanly.', $context);
        }
    }
}
