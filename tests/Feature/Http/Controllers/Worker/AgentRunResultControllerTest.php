<?php

use App\Enums\AgentRunFailureCategory;
use App\Enums\AgentRunStatus;
use App\Enums\ApplicationQuestionExtractionSource;
use App\Enums\ApplicationQuestionLabelSource;
use App\Enums\WorkflowStatus;
use App\Models\AgentRun;
use App\Models\Application;
use App\Models\ApplicationQuestion;
use App\Models\JobPosting;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    config(['services.browser_worker.token' => 'test-token']);
});

function runningAgentRun(): AgentRun
{
    $jobPosting = JobPosting::factory()->create(['source_url' => 'https://example.com/jobs/1']);
    $application = Application::factory()->for($jobPosting, 'jobPosting')->create();
    $workflowRun = WorkflowRun::factory()->for($application)->create(['status' => WorkflowStatus::Running]);
    $step = WorkflowStep::factory()->for($workflowRun, 'workflowRun')->create(['status' => WorkflowStatus::Running]);

    return AgentRun::factory()->for($step, 'workflowStep')->create([
        'status' => AgentRunStatus::Running,
        'started_at' => now(),
        'claim_expires_at' => now()->addMinute(),
    ]);
}

function reportResult(AgentRun $agentRun, array $payload): TestResponse
{
    return test()->withHeader('Authorization', 'Bearer test-token')
        ->postJson("/api/worker/agent-runs/{$agentRun->id}/result", $payload);
}

function completeSuccessPayload(): array
{
    return [
        'status' => 'succeeded',
        'inspection_outcome' => 'complete',
        'ats' => 'greenhouse',
        'requested_url' => 'https://example.com/jobs/1',
        'final_url' => 'https://example.com/jobs/1',
        'fields' => [
            [
                'position' => 0,
                'external_field_id' => 'first_name',
                'raw_label' => 'First Name*',
                'label_source' => 'dom_label_for',
                'control_type' => 'text',
                'required' => true,
                'options' => null,
                'section' => null,
                'extraction_source' => 'dom',
            ],
            [
                'position' => 1,
                'external_field_id' => null,
                'raw_label' => null,
                'label_source' => 'unresolved',
                'control_type' => 'input',
                'required' => null,
                'options' => null,
                'section' => null,
                'extraction_source' => 'dom',
            ],
        ],
        'warnings' => ['a mild warning'],
        'diagnostics' => ['field_count' => 2, 'unresolved_label_count' => 1],
    ];
}

it('persists a complete successful result transactionally', function () {
    $agentRun = runningAgentRun();

    reportResult($agentRun, completeSuccessPayload())->assertOk()->assertJson(['accepted' => true, 'duplicate' => false]);

    $agentRun->refresh();
    expect($agentRun->status)->toBe(AgentRunStatus::Succeeded)
        ->and($agentRun->inspection_outcome->value)->toBe('complete')
        ->and($agentRun->ats_detected)->toBe('greenhouse')
        ->and($agentRun->finished_at)->not->toBeNull()
        ->and($agentRun->result_summary['warnings'])->toBe(['a mild warning'])
        ->and($agentRun->applicationQuestions)->toHaveCount(2);

    expect($agentRun->workflowStep->fresh()->status)->toBe(WorkflowStatus::Succeeded)
        ->and($agentRun->workflowStep->workflowRun->fresh()->status)->toBe(WorkflowStatus::Succeeded);
});

it('preserves position exactly, not insertion order', function () {
    $agentRun = runningAgentRun();
    $payload = completeSuccessPayload();
    // Reverse the array's own order to prove position (not array/insertion order) is what's trusted.
    $payload['fields'] = array_reverse($payload['fields']);

    reportResult($agentRun, $payload)->assertOk();

    $ordered = ApplicationQuestion::where('agent_run_id', $agentRun->id)->orderBy('position')->pluck('raw_label');
    expect($ordered->all())->toBe(['First Name*', null]);
});

