<?php

namespace App\Http\Controllers;

use App\Exceptions\ResumeRevisionException;
use App\Models\JobPosting;
use App\Models\ResumeVariant;
use App\Support\ResumeRevision\JobResumes;
use App\Support\ResumeRevision\ReplacementFacts;
use App\Support\ResumeRevision\ReviseResumeVariant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Edit, replace, remove and reorder the parts of a tailored resume and save the result as a new variant (copy on write),
 * and mark which resume of a job is the working copy or the final one. No model call is made anywhere in here.
 */
class ResumeRevisionController extends Controller
{
    public function store(Request $request, JobPosting $jobPosting, ResumeVariant $resumeVariant, ReviseResumeVariant $revise): RedirectResponse
    {
        $this->belongs($jobPosting, $resumeVariant);

        $draft = $request->validate([
            'summary' => ['required', 'string', 'max:2000'],
            'roles' => ['required', 'array'],
            'roles.*.role_id' => ['required', 'integer'],
            'roles.*.bullets' => ['required', 'array'],
            'roles.*.bullets.*.id' => ['nullable', 'integer'],
            'roles.*.bullets.*.fact_key' => ['nullable', 'string'],
            'roles.*.bullets.*.replaces' => ['nullable', 'integer'],
            'roles.*.bullets.*.text' => ['required', 'string', 'max:2000'],
            'skills' => ['nullable', 'array'],
            'skills.*' => ['integer'],
        ]);

        try {
            $revision = $revise->revise($jobPosting, $resumeVariant, $draft);
        } catch (ResumeRevisionException $e) {
            return back()->withErrors($e->errors);
        }

        return redirect()->route('jobs.profile-resumes.show', [$jobPosting, $revision]);
    }

    public function facts(Request $request, JobPosting $jobPosting, ResumeVariant $resumeVariant, ReplacementFacts $replacements): JsonResponse
    {
        $this->belongs($jobPosting, $resumeVariant);
        $data = $request->validate(['role_id' => ['required', 'integer'], 'q' => ['nullable', 'string', 'max:100']]);

        return response()->json(['facts' => $replacements->forRole($jobPosting, $resumeVariant, (int) $data['role_id'], (string) ($data['q'] ?? ''))]);
    }

    public function current(JobPosting $jobPosting, ResumeVariant $resumeVariant, JobResumes $resumes): RedirectResponse
    {
        $this->belongs($jobPosting, $resumeVariant);
        $resumes->setCurrent($jobPosting, $resumeVariant);

        return back();
    }

    public function preferred(Request $request, JobPosting $jobPosting, ResumeVariant $resumeVariant, JobResumes $resumes): RedirectResponse
    {
        $this->belongs($jobPosting, $resumeVariant);
        $resumes->setPreferred($jobPosting, $request->boolean('preferred') ? $resumeVariant : null);

        return back();
    }

    private function belongs(JobPosting $posting, ResumeVariant $variant): void
    {
        abort_unless($variant->jobAnalysis !== null && $variant->jobAnalysis->job_posting_id === $posting->id && $variant->resume_profile_synthesis_id !== null, 404);
    }
}
