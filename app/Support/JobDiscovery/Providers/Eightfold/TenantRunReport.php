<?php

namespace App\Support\JobDiscovery\Providers\Eightfold;

use App\Support\JobDiscovery\DiscoveredJobCandidate;

/**
 * Mutable per-tenant ledger for one retrieve() call: what was asked, what
 * came back, what was dropped and why, and how the tenant ended.
 */
final class TenantRunReport
{
    public const OK = 'ok';

    /** Finished, but some positions were skipped because their details failed. */
    public const DEGRADED = 'degraded';

    /** Stopped early; results gathered before the failure are kept. */
    public const FAILED = 'failed';

    public string $status = self::OK;

    public ?string $failureCategory = null;

    public ?string $failureMessage = null;

    public int $searchRequests = 0;

    public int $searchRows = 0;

    public int $uniquePositions = 0;

    /** @var array<string, int> */
    public array $dropped = [];

    public int $plausible = 0;

    public int $detailRequests = 0;

    public int $detailsFromCache = 0;

    public int $detailFailures = 0;

    public int $detailsSkippedByBudget = 0;

    public ?string $locationHint = null;

    public int $queriesRun = 0;

    public int $queriesSkippedByBudget = 0;

    public int $retries = 0;

    public int $rateLimited = 0;

    public int $requests = 0;

    /** @var DiscoveredJobCandidate[] */
    public array $candidates = [];

    public function __construct(public readonly string $tenant) {}

    public function fail(EightfoldRequestException|string $reason, ?string $category = null): void
    {
        $this->status = self::FAILED;
        if ($reason instanceof EightfoldRequestException) {
            $this->failureCategory = $reason->category;
            $this->failureMessage = $reason->getMessage();
        } else {
            $this->failureCategory = $category ?? 'unexpected_error';
            $this->failureMessage = $reason;
        }
    }

    public function healthy(): bool
    {
        return $this->status === self::OK;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'tenant' => $this->tenant,
            'status' => $this->status,
            'failure_category' => $this->failureCategory,
            'failure_message' => $this->failureMessage,
            'requests' => $this->requests,
            'search_requests' => $this->searchRequests,
            'location_hint' => $this->locationHint,
            'queries_run' => $this->queriesRun,
            'queries_skipped_by_budget' => $this->queriesSkippedByBudget,
            'search_rows' => $this->searchRows,
            'unique_positions' => $this->uniquePositions,
            'dropped_before_details' => $this->dropped,
            'plausible' => $this->plausible,
            'detail_requests' => $this->detailRequests,
            'details_from_cache' => $this->detailsFromCache,
            'detail_failures' => $this->detailFailures,
            'details_skipped_by_budget' => $this->detailsSkippedByBudget,
            'retries' => $this->retries,
            'rate_limited_responses' => $this->rateLimited,
            'candidates' => count($this->candidates),
        ];
    }

    /**
     * One compact clause for a non-healthy tenant, e.g.
     * "nvidia failed (rate_limited: HTTP 429 …)".
     */
    public function summary(): string
    {
        if ($this->status === self::FAILED) {
            return "{$this->tenant} failed ({$this->failureCategory}: {$this->failureMessage})";
        }

        return "{$this->tenant} degraded ({$this->detailFailures} position".($this->detailFailures === 1 ? '' : 's').' skipped after failed detail requests)';
    }
}
