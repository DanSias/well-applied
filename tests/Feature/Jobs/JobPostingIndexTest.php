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
use Illuminate\Support\Facades\DB;
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

function indexTriageMatchAttempt(JobAnalysis $analysis, GenerationStatus $status): GenerationAttempt
{
    return GenerationAttempt::factory()->create([
        'subject_type' => $analysis->getMorphClass(),
        'subject_id' => $analysis->id,
        'generation_type' => GenerationType::JobMatch,
        'status' => $status,
    ]);
}

/**
 * Seeds one JobPosting per canonical triage stage on the given profile,
 * each with a distinct created_at so the index's "newest first" ordering
 * is deterministic (Matched newest, Raw oldest). Every fixture uses the
 * same durable models the SummarizeTriageStates tests use — snapshots,
 * completed matches, GenerationAttempts — never a persisted stage.
 *
 * @return array<string, JobPosting> keyed by canonical stage
 */
function indexTriageStages(CareerProfile $profile): array
{
    $stages = [
        'raw' => JobPosting::factory()->for($profile)->create(['title' => 'Raw Role', 'created_at' => now()->subDays(7)]),
    ];

    $stages['analyzing'] = JobPosting::factory()->for($profile)->create(['title' => 'Analyzing Role', 'created_at' => now()->subDays(6)]);
    indexTriageAttempt($stages['analyzing'], GenerationType::JobAnalysis, GenerationStatus::Queued);

    $stages['analysis-failed'] = JobPosting::factory()->for($profile)->create(['title' => 'Analysis Failed Role', 'created_at' => now()->subDays(5)]);
    indexTriageAttempt($stages['analysis-failed'], GenerationType::JobAnalysis, GenerationStatus::Failed);

    $stages['analyzed'] = JobPosting::factory()->for($profile)->create(['title' => 'Analyzed Role', 'created_at' => now()->subDays(4)]);
    indexTriageAnalysis($stages['analyzed'], '2026-09-01 08:00:00');

    $stages['matching'] = JobPosting::factory()->for($profile)->create(['title' => 'Matching Role', 'created_at' => now()->subDays(3)]);
    $matchingAnalysis = indexTriageAnalysis($stages['matching'], '2026-09-01 08:00:00');
    indexTriageMatchAttempt($matchingAnalysis, GenerationStatus::Running);

    $stages['match-failed'] = JobPosting::factory()->for($profile)->create(['title' => 'Match Failed Role', 'created_at' => now()->subDays(2)]);
    $matchFailedAnalysis = indexTriageAnalysis($stages['match-failed'], '2026-09-01 08:00:00');
    indexTriageMatchAttempt($matchFailedAnalysis, GenerationStatus::Failed);

    $stages['matched'] = JobPosting::factory()->for($profile)->create(['title' => 'Matched Role', 'created_at' => now()->subDays(1)]);
    $matchedAnalysis = indexTriageAnalysis($stages['matched'], '2026-09-01 08:00:00');
    $match = indexTriageMatch($profile, $matchedAnalysis, '2026-09-02 08:00:00');
    indexTriageMatchFinding($match, $matchedAnalysis, JobMatchCoverage::Supported);

    return $stages;
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
            ->where('jobs.0.triage.analysis_id', null)
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
    $analysis = indexTriageAnalysis($job, '2026-09-01 08:00:00');

    $this->get(route('jobs.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('jobs.0.triage.stage', 'analyzed')
            ->where('jobs.0.triage.coverage', null)
            ->where('jobs.0.triage.analysis_id', $analysis->id)
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
            ->where('jobs.0.triage.analysis_id', null)
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
            ->where('jobs.0.triage.analysis_id', $analysis->id)
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
            ->where('jobs.0.triage.analysis_id', $matchedAnalysis->id)
            ->where('jobs.0.triage.analysis_at', '2026-09-01 08:00:00')
            ->where('jobs.0.triage.match_at', '2026-09-02 08:00:00')
            ->where('jobs.1.id', $analyzed->id)
            ->where('jobs.1.triage.stage', 'analyzed')
            ->where('jobs.1.triage.coverage', null)
            ->where('jobs.1.triage.analysis_id', $analyzedAnalysis->id)
            ->where('jobs.1.triage.analysis_at', '2026-09-01 08:00:00')
            ->where('jobs.1.triage.match_at', null)
            ->where('jobs.2.id', $raw->id)
            ->where('jobs.2.triage.stage', 'raw')
            ->where('jobs.2.triage.coverage', null)
            ->where('jobs.2.triage.analysis_id', null)
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
            ->where('jobs.0.triage.analysis_id', $analysis->id)
            ->where('jobs.0.triage.match_at', null)
        );
});

