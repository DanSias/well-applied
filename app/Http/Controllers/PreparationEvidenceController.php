<?php

namespace App\Http\Controllers;

use App\Models\AgentRun;
use App\Support\ApplicationPreparation\ClaimNextPreparationRun;
use App\Support\ApplicationPreparation\PreparationArtifacts;
use App\Support\ApplicationPreparation\PresentPreparationReview;
use App\Support\CurrentCareerProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Serves a preparation attempt's private evidence to the person who owns it: the review of its report, and its
 * screenshots. Nothing is read from a path the browser supplies: the attempt is a route-bound AgentRun that must be a
 * preparation of the current profile's job, a screenshot is named by the worker's own strict pattern and read from that
 * attempt's own directory only, and the report is read through PreparationArtifacts (hash-checked).
 *
 * Local-first: like the rest of the web app there is no login, so these routes are exactly as private as the network the
 * app is bound to. They carry applicant-adjacent data (screenshots show what was typed into the form) and MUST NOT be
 * exposed on a network without authentication. See docs/application-preparation.md "Security assumptions".
 */
class PreparationEvidenceController extends Controller
{
    public function show(AgentRun $agentRun, PresentPreparationReview $review): JsonResponse
    {
        $this->authorizeAttempt($agentRun);

        return response()->json($review->present($agentRun), 200, ['Cache-Control' => 'private, no-store']);
    }

    public function screenshot(AgentRun $agentRun, string $name, PreparationArtifacts $artifacts): Response
    {
        $this->authorizeAttempt($agentRun);
        abort_unless(PreparationArtifacts::isScreenshotName($name), 404);

        $bytes = $artifacts->screenshot($agentRun, $name);
        abort_if($bytes === null, 404);

        return response($bytes, 200, [
            'Content-Type' => 'image/png',
            'Content-Disposition' => 'inline; filename="'.$name.'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function authorizeAttempt(AgentRun $agentRun): void
    {
        abort_unless($agentRun->agent_type === ClaimNextPreparationRun::AGENT_TYPE, 404);

        $job = $agentRun->workflowStep->workflowRun->application->jobPosting;
        abort_unless($job->career_profile_id === CurrentCareerProfile::resolve()->id, 404);
    }
}