it('preserves nullable uncertainty and options JSON exactly', function () {
    $agentRun = runningAgentRun();
    $payload = completeSuccessPayload();
    $payload['fields'][] = [
        'position' => 2,
        'external_field_id' => 'country',
        'raw_label' => 'Country',
        'label_source' => 'dom_aria_labelledby',
        'control_type' => 'select',
        'required' => false,
        'options' => ['United States', 'Canada'],
        'section' => 'Apply for this job',
        'extraction_source' => 'both',
    ];

    reportResult($agentRun, $payload)->assertOk();

    $unresolved = ApplicationQuestion::where('agent_run_id', $agentRun->id)->where('position', 1)->firstOrFail();
    $select = ApplicationQuestion::where('agent_run_id', $agentRun->id)->where('position', 2)->firstOrFail();

    expect($unresolved->raw_label)->toBeNull()
        ->and($unresolved->required)->toBeNull()
        ->and($unresolved->options)->toBeNull()
        ->and($unresolved->label_source)->toBe(ApplicationQuestionLabelSource::Unresolved)
        ->and($select->options)->toBe(['United States', 'Canada'])
        ->and($select->required)->toBeFalse()
        ->and($select->extraction_source)->toBe(ApplicationQuestionExtractionSource::Both);
});

it('rejects a malformed enum value', function () {
    $agentRun = runningAgentRun();
    $payload = completeSuccessPayload();
    $payload['fields'][0]['label_source'] = 'not_a_real_label_source';

    reportResult($agentRun, $payload)->assertUnprocessable();

    expect($agentRun->fresh()->status)->toBe(AgentRunStatus::Running);
});

it('rejects a worker attempting to report claim_timeout as its own failure_category', function () {
    $agentRun = runningAgentRun();

    reportResult($agentRun, [
        'status' => 'failed',
        'failure_category' => 'claim_timeout',
        'failure_message' => 'nice try',
    ])->assertUnprocessable();

    expect($agentRun->fresh()->status)->toBe(AgentRunStatus::Running);
});

it('persists an unsupported-ATS result as a successful technical execution', function () {
    $agentRun = runningAgentRun();

    reportResult($agentRun, [
        'status' => 'succeeded',
        'inspection_outcome' => 'unsupported',
        'ats' => null,
        'fields' => [],
        'warnings' => [],
        'diagnostics' => [],
    ])->assertOk();

    $agentRun->refresh();
    expect($agentRun->status)->toBe(AgentRunStatus::Succeeded)
        ->and($agentRun->inspection_outcome->value)->toBe('unsupported')
        ->and($agentRun->ats_detected)->toBeNull()
        ->and($agentRun->applicationQuestions)->toHaveCount(0);
});

it('marks all three execution levels failed on a technical failure and creates no questions', function () {
    $agentRun = runningAgentRun();

    reportResult($agentRun, [
        'status' => 'failed',
        'failure_category' => 'navigation_timeout',
        'failure_message' => 'page.goto timed out',
    ])->assertOk();

    $agentRun->refresh();
    expect($agentRun->status)->toBe(AgentRunStatus::Failed)
        ->and($agentRun->failure_category)->toBe(AgentRunFailureCategory::NavigationTimeout)
        ->and($agentRun->failure_message)->toBe('page.goto timed out')
        ->and($agentRun->applicationQuestions)->toHaveCount(0);

    expect($agentRun->workflowStep->fresh()->status)->toBe(WorkflowStatus::Failed)
        ->and($agentRun->workflowStep->workflowRun->fresh()->status)->toBe(WorkflowStatus::Failed);
});

// See tests/Feature/ApplicationInspection/PersistInspectionResultTest.php
// "does not mark the workflow successful if question persistence fails
// mid-transaction" for a genuine transactional-rollback proof at the
// service level — SQLite doesn't enforce column length, so a malformed
// HTTP payload large/invalid enough to fail at the DB layer is
// necessarily already rejected by ReportInspectionResultRequest first,
// which the "rejects a malformed enum value" test above already covers.

it('treats a duplicate identical result delivery as an idempotent no-op', function () {
    $agentRun = runningAgentRun();
    $payload = completeSuccessPayload();

    reportResult($agentRun, $payload)->assertOk()->assertJson(['accepted' => true, 'duplicate' => false]);
    $firstFinishedAt = $agentRun->fresh()->finished_at;
    $firstQuestionCount = ApplicationQuestion::where('agent_run_id', $agentRun->id)->count();

    reportResult($agentRun, $payload)->assertOk()->assertJson(['accepted' => true, 'duplicate' => true]);

    expect(ApplicationQuestion::where('agent_run_id', $agentRun->id)->count())->toBe($firstQuestionCount)
        ->and($agentRun->fresh()->finished_at->equalTo($firstFinishedAt))->toBeTrue();
});

