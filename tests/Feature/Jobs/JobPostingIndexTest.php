<?php

use App\Enums\AgentRunStatus;
use App\Enums\GenerationStatus;
use App\Enums\GenerationType;
use App\Enums\JobDiscoverySource;
use App\Enums\JobMatchCoverage;
use App\Enums\JobRemoteStatus;
use App\Enums\WorkflowStatus;
use App\Models\AgentRun;
use App\Models\Application;
use App\Models\CareerProfile;
use App\Models\GenerationAttempt;
use App\Models\JobAnalysis;
use App\Models\JobAnalysisFinding;
use App\Models\JobMatch;
use App\Models\JobMatchFinding;
use App\Models\JobPosting;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Deterministic seeds for the triage summary rows the index derives from
 * the same durable models the detail pages read — snapshots (never
 * lifecycle timestamps) plus zero-filled coverage buckets. Named with an
 * `index` prefix so they cannot collide with the other test files'
 * globals if both load in one process.
 */
function indexTriageAnalysis(JobPosting $jobPosting, string $generatedAt): JobAnalysis
{
    return JobAnalysis::factory()->for($jobPosting, 'jobPosting')->create([
        'generated_at' => $generatedAt,
    ]);
}

function indexTriageMatch(CareerProfile $profile, JobAnalysis $analysis, string $generatedAt): JobMatch
{
    return JobMatch::factory()->create([
        'job_analysis_id' => $analysis->id,
        'career_profile_id' => $profile->id,
        'generated_at' => $generatedAt,
    ]);
}

function indexTriageMatchFinding(JobMatch $match, JobAnalysis $analysis, JobMatchCoverage $coverage): JobMatchFinding
{
    $finding = JobAnalysisFinding::factory()->for($analysis, 'jobAnalysis')->create();

    return JobMatchFinding::factory()
        ->for($match, 'jobMatch')
        ->for($finding, 'jobAnalysisFinding')
        ->create(['coverage' => $coverage]);
}

function indexTriageAttempt(JobPosting $jobPosting, GenerationType $generationType, GenerationStatus $status): GenerationAttempt
{
    return GenerationAttempt::factory()->create([
        'subject_type' => $jobPosting->getMorphClass(),
        'subject_id' => $jobPosting->id,
        'generation_type' => $generationType,
        'status' => $status,
    ]);
}

it('renders the jobs index successfully', function () {
    CareerProfile::factory()->create();

    $this->get(route('jobs.index'))->assertOk();
});

