<?php

namespace App\Http\Controllers;

use App\Enums\GenerationType;
use App\Models\JobPosting;
use App\Support\CurrentCareerProfile;
use App\Support\GenerationAttempt\QueueGenerationAttempt;
use App\Support\JobQualification\QualificationFreshness;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * The manual "Qualify" action for one job. It only asks for a qualification: the claim, the queued attempt and the job
 * are QueueGenerationAttempt's and GenerateJobQualificationJob's, exactly as for analysis. It never changes a job's
 * disposition and never queues analysis or matching.
 */
class JobQualificationController extends Controller
{
    public function store(Request $request, JobPosting $jobPosting, QueueGenerationAttempt $claims, QualificationFreshness $freshness): RedirectResponse
    {
        // A job of another profile is indistinguishable from one that does not exist.
        abort_unless($jobPosting->career_profile_id === CurrentCareerProfile::resolve()->id, 404);

        $refresh = $request->boolean('refresh');

        // An unchanged job already has a valid assessment; only an explicit refresh spends another model call.
        if (! $refresh && $freshness->current($jobPosting) !== null) {
            Inertia::flash('qualification_result', ['outcome' => 'up_to_date']);

            return back();
        }

        $attempt = $claims->claim(GenerationType::JobQualification, $jobPosting, regenerate: $refresh);

        Inertia::flash('qualification_result', ['outcome' => $attempt === null ? 'in_flight' : 'started']);

        return back();
    }
}
