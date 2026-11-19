<?php

namespace App\Http\Controllers;

use App\Http\Requests\StartInspectionRequest;
use App\Models\Application;
use App\Models\JobPosting;
use App\Support\ApplicationInspection\DispatchApplicationInspection;
use App\Support\ApplicationInspection\InspectionAlreadyRunning;
use App\Support\ApplicationInspection\PresentApplicationInspection;
use App\Support\Navigation\JobHome;
use App\Support\Navigation\ReturnTo;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * "Inspect Application" and its read-only results page — see
 * docs/application-inspector.md. store() never runs an inspection
 * itself; it only dispatches the durable workflow (see
 * App\Support\ApplicationInspection\DispatchApplicationInspection) and
 * redirects, mirroring JobAnalysisController::store()'s existing
 * thin-controller convention exactly.
 */
class ApplicationController extends Controller
{
    public function store(StartInspectionRequest $request, JobPosting $jobPosting, DispatchApplicationInspection $dispatcher): RedirectResponse
    {
        try {
            $workflowRun = $dispatcher->dispatch($jobPosting, $request->applicationUrl(), $request->clearsApplicationUrl(), $request->onlyIfNone());
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        } catch (InspectionAlreadyRunning $e) {
            abort(409, $e->getMessage());
        }

        // The Opportunities inspection drawer starts an inspection without leaving the page: it asks to come back to
        // itself, with the drawer still open on this job. Everywhere else keeps going to the results page.
        if ($request->input('return_to') === ReturnTo::OPPORTUNITY) {
            return redirect()->route('opportunities.show', $jobPosting);
        }

        if ($request->input('return_to') === 'opportunities') {
            $resume = in_array($request->input('resume'), ['none', 'in-progress', 'final'], true) ? $request->input('resume') : null;

            return redirect()->route('opportunities.index', array_filter(['resume' => $resume, 'inspect' => $jobPosting->id]));
        }

        return redirect()->route('applications.show', $workflowRun->application);
    }

    public function show(Application $application, PresentApplicationInspection $presenter): Response
    {
        $application->load('jobPosting');

        return Inertia::render('applications/show', [
            'application' => [
                'id' => $application->id,
                'job_posting' => [
                    'id' => $application->jobPosting->id,
                    'company' => $application->jobPosting->company,
                    'title' => $application->jobPosting->title,
                    'source_url' => $application->jobPosting->source_url,
                    'home_url' => JobHome::url($application->jobPosting),
                ],
            ],
            'inspection' => $presenter->present($application),
        ]);
    }
}
