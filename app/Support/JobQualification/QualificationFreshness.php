<?php

namespace App\Support\JobQualification;

use App\Models\CareerProfile;
use App\Models\JobPosting;
use App\Models\JobQualification;
use App\Support\JobQualification\Prompts\JobQualificationPromptV1;

/**
 * Whether a stored qualification still describes the job as it is now. It does when it was made from the same posting
 * content, the same candidate evidence and the same policy (and, if a model was asked, the same prompt). Anything else
 * leaves it in history as out of date and a new one supersedes it; nothing is flagged or invalidated in place.
 */
final class QualificationFreshness
{
    public function __construct(
        private readonly CandidateCapabilitySummary $capabilities,
        private readonly JobQualificationPromptV1 $prompt,
    ) {}

    /**
     * What a qualification made right now would be made from.
     *
     * @return array{content: string, profile: string, summary: array{skills: list<array<string, mixed>>, roles: list<array<string, mixed>>, clearance_evidence: bool}}
     */
    public function inputs(JobPosting $job): array
    {
        $summary = $this->capabilities->build(CareerProfile::query()->findOrFail($job->career_profile_id));

        return [
            'content' => JobContentFingerprint::for($job),
            'profile' => CandidateCapabilitySummary::fingerprint($summary),
            'summary' => $summary,
        ];
    }

    public function latest(JobPosting $job): ?JobQualification
    {
        return JobQualification::query()->where('job_posting_id', $job->id)->orderByDesc('id')->first();
    }

    public function isFresh(JobQualification $qualification, string $contentFingerprint, string $profileFingerprint): bool
    {
        return $qualification->content_fingerprint === $contentFingerprint
            && $qualification->profile_fingerprint === $profileFingerprint
            && $qualification->policy_version === QualificationPolicy::VERSION
            && ($qualification->prompt_version === null || $qualification->prompt_version === $this->prompt->version());
    }

    /** The latest qualification, only if it is still fresh. */
    public function current(JobPosting $job): ?JobQualification
    {
        $latest = $this->latest($job);

        if ($latest === null) {
            return null;
        }

        $inputs = $this->inputs($job);

        return $this->isFresh($latest, $inputs['content'], $inputs['profile']) ? $latest : null;
    }
}
