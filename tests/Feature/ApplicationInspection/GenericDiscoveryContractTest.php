<?php

use App\Enums\AgentRunStatus;
use App\Enums\ApplicationQuestionLabelSource;
use App\Enums\InspectionOutcome;
use App\Enums\WorkflowStatus;
use App\Http\Requests\Worker\ReportInspectionResultRequest;
use App\Models\AgentRun;
use App\Models\Application;
use App\Models\ApplicationQuestion;
use App\Models\JobPosting;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Support\ApplicationInspection\PersistInspectionResult;
use Illuminate\Support\Facades\Validator;

/**
 * M1: generic form discovery adds NO new contract. These are the results the worker really produces (committed snapshots, kept
 * honest by a drift test on the worker side), pushed through Laravel's own request rules and persistence. If the worker ever
 * emits a key, enum value or shape Laravel does not know, this fails.
 */
function genericSnapshot(string $name): array
{
    $payload = json_decode(file_get_contents(base_path("workers/browser-inspector/test/fixtures/universal/contract/{$name}.result.json")), true, flags: JSON_THROW_ON_ERROR);
    unset($payload['_note']); // a comment in the snapshot, not part of what the worker sends

    return $payload;
}

function genericRunningAgentRun(): AgentRun
{
    $application = Application::factory()->for(JobPosting::factory()->create(), 'jobPosting')->create();
    $workflowRun = WorkflowRun::factory()->for($application)->create(['status' => WorkflowStatus::Running]);
    $step = WorkflowStep::factory()->for($workflowRun, 'workflowRun')->create(['status' => WorkflowStatus::Running]);

    return AgentRun::factory()->for($step, 'workflowStep')->create(['status' => AgentRunStatus::Running]);
}

dataset('generic snapshots', ['generic-unknown-ats-form', 'generic-greenhouse-style-partial']);

it('passes the request validation exactly as written, with every field key kept', function (string $name) {
    $payload = genericSnapshot($name);
    $validator = Validator::make($payload, (new ReportInspectionResultRequest)->rules());

    expect($validator->fails())->toBeFalse(json_encode($validator->errors()->all()));

    $validated = $validator->validated();
    foreach ($payload['fields'] as $i => $field) {
        // validated() drops keys the contract does not name, so equality proves the worker sent nothing Laravel would lose.
        expect($validated['fields'][$i])->toEqual($field);
    }
})->with('generic snapshots');

it('persists the fields as application questions with the right provenance', function () {
    $payload = Validator::make(genericSnapshot('generic-greenhouse-style-partial'), (new ReportInspectionResultRequest)->rules())->validated();
    $agentRun = genericRunningAgentRun();

    expect(app(PersistInspectionResult::class)->persist($agentRun, $payload)['outcome'])->toBe('accepted');

    $agentRun->refresh();
    $questions = ApplicationQuestion::where('agent_run_id', $agentRun->id)->orderBy('position')->get();

    expect($agentRun->status)->toBe(AgentRunStatus::Succeeded)
        ->and($agentRun->inspection_outcome)->toBe(InspectionOutcome::Partial)
        ->and($agentRun->inspection_stop_reason)->toBeNull()
        ->and($questions)->toHaveCount(19);

    // One row per logical control, the dropdowns typed as selections, the heading-derived label stored as the closest
    // existing source, the marker gone from the label.
    $byField = $questions->keyBy('external_field_id');
    expect($byField['country']->control_type)->toBe('select')
        ->and($byField['country']->raw_label)->toBe('Country')
        ->and($byField['country']->required)->toBeTrue()
        ->and($byField['gender']->control_type)->toBe('select')
        ->and($byField['resume']->raw_label)->toBe('Resume/CV')
        ->and($byField['resume']->label_source)->toBe(ApplicationQuestionLabelSource::InferredProximity)
        ->and($byField['resume']->control_type)->toBe('file')
        ->and($byField['resume']->required)->toBeFalse();
    expect($questions->where('external_field_id', null))->toHaveCount(0);
});

it('keeps what was not read visible: the partial outcome and its warnings are stored with the run', function () {
    $payload = Validator::make(genericSnapshot('generic-greenhouse-style-partial'), (new ReportInspectionResultRequest)->rules())->validated();
    $agentRun = genericRunningAgentRun();
    app(PersistInspectionResult::class)->persist($agentRun, $payload);

    $summary = $agentRun->refresh()->result_summary;

    expect($summary['warnings'])->toContain('conditional_fields_not_inspected')
        ->and($summary['warnings'])->toContain('generic_discovery');
});

it('persists an unknown-ATS form as complete, which was unsupported with no fields before', function () {
    $payload = Validator::make(genericSnapshot('generic-unknown-ats-form'), (new ReportInspectionResultRequest)->rules())->validated();
    $agentRun = genericRunningAgentRun();
    app(PersistInspectionResult::class)->persist($agentRun, $payload);

    expect($agentRun->refresh()->inspection_outcome)->toBe(InspectionOutcome::Complete)
        ->and(ApplicationQuestion::where('agent_run_id', $agentRun->id)->pluck('control_type', 'raw_label')->all())->toBe([
            'First name' => 'text', 'Last name' => 'text', 'Email' => 'email', 'Phone' => 'tel', 'Resume' => 'file', 'Why do you want to work here?' => 'textarea',
        ]);
});
