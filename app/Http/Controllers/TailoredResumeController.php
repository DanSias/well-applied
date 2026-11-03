<?php

namespace App\Http\Controllers;

use App\Models\JobPosting;
use App\Support\TailoredResume\PresentTailoredResume;
use App\Support\TailoredResume\TailoredResumePipeline;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The one-click "Generate Tailored Resume" action and its unified
 * status. store() only starts (or resumes) the durable pipeline and
 * returns; it never runs a stage, mirroring the other generation
 * controllers. Starting again is safe at any time: valid stages are
 * reused, an in-flight run is joined, and a failed run continues from
 * the failed stage. See App\Support\TailoredResume\TailoredResumePipeline.
 */
class TailoredResumeController extends Controller
{
    public function store(Request $request, JobPosting $jobPosting, TailoredResumePipeline $pipeline): RedirectResponse
    {
        $request->validate([
            'allow_incomplete_description' => ['sometimes', 'boolean'],
            'regenerate' => ['sometimes', 'boolean'],
        ]);

        $pipeline->start(
            $jobPosting,
            $request->boolean('allow_incomplete_description'),
            $request->boolean('regenerate'),
        );

        return redirect()->route('jobs.show', $jobPosting);
    }

    public function status(JobPosting $jobPosting, PresentTailoredResume $presenter): JsonResponse
    {
        return response()->json($presenter->present($jobPosting));
    }
}
