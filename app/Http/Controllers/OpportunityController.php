<?php

namespace App\Http\Controllers;

use App\Enums\JobDisposition;
use App\Support\ApplicationInspection\PresentApplicationInspection;
use App\Support\CurrentCareerProfile;
use App\Support\Opportunities\PresentOpportunities;
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
    public function index(Request $request, PresentOpportunities $opportunities, PresentApplicationInspection $inspection): Response
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
     * What the inspection drawer shows for one pursued job: the job, and its Application's inspection state when one
     * exists. Null when the job is not one of the current profile's pursued jobs (the drawer says it is unavailable).
     *
     * @return array<string, mixed>|null
     */
    private function inspectionJob(int $id, PresentApplicationInspection $inspection): ?array
    {
        $job = CurrentCareerProfile::resolve()->jobPostings()
            ->where('disposition', JobDisposition::Pursued->value)
            ->with('applications')
            ->find($id);

        if ($job === null) {
            return null;
        }

        $application = $job->applications->first();

        return [
            'id' => $job->id,
            'title' => $job->title,
            'company' => $job->company,
            'source_url' => $job->source_url,
            'application_id' => $application?->id,
            'inspection' => $application !== null ? $inspection->present($application) : null,
        ];
    }
}
