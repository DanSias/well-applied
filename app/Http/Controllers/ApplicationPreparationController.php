<?php

namespace App\Http\Controllers;

use App\Enums\JobDisposition;
use App\Models\Application;
use App\Models\JobPosting;
use App\Models\ResumeVariant;
use App\Support\ApplicationPreparation\DispatchApplicationPreparation;
use App\Support\ApplicationPreparation\PreparationException;
use App\Support\ApplicationPreparation\PresentApplicationPreparation;
use App\Support\CurrentCareerProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Prepare Application" for a pursued job (an Opportunity), and its read-only state. store() never runs a browser: it
 * freezes the attempt's inputs and queues the durable workflow (DispatchApplicationPreparation) for a preparer worker
 * to claim. Nothing here submits, records a Submission, or reads a worker result.
 *
 * Local-first: like the rest of the web app there is no login, so these routes are only as private as the network the
 * app is bound to (see docs/application-preparation.md "Security assumptions").
 */
class ApplicationPreparationController extends Controller
{
    public function store(Request $request, JobPosting $jobPosting, DispatchApplicationPreparation $dispatcher, PresentApplicationPreparation $presenter): JsonResponse
    {
        abort_unless($jobPosting->career_profile_id === CurrentCareerProfile::resolve()->id, 404);
        abort_unless($jobPosting->disposition === JobDisposition::Pursued, 422, 'Only a job you are pursuing can be prepared.');

        $data = $request->validate(['resume_variant_id' => ['nullable', 'integer']]);
        $variant = isset($data['resume_variant_id']) ? ResumeVariant::query()->whereKey((int) $data['resume_variant_id'])->first() : null;
        if (isset($data['resume_variant_id']) && $variant === null) {
            return response()->json(['error' => 'That resume does not exist.', 'reason' => 'resume_not_found'], 422);
        }

        try {
            ['workflowRun' => $run, 'created' => $created] = $dispatcher->dispatch($jobPosting, $variant);
        } catch (PreparationException $e) {
            return response()->json(['error' => $e->getMessage(), 'reason' => $e->reason], 422);
        }

        return response()->json([
            'created' => $created,
            'workflow_run_id' => $run->id,
            'preparation' => $presenter->present($run->application),
        ], $created ? 202 : 200);
    }

    public function show(Application $application, PresentApplicationPreparation $presenter): JsonResponse
    {
        abort_unless($application->jobPosting->career_profile_id === CurrentCareerProfile::resolve()->id, 404);

        return response()->json($presenter->present($application));
    }
}
