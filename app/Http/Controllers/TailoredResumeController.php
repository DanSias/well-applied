<?php

namespace App\Http\Controllers;

use App\Models\JobPosting;
use App\Models\ResumeProfileSynthesis;
use App\Support\CurrentCareerProfile;
use App\Support\JobFreshness\JobFreshness;
use App\Support\Navigation\ReturnTo;
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
        JobFreshness::abortUnlessNewWorkAllowed($jobPosting);

        $request->validate([
            'allow_incomplete_description' => ['sometimes', 'boolean'],
            'regenerate' => ['sometimes', 'boolean'],
            // Tailor from an approved profile (the standard pathway is used when this is absent).
            'resume_profile_synthesis_id' => ['sometimes', 'nullable', 'integer', 'exists:resume_profile_syntheses,id'],
            'return_to' => ['sometimes', 'nullable', ReturnTo::RULE],
        ]);

        $synthesis = $request->filled('resume_profile_synthesis_id')
            ? ResumeProfileSynthesis::query()->where('career_profile_id', CurrentCareerProfile::resolve()->id)->findOrFail($request->integer('resume_profile_synthesis_id'))
            : null;

        $pipeline->start(
            $jobPosting,
            $request->boolean('allow_incomplete_description'),
            $request->boolean('regenerate'),
            $synthesis,
        );

        return ReturnTo::redirect($request, $jobPosting) ?? redirect()->route('jobs.show', $jobPosting);
    }

    public function status(JobPosting $jobPosting, PresentTailoredResume $presenter): JsonResponse
    {
        return response()->json($presenter->present($jobPosting));
    }
}
