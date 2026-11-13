<?php

namespace App\Http\Controllers;

use App\Http\Requests\JobDecisionRequest;
use App\Http\Requests\UndoJobDecisionsRequest;
use App\Models\JobPosting;
use App\Support\JobDecisions\RecordJobDecisionBatch;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Shortlist, dismiss, pursue or restore jobs, and undo those decisions. Thin by design: every rule lives in
 * RecordJobDecision, and a single job goes through the same batch path as many so the page always receives one result
 * shape (`decision_result`: what changed, what was already so, what was refused and why, and the decision ids to undo).
 */
class JobDecisionController extends Controller
{
    public function __construct(private readonly RecordJobDecisionBatch $decisions) {}

    public function store(JobDecisionRequest $request, JobPosting $jobPosting): RedirectResponse
    {
        return $this->respond($this->decisions->record([$jobPosting->id], $request->disposition(), $request->reason(), $request->note()));
    }

    public function batch(JobDecisionRequest $request): RedirectResponse
    {
        return $this->respond($this->decisions->record($request->jobIds(), $request->disposition(), $request->reason(), $request->note()));
    }

    public function undo(UndoJobDecisionsRequest $request): RedirectResponse
    {
        return $this->respond($this->decisions->undo($request->decisions()));
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function respond(array $result): RedirectResponse
    {
        Inertia::flash('decision_result', $result);

        return back()->with('decision_result', $result);
    }
}
