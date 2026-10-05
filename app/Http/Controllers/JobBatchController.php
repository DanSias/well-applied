<?php

namespace App\Http\Controllers;

use App\Http\Requests\BatchOpportunitiesRequest;
use App\Support\OpportunityTriage\ProcessBatchOpportunities;
use Illuminate\Http\RedirectResponse;

/**
 * Human-initiated batch actions for the Opportunity Triage surface —
 * Phase E3. Two thin POST endpoints that analyze or match an explicitly
 * selected set of job postings by id:
 *
 * - `POST /jobs/batch/analyze` (`jobs.batch.analyze`)
 * - `POST /jobs/batch/match`   (`jobs.batch.match`)
 *
 * Both delegate every real decision to the single shared orchestrator
 * (App\Support\OpportunityTriage\ProcessBatchOpportunities), keeping the
 * controllers deliberately thin: validate the explicit id list, run the
 * batch, and flash the structured result under `batch_result` before
 * redirecting back. No polling, no "all filtered" mode, and nothing is
 * persisted beyond the per-item GenerationAttempt claims the orchestrator
 * already makes for started items.
 */
class JobBatchController extends Controller
{
    public function __construct(
        private readonly ProcessBatchOpportunities $batch,
    ) {}

    /**
     * Analyze the explicitly selected job postings.
     */
    public function analyze(BatchOpportunitiesRequest $request): RedirectResponse
    {
        $result = $this->batch->run('analyze', $request->jobIds());

        return back()->with('batch_result', $result);
    }

    /**
     * Match the explicitly selected job postings against their selected
     * analysis.
     */
    public function match(BatchOpportunitiesRequest $request): RedirectResponse
    {
        $result = $this->batch->run('match', $request->jobIds());

        return back()->with('batch_result', $result);
    }
}