it('rejects a stale result for an agent run a claim-timeout recovery already failed', function () {
    $agentRun = runningAgentRun();
    // Simulate the exact race the architecture names: the lease
    // expired and was recovered as failed before this (slow, since-
    // abandoned) worker's result finally arrived.
    $agentRun->update([
        'status' => AgentRunStatus::Failed,
        'failure_category' => AgentRunFailureCategory::ClaimTimeout,
        'finished_at' => now(),
    ]);
    $agentRun->workflowStep->update(['status' => WorkflowStatus::Failed]);
    $agentRun->workflowStep->workflowRun->update(['status' => WorkflowStatus::Failed]);

    reportResult($agentRun, completeSuccessPayload())
        ->assertStatus(409)
        ->assertJson(['accepted' => false, 'reason' => 'agent_run_not_running', 'current_status' => 'failed']);

    expect(ApplicationQuestion::where('agent_run_id', $agentRun->id)->count())->toBe(0);
});

it('cannot target another Application — application_id is derived server-side, never from the payload', function () {
    $agentRun = runningAgentRun();
    $otherApplication = Application::factory()->create();
    $payload = completeSuccessPayload();
    $payload['application_id'] = $otherApplication->id; // ignored — not a real field on this contract

    reportResult($agentRun, $payload)->assertOk();

    $question = ApplicationQuestion::where('agent_run_id', $agentRun->id)->first();
    expect($question->application_id)->toBe($agentRun->workflowStep->workflowRun->application_id)
        ->and($question->application_id)->not->toBe($otherApplication->id);
});

// ---- Phase 1: URL evidence, stop reasons and failed-run diagnostics ----

it('keeps URL evidence, redacted to origin and path, and the stop reason for a run that reached no form', function () {
    $agentRun = runningAgentRun();

    reportResult($agentRun, [
        'status' => 'succeeded',
        'inspection_outcome' => 'application_not_found',
        'stop_reason' => 'no_apply_link',
        'ats' => null,
        'requested_url' => 'https://jobicy.com/jobs/1-x?utm_source=feed&token=SECRET',
        'final_url' => 'https://jobicy.com/jobs/1-x?token=SECRET#top',
        'fields' => [],
        'warnings' => ['networkidle_timeout'],
        'diagnostics' => [
            'page_title' => 'A job - Jobicy',
            'http_status' => 200,
            'navigation' => [['kind' => 'start', 'url' => 'https://jobicy.com/jobs/1-x?token=SECRET', 'status' => 200]],
        ],
    ])->assertOk();

    $agentRun->refresh();

    expect($agentRun->inspection_outcome->value)->toBe('application_not_found')
        ->and($agentRun->inspection_stop_reason)->toBe('no_apply_link')
        ->and($agentRun->result_summary['requested_url'])->toBe('https://jobicy.com/jobs/1-x')
        ->and($agentRun->result_summary['final_url'])->toBe('https://jobicy.com/jobs/1-x')
        ->and($agentRun->result_summary['navigation'][0]['url'])->toBe('https://jobicy.com/jobs/1-x')
        ->and($agentRun->result_summary['page_title'])->toBe('A job - Jobicy')
        ->and($agentRun->result_summary['warnings'])->toBe(['networkidle_timeout'])
        ->and(json_encode($agentRun->result_summary))->not->toContain('SECRET');
});

it('retains diagnostics and redacts URLs in the message for a failed run', function () {
    $agentRun = runningAgentRun();

    reportResult($agentRun, [
        'status' => 'failed',
        'failure_category' => 'navigation_timeout',
        'failure_message' => 'Timeout navigating to https://employer.example.com/apply?token=SECRET',
        'diagnostics' => [
            'page_title' => 'Careers',
            'navigation' => [
                ['kind' => 'start', 'url' => 'https://jobicy.com/jobs/1', 'status' => 200],
                ['kind' => 'link', 'url' => 'https://employer.example.com/apply?token=SECRET', 'status' => null],
            ],
            'total_ms' => 21000,
        ],
    ])->assertOk();

    $agentRun->refresh();

    expect($agentRun->status)->toBe(AgentRunStatus::Failed)
        ->and($agentRun->failure_message)->not->toContain('SECRET')
        ->and($agentRun->result_summary['page_title'])->toBe('Careers')
        ->and($agentRun->result_summary['navigation'])->toHaveCount(2)
        ->and($agentRun->result_summary['navigation'][1]['url'])->toBe('https://employer.example.com/apply')
        ->and($agentRun->workflowStep->workflowRun->failure_message)->not->toContain('SECRET');
});

