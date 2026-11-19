<?php

use App\Enums\AgentRunStatus;
use App\Enums\WorkflowStatus;
use App\Models\AgentRun;
use App\Models\Application;
use App\Models\ApplicationQuestion;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Support\ApplicationInspection\PresentApplicationInspection;

function succeededAgentRunWithQuestions(Application $application, int $questionCount): AgentRun
{
    $workflowRun = WorkflowRun::factory()->for($application)->create(['status' => WorkflowStatus::Succeeded]);
    $step = WorkflowStep::factory()->for($workflowRun, 'workflowRun')->create(['status' => WorkflowStatus::Succeeded]);
    $agentRun = AgentRun::factory()->for($step, 'workflowStep')->create([
        'status' => AgentRunStatus::Succeeded,
        'inspection_outcome' => 'complete',
        'ats_detected' => 'greenhouse',
    ]);

    for ($i = 0; $i < $questionCount; $i++) {
        ApplicationQuestion::factory()->for($application)->for($agentRun, 'agentRun')->create(['position' => $i]);
    }

    return $agentRun;
}

it('presents the first successful inspection when only one exists', function () {
    $application = Application::factory()->create();
    succeededAgentRunWithQuestions($application, 3);

    $presented = (new PresentApplicationInspection)->present($application);

    expect($presented['latest_result']['field_count'])->toBe(3);
});

it('presents the LATEST successful inspection when multiple exist, not simply all questions ever created', function () {
    $application = Application::factory()->create();
    succeededAgentRunWithQuestions($application, 3);
    $second = succeededAgentRunWithQuestions($application, 5);

    $presented = (new PresentApplicationInspection)->present($application);

    expect($presented['latest_result']['agent_run_id'])->toBe($second->id)
        ->and($presented['latest_result']['field_count'])->toBe(5);
});

it('keeps older inspection questions in the database even though the presenter only shows the latest', function () {
    $application = Application::factory()->create();
    succeededAgentRunWithQuestions($application, 3);
    succeededAgentRunWithQuestions($application, 5);

    expect(ApplicationQuestion::where('application_id', $application->id)->count())->toBe(8);
});

it('does not let a later failed inspection erase the previous successful result', function () {
    $application = Application::factory()->create();
    succeededAgentRunWithQuestions($application, 3);

    $failedRun = WorkflowRun::factory()->for($application)->create(['status' => WorkflowStatus::Failed, 'failure_message' => 'boom']);

    $presented = (new PresentApplicationInspection)->present($application);

    expect($presented['workflow_run']['status'])->toBe('failed')
        ->and($presented['workflow_run']['failure_message'])->toBe('boom')
        ->and($presented['latest_result']['field_count'])->toBe(3);
});

it('presents null latest_result when no inspection has ever succeeded', function () {
    $application = Application::factory()->create();
    WorkflowRun::factory()->for($application)->create(['status' => WorkflowStatus::Failed]);

    $presented = (new PresentApplicationInspection)->present($application);

    expect($presented['latest_result'])->toBeNull();
});

it('presents null workflow_run when no inspection has ever been dispatched', function () {
    $application = Application::factory()->create();

    $presented = (new PresentApplicationInspection)->present($application);

    expect($presented['workflow_run'])->toBeNull()
        ->and($presented['latest_result'])->toBeNull();
});

function nonExtractingRun(Application $application, string $outcome, ?string $stopReason, array $summary = []): AgentRun
{
    $workflowRun = WorkflowRun::factory()->for($application)->create(['status' => WorkflowStatus::Succeeded]);
    $step = WorkflowStep::factory()->for($workflowRun, 'workflowRun')->create(['status' => WorkflowStatus::Succeeded]);

    return AgentRun::factory()->for($step, 'workflowStep')->create([
        'status' => AgentRunStatus::Succeeded,
        'inspection_outcome' => $outcome,
        'inspection_stop_reason' => $stopReason,
        'result_summary' => $summary,
    ]);
}

it('does not let a later run that found nothing hide an earlier extraction', function () {
    $application = Application::factory()->create();
    $earlier = succeededAgentRunWithQuestions($application, 4);
    nonExtractingRun($application, 'blocked', 'anti_bot');

    $presented = (new PresentApplicationInspection)->present($application);

    expect($presented['latest_result']['inspection_outcome'])->toBe('blocked')
        ->and($presented['latest_result']['stop_reason'])->toBe('anti_bot')
        ->and($presented['earlier_result']['agent_run_id'])->toBe($earlier->id)
        ->and($presented['earlier_result']['field_count'])->toBe(4);
});

it('offers no earlier result when the latest run extracted fields or there was never one', function () {
    $application = Application::factory()->create();
    succeededAgentRunWithQuestions($application, 2);
    succeededAgentRunWithQuestions($application, 3);
    expect((new PresentApplicationInspection)->present($application)['earlier_result'])->toBeNull();

    $other = Application::factory()->create();
    nonExtractingRun($other, 'application_not_found', 'no_apply_link');
    expect((new PresentApplicationInspection)->present($other)['earlier_result'])->toBeNull();
});

it('reads a record from before URL evidence existed without inventing any', function () {
    $application = Application::factory()->create();
    nonExtractingRun($application, 'unsupported', null, ['total_ms' => 5000, 'page_title' => 'Old title', 'warnings' => ['networkidle_timeout']]);

    $diagnostics = (new PresentApplicationInspection)->present($application)['latest_result']['diagnostics'];

    expect($diagnostics['recorded'])->toBeFalse()
        ->and($diagnostics['requested_url'])->toBeNull()
        ->and($diagnostics['final_url'])->toBeNull()
        ->and($diagnostics['navigation'])->toBe([])
        ->and($diagnostics['page_title'])->toBe('Old title')
        ->and($diagnostics['warnings'])->toBe(['networkidle_timeout']);
});

it('presents the application URL without a query string, and the latest failed attempt\'s diagnostics', function () {
    $application = Application::factory()->create([
        'application_url' => 'https://boards.greenhouse.io/acme/jobs/9?gh_jid=9',
        'application_url_origin' => 'user',
        'application_url_set_at' => now(),
    ]);
    $workflowRun = WorkflowRun::factory()->for($application)->create(['status' => WorkflowStatus::Failed, 'failure_message' => 'x']);
    $step = WorkflowStep::factory()->for($workflowRun, 'workflowRun')->create(['status' => WorkflowStatus::Failed]);
    AgentRun::factory()->for($step, 'workflowStep')->create([
        'status' => AgentRunStatus::Failed,
        'failure_category' => 'navigation_timeout',
        'failure_message' => 'Timed out',
        'result_summary' => ['requested_url' => 'https://a.example.com/x', 'page_title' => 'Slow page', 'warnings' => []],
    ]);

    $presented = (new PresentApplicationInspection)->present($application);

    expect($presented['application_url'])->toMatchArray(['url' => 'https://boards.greenhouse.io/acme/jobs/9', 'origin' => 'user'])
        ->and($presented['latest_attempt']['failure_category'])->toBe('navigation_timeout')
        ->and($presented['latest_attempt']['diagnostics']['page_title'])->toBe('Slow page')
        ->and($presented['latest_attempt']['diagnostics']['recorded'])->toBeTrue();
});
