<?php

namespace App\Support\JobDecisions;

use App\Models\Application;
use App\Models\ClarificationSession;
use App\Models\JobAnalysis;
use App\Models\JobPosting;
use App\Models\JobResumeSelection;
use App\Models\ResumeVariant;
use App\Support\CurrentCareerProfile;

/**
 * Read-only: which jobs already have resume or application work, so they can be reviewed and classified deliberately.
 * Nothing here changes a job, a resume, an analysis, a match or an application.
 */
final class ReportJobsWithWork
{
    /**
     * @return list<array<string, mixed>>
     */
    public function report(): array
    {
        $jobs = CurrentCareerProfile::resolve()->jobPostings()->get(['id', 'company', 'title', 'disposition', 'source_url'])->keyBy('id');
        $ids = $jobs->keys()->all();

        $variants = ResumeVariant::query()
            ->whereIn('job_analysis_id', JobAnalysis::query()->whereIn('job_posting_id', $ids)->select('id'))
            ->with('jobAnalysis:id,job_posting_id')->get(['id', 'job_analysis_id', 'resume_profile_synthesis_id'])
            ->groupBy(fn (ResumeVariant $v) => $v->jobAnalysis->job_posting_id);
        $selections = JobResumeSelection::query()->whereIn('job_posting_id', $ids)->get()->keyBy('job_posting_id');
        $applications = Application::query()->whereIn('job_posting_id', $ids)->get()->groupBy('job_posting_id');
        $sessions = ClarificationSession::query()->whereIn('job_posting_id', $ids)->get()->groupBy('job_posting_id');

        $rows = [];

        foreach ($jobs as $id => $job) {
            if (! $variants->has($id) && ! $selections->has($id) && ! $applications->has($id) && ! $sessions->has($id)) {
                continue;
            }

            /** @var JobPosting $job */
            $selection = $selections->get($id);
            $rows[] = [
                'job_id' => $id,
                'title' => $job->title,
                'company' => $job->company,
                'disposition' => $job->disposition->value,
                'resume_variants' => $variants->get($id)?->count() ?? 0,
                'current_resume_variant_id' => $selection?->current_resume_variant_id,
                'final_resume_variant_id' => $selection?->preferred_resume_variant_id,
                'applications' => ($applications->get($id) ?? collect())->map(fn (Application $a) => ['id' => $a->id, 'status' => $a->status->value])->values()->all(),
                'clarification_sessions' => $sessions->get($id)?->count() ?? 0,
            ];
        }

        return $rows;
    }
}
