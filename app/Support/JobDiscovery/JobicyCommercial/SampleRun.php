<?php

namespace App\Support\JobDiscovery\JobicyCommercial;

use Illuminate\Support\Sleep;

/**
 * One controlled sample: the free page, then the Commercial page of the same window, then the comparison. At most ONE page per tier (no cursor following), spaced at
 * least one second apart (Jobicy's documented limit), under a strict SpendBudget, with NO retry: any failure stops the run and is reported, and a 429/503 records
 * its (clamped) Retry-After for the operator instead of waiting and trying again. Nothing is persisted and no production table is read or written.
 */
final class SampleRun
{
    public const SPACING_MILLISECONDS = 1100;

    public function __construct(private readonly JobicyApiClient $client, private readonly SampleEvaluator $evaluator = new SampleEvaluator) {}

    /**
     * @param  array<string, scalar>  $filters
     * @return array<string, mixed> a report; `run.status` is 'completed' or the reason it stopped
     */
    public function run(SpendBudget $budget, int $count, array $filters = ['geo' => 'usa']): array
    {
        $run = ['status' => 'completed', 'requests_made' => 0, 'worst_case_cents' => $count * SpendBudget::CENTS_PER_DIRECT_JOB, 'budget_cents' => $budget->maxCostCents, 'retry_after_seconds' => null, 'charge_unknown' => false];

        // Refused before ANY request: no key, or a worst case the budget can not cover.
        if (! $this->client->hasKey()) {
            $run['status'] = JobicyApiException::NOT_CONFIGURED;

            return $this->finish($run, $budget);
        }
        if (! $budget->canAffordCommercial($count) || $budget->maxRequests < 2) {
            $run['status'] = 'budget_too_small';

            return $this->finish($run, $budget);
        }

        // 1. The free page (no credential is sent).
        try {
            $budget->recordRequest();
            $public = $this->client->page('public', $count, $filters);
        } catch (JobicyApiException $e) {
            return $this->stopped($run, $e, 'public', $budget);
        }

        Sleep::for(self::SPACING_MILLISECONDS)->milliseconds();

        // 2. The Commercial page: only if its worst case still fits.
        if (! $budget->canAffordCommercial($count)) {
            $run['status'] = 'budget_too_small';

            return $this->finish($run, $budget);
        }
        try {
            $budget->recordRequest();
            $budget->reserve($count);
            $commercial = $this->client->page('commercial', $count, $filters);
        } catch (JobicyApiException $e) {
            // A failed paid request may or may not have charged: the reservation is released but the run says the charge is unknown.
            $budget->recordCharge(0.0);
            $run['charge_unknown'] = true;

            return $this->stopped($run, $e, 'commercial', $budget);
        }

        $total = is_numeric($commercial['total_request_cost'] ?? null) ? (float) $commercial['total_request_cost'] : null;
        $budget->recordCharge($total ?? 0.0);
        if ($budget->exceeded()) {
            $run['status'] = 'budget_exceeded';
        }

        $report = $this->evaluator->evaluate($public['jobs'], $commercial['jobs'], ['commercial_total_cost_usd' => $total]);
        unset($report['_candidates']);

        return $this->finish($run, $budget, $report);
    }

    /**
     * @param  array<string, mixed>  $run
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function finish(array $run, SpendBudget $budget, array $extra = []): array
    {
        return ['schema' => SampleEvaluator::SCHEMA, 'run' => array_merge($run, ['requests_made' => $budget->requestsUsed(), 'reported_cents' => $budget->reportedCents()])] + $extra;
    }

    /**
     * @param  array<string, mixed>  $run
     * @return array<string, mixed>
     */
    private function stopped(array $run, JobicyApiException $e, string $tier, SpendBudget $budget): array
    {
        $run['status'] = $e->reason;
        $run['stopped_at_tier'] = $tier;
        $run['http_status'] = $e->status;
        // Honoured by STOPPING: the operator re-runs after this long, if at all. The run never waits and retries on its own.
        $run['retry_after_seconds'] = $e->retryAfterSeconds;

        return $this->finish($run, $budget);
    }
}