it('treats a missing triage filter as all, returning every stage', function () {
    $profile = CareerProfile::factory()->create();
    indexTriageStages($profile);

    $this->get(route('jobs.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('jobs', 7)
            ->where('filters.triage', 'all')
        );
});

it('treats triage=all as no restriction over the triage stages', function () {
    $profile = CareerProfile::factory()->create();
    $stages = indexTriageStages($profile);

    $this->get(route('jobs.index', ['triage' => 'all']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('jobs', 7)
            ->where('jobs.0.id', $stages['matched']->id)
            ->where('jobs.6.id', $stages['raw']->id)
            ->where('filters.triage', 'all')
        );
});

it('filters to only raw opportunities on the raw triage stage', function () {
    $profile = CareerProfile::factory()->create();
    $stages = indexTriageStages($profile);

    $this->get(route('jobs.index', ['triage' => 'raw']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('jobs', 1)
            ->where('jobs.0.id', $stages['raw']->id)
            ->where('jobs.0.triage.stage', 'raw')
            ->where('jobs.0.triage.coverage', null)
            ->where('filters.triage', 'raw')
        );
});

it('includes analyzing and matching on the in-progress triage stage and excludes the others, keeping ordering', function () {
    $profile = CareerProfile::factory()->create();
    $stages = indexTriageStages($profile);

    // Matching was created after Analyzing, so the newest-first ordering
    // is preserved within the filtered result.
    $this->get(route('jobs.index', ['triage' => 'in-progress']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('jobs', 2)
            ->where('jobs.0.id', $stages['matching']->id)
            ->where('jobs.0.triage.stage', 'matching')
            ->where('jobs.1.id', $stages['analyzing']->id)
            ->where('jobs.1.triage.stage', 'analyzing')
            ->where('filters.triage', 'in-progress')
        );
});

it('filters to only analyzed opportunities on the analyzed triage stage', function () {
    $profile = CareerProfile::factory()->create();
    $stages = indexTriageStages($profile);

    $this->get(route('jobs.index', ['triage' => 'analyzed']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('jobs', 1)
            ->where('jobs.0.id', $stages['analyzed']->id)
            ->where('jobs.0.triage.stage', 'analyzed')
            ->where('jobs.0.triage.analysis_at', '2026-09-01 08:00:00')
            ->where('jobs.0.triage.coverage', null)
            ->where('jobs.0.triage.match_at', null)
            ->where('filters.triage', 'analyzed')
        );
});

it('filters to only matched opportunities on the matched triage stage', function () {
    $profile = CareerProfile::factory()->create();
    $stages = indexTriageStages($profile);

    $this->get(route('jobs.index', ['triage' => 'matched']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('jobs', 1)
            ->where('jobs.0.id', $stages['matched']->id)
            ->where('jobs.0.triage.stage', 'matched')
            ->where('jobs.0.triage.coverage.supported', 1)
            ->where('jobs.0.triage.coverage.partial', 0)
            ->where('jobs.0.triage.coverage.no_evidence', 0)
            ->where('jobs.0.triage.coverage.not_assessable', 0)
            ->where('jobs.0.triage.match_at', '2026-09-02 08:00:00')
            ->where('filters.triage', 'matched')
        );
});

it('includes both failure stages on the failed triage stage and excludes the others', function () {
    $profile = CareerProfile::factory()->create();
    $stages = indexTriageStages($profile);

    // Match-failed was created after Analysis-failed, so the filter
    // result keeps the index newest-first ordering.
    $this->get(route('jobs.index', ['triage' => 'failed']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('jobs', 2)
            ->where('jobs.0.id', $stages['match-failed']->id)
            ->where('jobs.0.triage.stage', 'match-failed')
            ->where('jobs.0.triage.coverage', null)
            ->where('jobs.1.id', $stages['analysis-failed']->id)
            ->where('jobs.1.triage.stage', 'analysis-failed')
            ->where('filters.triage', 'failed')
        );
});

it('treats an invalid triage filter value as all instead of erroring', function () {
    $profile = CareerProfile::factory()->create();
    indexTriageStages($profile);

    $this->get(route('jobs.index', ['triage' => 'bogus-value']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('jobs', 7)
            ->where('filters.triage', 'all')
        );
});

it('composes the triage filter with the inspection status filter as an intersection', function () {
    $profile = CareerProfile::factory()->create();
    $stages = indexTriageStages($profile);

    // Promote one raw and one analyzed job to inspected, leaving the
    // rest not-inspected.
    foreach ([$stages['raw'], $stages['analyzed']] as $job) {
        $application = Application::factory()->for($job, 'jobPosting')->create();
        $workflowRun = WorkflowRun::factory()->for($application)->create(['status' => WorkflowStatus::Succeeded]);
        $step = WorkflowStep::factory()->for($workflowRun, 'workflowRun')->create(['status' => WorkflowStatus::Succeeded]);
        AgentRun::factory()->for($step, 'workflowStep')->create([
            'status' => AgentRunStatus::Succeeded,
            'inspection_outcome' => 'complete',
        ]);
    }

    $this->get(route('jobs.index', ['status' => 'inspected', 'triage' => 'raw']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('jobs', 1)
            ->where('jobs.0.id', $stages['raw']->id));

    $this->get(route('jobs.index', ['status' => 'not_inspected', 'triage' => 'analyzed']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('jobs', 0));

    // The composed filters stay active independently — flipping one
    // leaves the other in place.
    $this->get(route('jobs.index', ['status' => 'inspected', 'triage' => 'matched']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('jobs', 0)
            ->where('filters.status', 'inspected')
            ->where('filters.triage', 'matched'));
});

it('composes the triage filter with the search query', function () {
    $profile = CareerProfile::factory()->create();
    $raw = JobPosting::factory()->for($profile)->create(['title' => 'Staff Engineer', 'created_at' => now()->subDays(2)]);
    $analyzed = JobPosting::factory()->for($profile)->create(['title' => 'Senior Engineer', 'created_at' => now()->subDays(1)]);
    indexTriageAnalysis($analyzed, '2026-09-01 08:00:00');

    $this->get(route('jobs.index', ['q' => 'engineer', 'triage' => 'raw']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('jobs', 1)
            ->where('jobs.0.id', $raw->id)
            ->where('filters.q', 'engineer')
            ->where('filters.triage', 'raw'));

    $this->get(route('jobs.index', ['q' => 'engineer', 'triage' => 'analyzed']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('jobs', 1)
            ->where('jobs.0.id', $analyzed->id)
            ->where('filters.triage', 'analyzed'));
});

it('returns each active triage filter value in the filters props', function () {
    $profile = CareerProfile::factory()->create();
    indexTriageStages($profile);

    foreach (['raw', 'in-progress', 'analyzed', 'matched', 'failed'] as $value) {
        $this->get(route('jobs.index', ['triage' => $value]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.triage', $value)
                ->where('filters.q', '')
                ->where('filters.status', 'all'));
    }
});

/**
 * Server-side pagination. Names are `indexPage`-prefixed: Pest loads every
 * Feature file into one global namespace.
 */
function indexPageSeed(CareerProfile $profile, int $count, array $attributes = []): void
{
    foreach (range(1, $count) as $i) {
        JobPosting::factory()->for($profile)->create($attributes + [
            'title' => sprintf('Role %03d', $i),
            'created_at' => now()->subMinutes($count - $i),
        ]);
    }
}

it('paginates at 25 rows by default and reports the range', function () {
    indexPageSeed($profile = CareerProfile::factory()->create(), 60);

    $this->get(route('jobs.index'))->assertInertia(fn (Assert $page) => $page
        ->has('jobs', 25)
        ->where('jobs.0.title', 'Role 060')
        ->where('pagination.page', 1)
        ->where('pagination.per_page', 25)
        ->where('pagination.total', 60)
        ->where('pagination.last_page', 3)
        ->where('pagination.from', 1)
        ->where('pagination.to', 25)
        ->where('pagination.per_page_options', [25, 50, 100]));
});

it('serves later pages without repeating or skipping rows', function () {
    indexPageSeed(CareerProfile::factory()->create(), 60);

    $titles = collect([1, 2, 3])->flatMap(function (int $page) {
        $props = $this->get(route('jobs.index', ['page' => $page]))->inertiaProps();

        return collect($props['jobs'])->pluck('title');
    });

    expect($titles)->toHaveCount(60)->and($titles->unique())->toHaveCount(60)
        ->and($titles->first())->toBe('Role 060')->and($titles->last())->toBe('Role 001');
});

it('reports the last partial page range', function () {
    indexPageSeed(CareerProfile::factory()->create(), 60);

    $this->get(route('jobs.index', ['page' => 3]))->assertInertia(fn (Assert $page) => $page
        ->has('jobs', 10)
        ->where('pagination.from', 51)
        ->where('pagination.to', 60));
});

it('supports 50 and 100 rows per page and degrades an unsupported size to 25', function () {
    indexPageSeed(CareerProfile::factory()->create(), 120);

    $this->get(route('jobs.index', ['per_page' => 50]))->assertInertia(fn (Assert $page) => $page->has('jobs', 50)->where('pagination.last_page', 3));
    $this->get(route('jobs.index', ['per_page' => 100]))->assertInertia(fn (Assert $page) => $page->has('jobs', 100)->where('pagination.last_page', 2));
    $this->get(route('jobs.index', ['per_page' => 7]))->assertInertia(fn (Assert $page) => $page->has('jobs', 25)->where('pagination.per_page', 25));
});

it('clamps an out-of-range or invalid page into range', function () {
    indexPageSeed(CareerProfile::factory()->create(), 30);

    $this->get(route('jobs.index', ['page' => 99]))->assertInertia(fn (Assert $page) => $page->where('pagination.page', 2)->has('jobs', 5));
    $this->get(route('jobs.index', ['page' => -4]))->assertInertia(fn (Assert $page) => $page->where('pagination.page', 1));
    $this->get(route('jobs.index', ['page' => 'abc']))->assertInertia(fn (Assert $page) => $page->where('pagination.page', 1));
});

it('paginates the filtered result set, not the whole table, and counts only matches', function () {
    $profile = CareerProfile::factory()->create();
    indexPageSeed($profile, 40, ['company' => 'Acme']);
    indexPageSeed($profile, 10, ['company' => 'Other']);

    $this->get(route('jobs.index', ['q' => 'Acme', 'per_page' => 25, 'page' => 2]))->assertInertia(fn (Assert $page) => $page
        ->where('filters.q', 'Acme')
        ->where('pagination.total', 40)
        ->where('pagination.from', 26)
        ->where('pagination.to', 40)
        ->has('jobs', 15));
});

it('paginates a derived-state filter such as the triage stage', function () {
    $profile = CareerProfile::factory()->create();
    indexPageSeed($profile, 30);
    $analyzed = JobPosting::factory()->for($profile)->create(['title' => 'Analyzed One']);
    indexTriageAnalysis($analyzed, '2026-09-01 08:00:00');

    $this->get(route('jobs.index', ['triage' => 'raw']))->assertInertia(fn (Assert $page) => $page
        ->where('pagination.total', 30)
        ->has('jobs', 25));
    $this->get(route('jobs.index', ['triage' => 'analyzed']))->assertInertia(fn (Assert $page) => $page
        ->where('pagination.total', 1)
        ->where('jobs.0.title', 'Analyzed One'));
});

it('reports an empty range when nothing matches', function () {
    CareerProfile::factory()->create();

    $this->get(route('jobs.index', ['q' => 'nothing']))->assertInertia(fn (Assert $page) => $page
        ->where('pagination.total', 0)->where('pagination.from', 0)->where('pagination.to', 0)->where('pagination.last_page', 1));
});

it('only summarizes the rows on the page when no derived-state filter is active', function () {
    indexPageSeed(CareerProfile::factory()->create(), 60);

    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });

    $this->get(route('jobs.index'))->assertOk();

    // A fixed number of bulk queries, never one per posting.
    expect(count($queries))->toBeLessThan(40);
});
