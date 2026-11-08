<?php

namespace App\Http\Controllers;

use App\Jobs\RunReadinessReviewJob;
use App\Models\JobPosting;
use App\Models\ResumeVariant;
use App\Support\ReadinessReview\RunReadinessReview;
use Illuminate\Http\RedirectResponse;

/**
 * Starts an Application Readiness Review of one saved resume, only when asked. It is never run by an edit or a save.
 */
class ReadinessReviewController extends Controller
{
    public function store(JobPosting $jobPosting, ResumeVariant $resumeVariant, RunReadinessReview $reviews): RedirectResponse
    {
        abort_unless($resumeVariant->jobAnalysis !== null && $resumeVariant->jobAnalysis->job_posting_id === $jobPosting->id && $resumeVariant->resume_profile_synthesis_id !== null, 404);

        $review = $reviews->request($resumeVariant);

        if ($review->status === 'queued' && $review->wasRecentlyCreated) {
            RunReadinessReviewJob::dispatch($review->id);
        }

        return back();
    }
}
