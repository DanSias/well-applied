<?php

namespace App\Support\Opportunities;

use App\Models\JobAnalysis;
use App\Models\JobPosting;
use App\Support\TailoredResume\PresentTailoredResume;

/**
 * The Opportunity workspace's data: a composition of what already exists for one pursued job, with no state or rules of
 * its own. The row (resume state, stage, inspection summary, the derived next step) comes from PresentOpportunities, so
 * the workspace and the list always agree; the resume panel's status from PresentTailoredResume; the inspection drawer's
 * data from PresentInspectionJob. The only thing built here is a short pointer to the latest analysis and match, which
 * link to their own pages rather than repeat them.
 */
final class PresentOpportunityWorkspace
{
    public function __construct(
        private readonly PresentOpportunities $opportunities,
        private readonly PresentTailoredResume $tailoredResume,
        private readonly PresentInspectionJob $inspectionJob,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function present(JobPosting $job): array
    {
        return [
            'opportunity' => $this->opportunities->forJob($job) + [
                'description' => $job->description,
                'description_completeness' => $job->description_completeness->value,
                'captured_at' => $job->created_at?->toDateString(),
            ],
            'tailored_resume' => $this->tailoredResume->present($job),
            'resume_profiles' => $this->tailoredResume->profiles($job),
            'analysis' => $this->analysis($job),
            'inspection_job' => $this->inspectionJob->present($job),
        ];
    }

    /**
     * The latest analysis, and the latest match on it when there is one (a profile-assisted resume needs no match).
     *
     * @return array<string, mixed>|null
     */
    private function analysis(JobPosting $job): ?array
    {
        /** @var JobAnalysis|null $analysis */
        $analysis = $job->jobAnalyses()->withCount('findings')->latest('generated_at')->latest('id')->first();

        if ($analysis === null) {
            return null;
        }

        $match = $analysis->jobMatches()->withCount('findings')->latest('generated_at')->latest('id')->first();

        return [
            'id' => $analysis->id,
            'generated_at' => $analysis->generated_at->toIso8601String(),
            'seniority' => $analysis->overall_seniority?->value,
            'role_summary' => $analysis->role_summary,
            'findings_count' => $analysis->findings_count,
            'match' => $match === null ? null : [
                'id' => $match->id,
                'generated_at' => $match->generated_at->toIso8601String(),
                'findings_count' => $match->findings_count,
            ],
        ];
    }
}
