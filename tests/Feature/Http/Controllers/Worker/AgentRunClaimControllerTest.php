<?php

use App\Enums\AgentRunFailureCategory;
use App\Enums\AgentRunStatus;
use App\Enums\WorkflowStatus;
use App\Models\AgentRun;
use App\Models\Application;
use App\Models\JobPosting;
use App\Models\WorkerHeartbeat;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    config(['services.browser_worker.token' => 'test-token']);
});

/**
 * Builds a full Application -> WorkflowRun -> WorkflowStep -> AgentRun
 * chain against a real JobPosting, returning the AgentRun (its
 * ancestors are reachable via relations for assertions).
 */
function queuedAgentRun(array $agentRunOverrides = [], array $workflowOverrides = []): AgentRun
{
    $jobPosting = JobPosting::factory()->create(['source_url' => 'https://example.com/jobs/1']);
    $application = Application::factory()->for($jobPosting, 'jobPosting')->create();
    $workflowRun = WorkflowRun::factory()->for($application)->create(array_merge(
        ['status' => WorkflowStatus::Pending],
        $workflowOverrides,
    ));
    $step = WorkflowStep::factory()->for($workflowRun, 'workflowRun')->create(array_merge(
        ['status' => WorkflowStatus::Pending],
        $workflowOverrides,
    ));

    return AgentRun::factory()->for($step, 'workflowStep')->create(array_merge(
        ['status' => AgentRunStatus::Queued],
        $agentRunOverrides,
    ));
}

function claimRequest(): TestResponse
{
    return test()->withHeader('Authorization', 'Bearer test-token')
        ->postJson('/api/worker/agent-runs/claim', ['worker_identity' => 'test-worker']);
}

it('returns no content when there is no queued work', function () {
    claimRequest()->assertNoContent();
});

it('atomically claims a queued browser_inspector agent run', function () {
    $agentRun = queuedAgentRun();
    $jobPosting = $agentRun->workflowStep->workflowRun->application->jobPosting;

    $response = claimRequest();

    $response->assertOk()->assertJson([
        'agent_run_id' => $agentRun->id,
        'application_url' => $jobPosting->source_url,
        'start_url' => $jobPosting->source_url,
        'start_origin' => 'source',
        'source_url' => $jobPosting->source_url,
    ]);

    $agentRun->refresh();
    expect($agentRun->status)->toBe(AgentRunStatus::Running)
        ->and($agentRun->worker_identity)->toBe('test-worker')
        ->and($agentRun->started_at)->not->toBeNull()
        ->and($agentRun->claim_expires_at)->not->toBeNull();
});

it('never returns Career Profile data, resume content, or unrelated Application fields', function () {
    queuedAgentRun();

    $response = claimRequest();

    expect(array_keys($response->json()))->toEqualCanonicalizing([
        'agent_run_id', 'application_url', 'start_url', 'start_origin', 'source_url', 'budget_seconds',
    ]);
});

it('marks the owning workflow step and workflow run running on claim', function () {
    $agentRun = queuedAgentRun();
    $step = $agentRun->workflowStep;
    $workflowRun = $step->workflowRun;

    claimRequest()->assertOk();

    expect($step->fresh()->status)->toBe(WorkflowStatus::Running)
        ->and($workflowRun->fresh()->status)->toBe(WorkflowStatus::Running);
});

it('never claims the same agent run twice', function () {
    queuedAgentRun();

    $first = claimRequest();
    $second = claimRequest();

    $first->assertOk();
    $second->assertNoContent();
});

it('never claims a non-browser_inspector agent run', function () {
    queuedAgentRun(['agent_type' => 'some_other_agent_type']);

    claimRequest()->assertNoContent();
});

it('records worker presence on an authenticated claim poll even when there is no work', function () {
    claimRequest()->assertNoContent();

    $heartbeat = WorkerHeartbeat::firstWhere('identity', 'test-worker');
    expect($heartbeat)->not->toBeNull()
        ->and($heartbeat->worker_type)->toBe('browser_inspector')
        ->and($heartbeat->last_seen_at)->not->toBeNull();
});

it('does not record presence for an unauthenticated claim attempt', function () {
    test()->postJson('/api/worker/agent-runs/claim', ['worker_identity' => 'sneaky-worker'])
        ->assertUnauthorized();

    expect(WorkerHeartbeat::count())->toBe(0);
});

it('upserts the same identity rather than accumulating a new row per poll', function () {
    claimRequest();
    claimRequest();
    claimRequest();

    expect(WorkerHeartbeat::where('identity', 'test-worker')->count())->toBe(1);
});

it('recovers an expired claim as failed with claim_timeout before assigning new work', function () {
    $stale = queuedAgentRun(
        agentRunOverrides: ['status' => AgentRunStatus::Running, 'claim_expires_at' => now()->subMinute()],
        workflowOverrides: ['status' => WorkflowStatus::Running],
    );
    $step = $stale->workflowStep;
    $workflowRun = $step->workflowRun;

    claimRequest()->assertNoContent();

    $stale->refresh();
    expect($stale->status)->toBe(AgentRunStatus::Failed)
        ->and($stale->failure_category)->toBe(AgentRunFailureCategory::ClaimTimeout)
        ->and($step->fresh()->status)->toBe(WorkflowStatus::Failed)
        ->and($workflowRun->fresh()->status)->toBe(WorkflowStatus::Failed);
});

