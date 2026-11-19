<?php

namespace App\Http\Controllers;

use App\Enums\JobDisposition;
use App\Http\Requests\StopPursuingRequest;
use App\Models\JobPosting;
use App\Support\CurrentCareerProfile;
use App\Support\JobDecisions\RecordJobDecisionBatch;
use App\Support\Opportunities\PresentInspectionJob;
use App\Support\Opportunities\PresentOpportunities;
use App\Support\Opportunities\PresentOpportunityWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Opportunities": jobs the user has explicitly decided to pursue (disposition = pursued). There is no opportunity
 * table; this is a view over JobPosting and the work that already hangs off it. Preparing a resume, inspecting the
 * application form and the rest happen in the existing job and resume workspaces this page links into.
 */
class OpportunityController extends Controller
{
    public function index(Request $request, PresentOpportunities $opportunities, PresentInspectionJob $inspection): Response
    {
        $resume = in_array($request->query('resume'), ['none', 'in-progress', 'final'], true) ? (string) $request->query('resume') : 'all';
        $inspectId = ctype_digit((string) $request->query('inspect')) ? (int) $request->query('inspect') : null;

        return Inertia::render('opportunities/index', $opportunities->present($resume) + [
            'filters' => ['resume' => $resume, 'inspect' => $inspectId],
            // Only the inspection drawer's own data, fetched when it opens (partial reload) so the list is left alone.
            'inspection_job' => fn () => $inspectId === null ? null : $this->inspectionJob($inspectId, $inspection),
        ]);
    }

    /**
     * One pursued job as a workspace. The URL carries the job's own id: an opportunity is not a record of its own, so
     * there is nothing else to look up. A job that is not (or is no longer) pursued has no workspace and goes to its
     * job page, which keeps any resume, analysis or inspection work reachable.
     */
    public function show(JobPosting $jobPosting, PresentOpportunityWorkspace $workspace): Response|RedirectResponse
    {
        abort_unless($jobPosting->career_profile_id === CurrentCareerProfile::resolve()->id, 404);

        if ($jobPosting->disposition !== JobDisposition::Pursued) {
            return redirect()->route('jobs.show', $jobPosting);
        }

        return Inertia::render('opportunities/show', $workspace->present($jobPosting));
    }

    /**
     * Stop pursuing: the job goes to Dismissed with the person's reason, through the same writer and history as every
     * other decision, so Undo, the Jobs page's Dismissed view and the Opportunities "existing work" list all work on it
     * unchanged. Nothing hangs off the disposition: resumes, analyses, inspections, preparations and recorded
     * applications are left exactly as they are. The job must still be pursued when the request is judged (under the
     * writer's lock), so a stale page or a repeated click cannot do anything else. The page only leaves for the list
     * once the server reports the change (or that the job was already stopped); a refusal stays on the page.
     */
    public function stop(StopPursuingRequest $request, JobPosting $jobPosting, RecordJobDecisionBatch $decisions): RedirectResponse
    {
        abort_unless($jobPosting->career_profile_id === CurrentCareerProfile::resolve()->id, 404);

        $result = $decisions->record([$jobPosting->id], JobDisposition::Dismissed, $request->reason(), $request->note(), JobDisposition::Pursued);
        // A refusal (the job is no longer pursued, or is not found) is shown inside the dialog, which stays open.
        $refusal = $result['failed'][0]['message'] ?? ($result['not_found'] !== [] ? 'That opportunity could not be found.' : null);

        if ($refusal !== null) {
            return back()->withErrors(['reason' => $refusal]);
        }

        $result['stopped_pursuing'] = ['company' => $jobPosting->company, 'title' => $jobPosting->title];
        Inertia::flash('decision_result', $result);

        return redirect()->route('opportunities.index')->with('decision_result', $result);
    }

    /**
     * The drawer's job on the list: only a pursued job of the current profile, else null (the drawer says unavailable).
     *
     * @return array<string, mixed>|null
     */
    private function inspectionJob(int $id, PresentInspectionJob $inspection): ?array
    {
        $job = CurrentCareerProfile::resolve()->jobPostings()->where('disposition', JobDisposition::Pursued->value)->find($id);

        return $job === null ? null : $inspection->present($job);
    }
}
