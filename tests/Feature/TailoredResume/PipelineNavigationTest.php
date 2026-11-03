<?php

use App\Enums\GenerationStatus;
use App\Enums\GenerationType;
use App\Models\GenerationAttempt;
use App\Models\JobAnalysis;
use App\Models\JobMatch;
use App\Models\JobPosting;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\JobMatchFixtures;

/**
 * The one-click pipeline reports progress on the job page. The legacy
 * per-stage pages poll attempts and follow result_url; they must ignore
 * pipeline-owned (chain) attempts so the user is never moved off the
 * job page, while manual attempts behave exactly as before.
 */
function pipelineAttempt(string $subjectType, int $subjectId, GenerationType $type, GenerationStatus $status, bool $chain, array $extra = []): GenerationAttempt
{
    return GenerationAttempt::factory()->create([
        'subject_type' => $subjectType,
        'subject_id' => $subjectId,
        'generation_type' => $type,
        'status' => $status,
        'chain' => $chain,
        'pipeline_run_id' => $chain ? 'run-1' : null,
        ...$extra,
    ]);
}

it('gives no result_url for a succeeded pipeline-owned attempt but keeps it for a manual one', function (GenerationType $type) {
    $job = JobPosting::factory()->create();
    $analysis = JobAnalysis::factory()->create(['job_posting_id' => $job->id]);
    ['profile' => $profile] = JobMatchFixtures::candidate();
    $match = JobMatch::factory()->create(['job_analysis_id' => $analysis->id, 'career_profile_id' => $profile->id]);

    [$subjectType, $subjectId, $resultId] = match ($type) {
        GenerationType::JobAnalysis => [(new JobPosting)->getMorphClass(), $job->id, $analysis->id],
        default => [(new JobAnalysis)->getMorphClass(), $analysis->id, $match->id],
    };

    $owned = pipelineAttempt($subjectType, $subjectId, $type, GenerationStatus::Succeeded, true, ['result_id' => $resultId]);
    $manual = pipelineAttempt($subjectType, $subjectId, $type, GenerationStatus::Succeeded, false, ['result_id' => $resultId]);

    $this->getJson(route('generation-attempts.show', $owned))->assertOk()->assertJson(['status' => 'succeeded', 'result_url' => null]);
    expect($this->getJson(route('generation-attempts.show', $manual))->json('result_url'))->toBeString();
})->with([GenerationType::JobAnalysis, GenerationType::JobMatch]);

it('does not hand a pipeline-owned analysis attempt to the legacy job page polling', function () {
    $job = JobPosting::factory()->create();
    pipelineAttempt((new JobPosting)->getMorphClass(), $job->id, GenerationType::JobAnalysis, GenerationStatus::Running, true);

    $this->get(route('jobs.show', $job))
        ->assertInertia(fn (Assert $page) => $page->where('job.latest_job_analysis_attempt', null));
});

it('still hands a manual analysis attempt to the legacy job page polling', function () {
    $job = JobPosting::factory()->create();
    $attempt = pipelineAttempt((new JobPosting)->getMorphClass(), $job->id, GenerationType::JobAnalysis, GenerationStatus::Running, false);

    $this->get(route('jobs.show', $job))
        ->assertInertia(fn (Assert $page) => $page->where('job.latest_job_analysis_attempt.id', $attempt->id));
});

it('does not hand pipeline-owned match or resume attempts to the legacy analysis and match pages', function () {
    ['profile' => $profile] = JobMatchFixtures::candidate();
    $job = JobPosting::factory()->create();
    $analysis = JobAnalysis::factory()->create(['job_posting_id' => $job->id]);
    $match = JobMatch::factory()->create(['job_analysis_id' => $analysis->id, 'career_profile_id' => $profile->id]);

    pipelineAttempt((new JobAnalysis)->getMorphClass(), $analysis->id, GenerationType::JobMatch, GenerationStatus::Failed, true, ['failure_message' => 'x']);
    pipelineAttempt((new JobMatch)->getMorphClass(), $match->id, GenerationType::ResumeVariant, GenerationStatus::Failed, true, ['failure_message' => 'Resume Wording provider returned no final content after 3 attempts.']);

    $this->get(route('jobs.analyses.show', [$job, $analysis]))
        ->assertInertia(fn (Assert $page) => $page->where('analysis.latest_job_match_attempt', null));
    $this->get(route('jobs.analyses.matches.show', [$job, $analysis, $match]))
        ->assertInertia(fn (Assert $page) => $page->where('match.latest_resume_attempt', null));
});

it('still shows a manual failed resume attempt on the legacy match page', function () {
    ['profile' => $profile] = JobMatchFixtures::candidate();
    $job = JobPosting::factory()->create();
    $analysis = JobAnalysis::factory()->create(['job_posting_id' => $job->id]);
    $match = JobMatch::factory()->create(['job_analysis_id' => $analysis->id, 'career_profile_id' => $profile->id]);
    $attempt = pipelineAttempt((new JobMatch)->getMorphClass(), $match->id, GenerationType::ResumeVariant, GenerationStatus::Failed, false, ['failure_message' => 'boom']);

    $this->get(route('jobs.analyses.matches.show', [$job, $analysis, $match]))
        ->assertInertia(fn (Assert $page) => $page->where('match.latest_resume_attempt.id', $attempt->id));
});