it('rejects an unknown stop reason and an oversized navigation trace', function () {
    $agentRun = runningAgentRun();

    reportResult($agentRun, [...completeSuccessPayload(), 'stop_reason' => 'because'])->assertUnprocessable();
    reportResult($agentRun, [...completeSuccessPayload(), 'diagnostics' => ['navigation' => array_fill(0, 13, ['kind' => 'redirect', 'url' => 'https://a.example.com/'])]])->assertUnprocessable();
});

it('saves the resolved application URL on the Application, validated again, only when a form was reached', function () {
    $agentRun = runningAgentRun();
    $application = $agentRun->workflowStep->workflowRun->application;

    reportResult($agentRun, [
        ...completeSuccessPayload(),
        'resolved_url' => 'https://boards.greenhouse.io/acme/jobs/9?gh_jid=9&utm_source=x',
    ])->assertOk();

    $application->refresh();
    expect($application->application_url)->toBe('https://boards.greenhouse.io/acme/jobs/9?gh_jid=9')
        ->and($application->application_url_origin)->toBe('resolved')
        ->and($application->application_url_set_at)->not->toBeNull();
});

it('does not save a resolved URL for an outcome that reached no form, nor an unsafe one', function () {
    $agentRun = runningAgentRun();
    $application = $agentRun->workflowStep->workflowRun->application;

    reportResult($agentRun, [
        'status' => 'succeeded', 'inspection_outcome' => 'application_not_found', 'stop_reason' => 'no_apply_link',
        'fields' => [], 'resolved_url' => 'https://elsewhere.example.com/apply',
    ])->assertOk();
    expect($application->fresh()->application_url)->toBeNull();

    $second = runningAgentRun();
    reportResult($second, [...completeSuccessPayload(), 'resolved_url' => 'http://169.254.169.254/latest'])->assertOk();
    expect($second->workflowStep->workflowRun->application->fresh()->application_url)->toBeNull();
});

it('never replaces a URL the person supplied, and a resolution equal to the source URL is not recorded', function () {
    $agentRun = runningAgentRun();
    $application = $agentRun->workflowStep->workflowRun->application;
    $application->update(['application_url' => 'https://mine.example.com/apply', 'application_url_origin' => 'user', 'application_url_set_at' => now()]);

    reportResult($agentRun, [...completeSuccessPayload(), 'resolved_url' => 'https://boards.greenhouse.io/acme/jobs/9'])->assertOk();
    expect($application->fresh()->application_url)->toBe('https://mine.example.com/apply')
        ->and($application->fresh()->application_url_origin)->toBe('user');

    $other = runningAgentRun();
    reportResult($other, [...completeSuccessPayload(), 'resolved_url' => 'https://example.com/jobs/1?utm_source=x'])->assertOk();
    expect($other->workflowStep->workflowRun->application->fresh()->application_url)->toBeNull();
});

it('counts an unsupported result as a reached form only when its stop reason says so', function () {
    $with = runningAgentRun();
    reportResult($with, ['status' => 'succeeded', 'inspection_outcome' => 'unsupported', 'stop_reason' => 'form_without_adapter', 'fields' => [], 'resolved_url' => 'https://ats.example.com/apply/1'])->assertOk();
    expect($with->workflowStep->workflowRun->application->fresh()->application_url)->toBe('https://ats.example.com/apply/1');

    $without = runningAgentRun();
    reportResult($without, ['status' => 'succeeded', 'inspection_outcome' => 'unsupported', 'fields' => [], 'resolved_url' => 'https://ats.example.com/apply/2'])->assertOk();
    expect($without->workflowStep->workflowRun->application->fresh()->application_url)->toBeNull();
});