it('shows the empty state cleanly when no jobs have been captured', function () {
    CareerProfile::factory()->create();

    $this->get(route('jobs.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('jobs/index')->where('jobs', []));
});

it('defaults filters to an empty search and the all status tab', function () {
    CareerProfile::factory()->create();

    $this->get(route('jobs.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.q', '')
            ->where('filters.status', 'all')
        );
});

it('lists jobs newest first', function () {
    $profile = CareerProfile::factory()->create();
    $older = JobPosting::factory()->for($profile)->create(['title' => 'Older Job', 'created_at' => now()->subDays(2)]);
    $newer = JobPosting::factory()->for($profile)->create(['title' => 'Newer Job', 'created_at' => now()]);

    $this->get(route('jobs.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('jobs', 2)
            ->where('jobs.0.id', $newer->id)
            ->where('jobs.1.id', $older->id)
        );
});

it('exposes only summary fields on the index, not the full description', function () {
    $profile = CareerProfile::factory()->create();
    JobPosting::factory()->for($profile)->create([
        'company' => 'Acme',
        'title' => 'Engineer',
        'location' => 'Remote',
        'source_url' => 'https://acme.example/careers/engineer',
    ]);

    $this->get(route('jobs.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('jobs.0.company', 'Acme')
            ->where('jobs.0.title', 'Engineer')
            ->where('jobs.0.location', 'Remote')
            ->where('jobs.0.source_url', 'https://acme.example/careers/engineer')
            ->where('jobs.0.has_source_url', true)
            ->missing('jobs.0.description')
        );
});

it('exposes a not_inspected inspection summary for a JobPosting with no Application', function () {
    $profile = CareerProfile::factory()->create();
    JobPosting::factory()->for($profile)->create();

    $this->get(route('jobs.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('jobs.0.inspection.state', 'not_inspected')
            ->where('jobs.0.inspection.application_id', null)
        );
});

it('exposes an inspected summary with a field count once an inspection has succeeded', function () {
    $profile = CareerProfile::factory()->create();
    $job = JobPosting::factory()->for($profile)->create();
    $application = Application::factory()->for($job, 'jobPosting')->create();
    $workflowRun = WorkflowRun::factory()->for($application)->create(['status' => WorkflowStatus::Succeeded]);
    $step = WorkflowStep::factory()->for($workflowRun, 'workflowRun')->create(['status' => WorkflowStatus::Succeeded]);
    AgentRun::factory()->for($step, 'workflowStep')->create([
        'status' => AgentRunStatus::Succeeded,
        'inspection_outcome' => 'complete',
    ]);

    $this->get(route('jobs.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('jobs.0.inspection.state', 'inspected')
            ->where('jobs.0.inspection.application_id', $application->id)
        );
});

it('filters by title/company search text', function () {
    $profile = CareerProfile::factory()->create();
    JobPosting::factory()->for($profile)->create(['company' => 'Anthropic', 'title' => 'Account Executive']);
    JobPosting::factory()->for($profile)->create(['company' => 'Initech', 'title' => 'TPS Report Auditor']);

    $this->get(route('jobs.index', ['q' => 'anthropic']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('jobs', 1)
            ->where('jobs.0.company', 'Anthropic')
            ->where('filters.q', 'anthropic')
        );
});

it('filters to only not_inspected opportunities on the not_inspected status tab', function () {
    $profile = CareerProfile::factory()->create();
    $uninspected = JobPosting::factory()->for($profile)->create();
    $inspectedJob = JobPosting::factory()->for($profile)->create();
    $application = Application::factory()->for($inspectedJob, 'jobPosting')->create();
    $workflowRun = WorkflowRun::factory()->for($application)->create(['status' => WorkflowStatus::Succeeded]);
    $step = WorkflowStep::factory()->for($workflowRun, 'workflowRun')->create(['status' => WorkflowStatus::Succeeded]);
    AgentRun::factory()->for($step, 'workflowStep')->create(['status' => AgentRunStatus::Succeeded, 'inspection_outcome' => 'complete']);

    $this->get(route('jobs.index', ['status' => 'not_inspected']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('jobs', 1)
            ->where('jobs.0.id', $uninspected->id)
        );
});

it('exposes discovery.source manual for a hand-created JobPosting', function () {
    $profile = CareerProfile::factory()->create();
    JobPosting::factory()->for($profile)->create();

    $this->get(route('jobs.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('jobs.0.discovery.source', 'manual')
            ->where('jobs.0.discovery.discovered_at', null)
        );
});

it('exposes discovery metadata for a discovered JobPosting', function () {
    $profile = CareerProfile::factory()->create();
    JobPosting::factory()->for($profile)->discovered(JobDiscoverySource::Himalayas)->create([
        'remote_status' => JobRemoteStatus::Remote,
        'compensation_min' => 150000,
        'compensation_max' => 190000,
        'compensation_currency' => 'USD',
    ]);

    $this->get(route('jobs.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('jobs.0.discovery.source', 'himalayas')
            ->where('jobs.0.discovery.remote_status', 'remote')
            ->where('jobs.0.discovery.compensation_min', 150000)
            ->where('jobs.0.discovery.compensation_max', 190000)
        );
});

it('exposes a raw triage summary for a JobPosting with no analysis or match activity', function () {
    $profile = CareerProfile::factory()->create();
    JobPosting::factory()->for($profile)->create();

    $this->get(route('jobs.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('jobs.0.triage.stage', 'raw')
            ->where('jobs.0.triage.coverage', null)
            ->where('jobs.0.triage.analysis_at', null)
            ->where('jobs.0.triage.match_at', null)
        );
});

it('creates no analysis, match, or generation side effects while rendering the index', function () {
    $profile = CareerProfile::factory()->create();
    $job = JobPosting::factory()->for($profile)->create();
    $analysisBefore = JobAnalysis::count();

    $this->get(route('jobs.index'))
        ->assertOk();

    expect(JobAnalysis::count())->toBe($analysisBefore)
        ->and(JobMatch::count())->toBe(0)
        ->and(JobMatchFinding::count())->toBe(0)
        ->and(GenerationAttempt::count())->toBe(0)
        ->and($job->fresh() !== null)->toBe(true);
});

it('exposes an analyzed triage summary with the selected analysis snapshot timestamp', function () {
    $profile = CareerProfile::factory()->create();
    $job = JobPosting::factory()->for($profile)->create();
    indexTriageAnalysis($job, '2026-09-01 08:00:00');

    $this->get(route('jobs.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('jobs.0.triage.stage', 'analyzed')
            ->where('jobs.0.triage.coverage', null)
            ->where('jobs.0.triage.analysis_at', '2026-09-01 08:00:00')
            ->where('jobs.0.triage.match_at', null)
        );
});

it('exposes an analyzing triage summary while a job_analysis attempt is in flight', function () {
    $profile = CareerProfile::factory()->create();
    $job = JobPosting::factory()->for($profile)->create();
    indexTriageAttempt($job, GenerationType::JobAnalysis, GenerationStatus::Queued);

    $this->get(route('jobs.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('jobs.0.triage.stage', 'analyzing')
            ->where('jobs.0.triage.coverage', null)
            ->where('jobs.0.triage.analysis_at', null)
            ->where('jobs.0.triage.match_at', null)
        );
});

it('exposes a matched triage summary with zero-filled coverage buckets and snapshot timestamps', function () {
    $profile = CareerProfile::factory()->create();
    $job = JobPosting::factory()->for($profile)->create([
        'source_url' => 'https://acme.example/careers/engineer',
    ]);
    $analysis = indexTriageAnalysis($job, '2026-09-01 08:00:00');
    $match = indexTriageMatch($profile, $analysis, '2026-09-02 08:00:00');

    indexTriageMatchFinding($match, $analysis, JobMatchCoverage::Supported);
    indexTriageMatchFinding($match, $analysis, JobMatchCoverage::Partial);
    indexTriageMatchFinding($match, $analysis, JobMatchCoverage::NoEvidence);
    indexTriageMatchFinding($match, $analysis, JobMatchCoverage::NotAssessable);

    $this->get(route('jobs.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('jobs.0.triage.stage', 'matched')
            ->where('jobs.0.triage.coverage.supported', 1)
            ->where('jobs.0.triage.coverage.partial', 1)
            ->where('jobs.0.triage.coverage.no_evidence', 1)
            ->where('jobs.0.triage.coverage.not_assessable', 1)
            ->where('jobs.0.triage.analysis_at', '2026-09-01 08:00:00')
            ->where('jobs.0.triage.match_at', '2026-09-02 08:00:00')
            ->where('jobs.0.source_url', 'https://acme.example/careers/engineer')
            ->where('jobs.0.has_source_url', true)
            ->where('jobs.0.inspection.state', 'not_inspected')
        );
});

it('attaches each posting its own triage summary across a mixed result set', function () {
    $profile = CareerProfile::factory()->create();

    $raw = JobPosting::factory()->for($profile)->create(['title' => 'Raw Role', 'created_at' => now()->subDays(3)]);

    $analyzed = JobPosting::factory()->for($profile)->create(['title' => 'Analyzed Role', 'created_at' => now()->subDays(2)]);
    $analyzedAnalysis = indexTriageAnalysis($analyzed, '2026-09-01 08:00:00');

    $matched = JobPosting::factory()->for($profile)->create(['title' => 'Matched Role', 'created_at' => now()->subDays(1)]);
    $matchedAnalysis = indexTriageAnalysis($matched, '2026-09-01 08:00:00');
    $match = indexTriageMatch($profile, $matchedAnalysis, '2026-09-02 08:00:00');
    indexTriageMatchFinding($match, $matchedAnalysis, JobMatchCoverage::Supported);

    $this->get(route('jobs.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('jobs', 3)
            ->where('jobs.0.id', $matched->id)
            ->where('jobs.0.triage.stage', 'matched')
            ->where('jobs.0.triage.coverage.supported', 1)
            ->where('jobs.0.triage.coverage.partial', 0)
            ->where('jobs.0.triage.coverage.no_evidence', 0)
            ->where('jobs.0.triage.coverage.not_assessable', 0)
            ->where('jobs.0.triage.analysis_at', '2026-09-01 08:00:00')
            ->where('jobs.0.triage.match_at', '2026-09-02 08:00:00')
            ->where('jobs.1.id', $analyzed->id)
            ->where('jobs.1.triage.stage', 'analyzed')
            ->where('jobs.1.triage.coverage', null)
            ->where('jobs.1.triage.analysis_at', '2026-09-01 08:00:00')
            ->where('jobs.1.triage.match_at', null)
            ->where('jobs.2.id', $raw->id)
            ->where('jobs.2.triage.stage', 'raw')
            ->where('jobs.2.triage.coverage', null)
            ->where('jobs.2.triage.analysis_at', null)
            ->where('jobs.2.triage.match_at', null)
        );
});

it('keeps triage summaries scoped to the current CareerProfile', function () {
    $current = CareerProfile::factory()->create(); // created first — resolves as current
    $other = CareerProfile::factory()->create();

    $job = JobPosting::factory()->for($current)->create();
    $analysis = indexTriageAnalysis($job, '2026-09-01 08:00:00');
    // The completed match belongs to the other profile — it must not
    // surface in the current profile's triage summary.
    $foreignMatch = indexTriageMatch($other, $analysis, '2026-09-02 08:00:00');
    indexTriageMatchFinding($foreignMatch, $analysis, JobMatchCoverage::Supported);
    // A posting owned by the other profile is not in this index at all.
    JobPosting::factory()->for($other)->create(['title' => "Other profile's posting"]);

    $this->get(route('jobs.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('jobs', 1)
            ->where('jobs.0.id', $job->id)
            ->where('jobs.0.triage.stage', 'analyzed')
            ->where('jobs.0.triage.coverage', null)
            ->where('jobs.0.triage.match_at', null)
        );
});