// ---- Phase 1: start URL precedence and the execution budget ----

it('sends an execution budget that always ends before the lease, so the worker stops itself first', function () {
    foreach ([120, 60, 45] as $ttl) {
        config(['services.browser_worker.claim_ttl' => $ttl, 'services.browser_worker.delivery_allowance' => 30]);
        queuedAgentRun();

        $budget = claimRequest()->json('budget_seconds');

        expect($budget)->toBeLessThan($ttl)->and($budget)->toBeGreaterThanOrEqual(15);
    }
});

it('defaults to a 120 second lease with a 90 second budget', function () {
    queuedAgentRun();

    expect(config('services.browser_worker.claim_ttl'))->toBe(120)
        ->and(claimRequest()->json('budget_seconds'))->toBe(90);
});

it('starts at the Application URL when it has one, the person\'s own taking the origin user', function () {
    $agentRun = queuedAgentRun();
    $agentRun->workflowStep->workflowRun->application->update([
        'application_url' => 'https://boards.greenhouse.io/acme/jobs/9?gh_jid=9&utm_source=x',
        'application_url_origin' => 'user',
        'application_url_set_at' => now(),
    ]);

    $response = claimRequest();

    $response->assertOk()->assertJson([
        'start_url' => 'https://boards.greenhouse.io/acme/jobs/9?gh_jid=9',
        'start_origin' => 'user',
        'source_url' => 'https://example.com/jobs/1',
    ]);
    expect($agentRun->fresh()->input_summary)->toBe([
        'start_origin' => 'user',
        'start_url' => 'https://boards.greenhouse.io/acme/jobs/9',
    ]);
});

it('labels an inspector-resolved Application URL as resolved', function () {
    $agentRun = queuedAgentRun();
    $agentRun->workflowStep->workflowRun->application->update(['application_url' => 'https://jobs.lever.co/acme/1', 'application_url_origin' => 'resolved']);

    claimRequest()->assertJson(['start_origin' => 'resolved', 'start_url' => 'https://jobs.lever.co/acme/1']);
});

it('ignores an Application URL that fails the URL policy and falls back to the source URL', function () {
    $agentRun = queuedAgentRun();
    $agentRun->workflowStep->workflowRun->application->update(['application_url' => 'http://169.254.169.254/latest', 'application_url_origin' => 'user']);

    claimRequest()->assertJson(['start_origin' => 'source', 'start_url' => 'https://example.com/jobs/1']);
});

it('uses a USAJobs apply destination that differs from the source URL, but not one that fails validation or equals it', function () {
    $agentRun = queuedAgentRun();
    $job = $agentRun->workflowStep->workflowRun->application->jobPosting;
    $job->update([
        'discovery_source' => 'usajobs',
        'source_url' => 'https://www.usajobs.gov/job/1',
        'discovery_metadata' => ['discovery' => ['applyUris' => ['http://10.0.0.1/apply', 'https://www.usajobs.gov/job/1', 'https://apply.usajobs.gov/applicant/1']]],
    ]);

    claimRequest()->assertJson(['start_origin' => 'provider', 'start_url' => 'https://apply.usajobs.gov/applicant/1', 'source_url' => 'https://www.usajobs.gov/job/1']);
});

it('does not read apply destinations from providers that do not supply one, and a user URL outranks a provider one', function () {
    $agentRun = queuedAgentRun();
    $application = $agentRun->workflowStep->workflowRun->application;
    $application->jobPosting->update(['discovery_source' => 'jobicy', 'discovery_metadata' => ['discovery' => ['applyUris' => ['https://evil.example.com/x']]]]);

    claimRequest()->assertJson(['start_origin' => 'source']);

    $second = queuedAgentRun();
    $app2 = $second->workflowStep->workflowRun->application;
    $app2->jobPosting->update(['discovery_source' => 'usajobs', 'source_url' => 'https://www.usajobs.gov/job/2', 'discovery_metadata' => ['discovery' => ['applyUris' => ['https://apply.usajobs.gov/a/2']]]]);
    $app2->update(['application_url' => 'https://mine.example.com/apply', 'application_url_origin' => 'user']);

    claimRequest()->assertJson(['start_origin' => 'user', 'start_url' => 'https://mine.example.com/apply']);
});

it('still sends the raw source URL when nothing passes validation, so the worker refuses it and says why', function () {
    $agentRun = queuedAgentRun();
    $agentRun->workflowStep->workflowRun->application->jobPosting->update(['source_url' => 'http://localhost/x']);

    claimRequest()->assertJson(['start_origin' => 'source', 'start_url' => 'http://localhost/x']);
});
