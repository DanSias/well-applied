<?php

use App\Enums\AgentRunStatus;
use App\Enums\ApplicationQuestionExtractionSource;
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
use App\Support\ApplicationInspection\PresentApplicationInspection;
use Illuminate\Support\Facades\Validator;

/**
 * The offline Ashby adapter's provider-reported result (a committed snapshot, kept honest by a drift test on the worker side)
 * pushed through Laravel's own request rules and persistence. Provider-reported fields are NOT observed-in-DOM fields: the
 * label and extraction sources say so, and nothing here implies the page showed them.
 */
function ashbyPayload(): array
{
    $payload = json_decode(file_get_contents(base_path('workers/browser-inspector/test/fixtures/ashby/expected/nerdwallet-258.contract.result.json')), true, flags: JSON_THROW_ON_ERROR);
    unset($payload['_note']);

    return $payload;
}

function ashbyValidate(array $payload): Illuminate\Validation\Validator
{
    return Validator::make($payload, (new ReportInspectionResultRequest)->rules());
}

function ashbyRunningAgentRun(): AgentRun
{
    $application = Application::factory()->for(JobPosting::factory()->create(), 'jobPosting')->create();
    $workflowRun = WorkflowRun::factory()->for($application)->create(['status' => WorkflowStatus::Running]);
    $step = WorkflowStep::factory()->for($workflowRun, 'workflowRun')->create(['status' => WorkflowStatus::Running]);

    return AgentRun::factory()->for($step, 'workflowStep')->create(['status' => AgentRunStatus::Running]);
}

function ashbyPersist(?array $payload = null): AgentRun
{
    $agentRun = ashbyRunningAgentRun();
    $validated = ashbyValidate($payload ?? ashbyPayload())->validated();
    expect(app(PersistInspectionResult::class)->persist($agentRun, $validated)['outcome'])->toBe('accepted');

    return $agentRun->refresh();
}

it('passes the real request validation exactly as written, with every field key kept', function () {
    $payload = ashbyPayload();
    $validator = ashbyValidate($payload);

    expect($validator->fails())->toBeFalse(json_encode($validator->errors()->all()));
    foreach ($payload['fields'] as $i => $field) {
        expect($validator->validated()['fields'][$i])->toEqual($field);
    }
});

it('persists all 29 provider-reported fields with provider provenance, never as observed DOM', function () {
    $agentRun = ashbyPersist();
    $questions = ApplicationQuestion::where('agent_run_id', $agentRun->id)->orderBy('position')->get();

    expect($agentRun->status)->toBe(AgentRunStatus::Succeeded)
        ->and($agentRun->ats_detected)->toBe('ashby')
        ->and($questions)->toHaveCount(29)
        ->and($questions->pluck('position')->all())->toBe(range(0, 28))
        ->and($questions->pluck('extraction_source')->unique()->all())->toBe([ApplicationQuestionExtractionSource::ProviderSchema])
        ->and($questions->pluck('label_source')->unique()->map->value->sort()->values()->all())->toBe(['provider_schema', 'unresolved']);
});

it('keeps identity, label, type, required, section and options', function () {
    $questions = ApplicationQuestion::where('agent_run_id', ashbyPersist()->id)->get();
    $name = $questions->firstWhere('external_field_id', '_systemfield_name');

    expect($name->raw_label)->toBe('Name')
        ->and($name->label_source)->toBe(ApplicationQuestionLabelSource::ProviderSchema)
        ->and($name->control_type)->toBe('text')
        ->and($name->required)->toBeTrue()
        ->and($name->section)->toBe('Personal Information')
        ->and($questions->where('required', true))->toHaveCount(18)
        ->and($questions->where('section', 'NerdWallet Demographic Survey'))->toHaveCount(6)
        ->and($questions->whereNotNull('options')->every(fn ($q) => is_array($q->options) && $q->options !== []))->toBeTrue()
        ->and($questions->where('control_type', 'yes_no')->every(fn ($q) => $q->options === null))->toBeTrue();
});

it('stores the one untitled consent control as unresolved with no label rather than an invented one', function () {
    $q = ApplicationQuestion::where('agent_run_id', ashbyPersist()->id)->where('label_source', ApplicationQuestionLabelSource::Unresolved->value)->sole();

    expect($q->external_field_id)->toBe('_systemfield_data_consent_ack')
        ->and($q->raw_label)->toBeNull()
        ->and($q->extraction_source)->toBe(ApplicationQuestionExtractionSource::ProviderSchema);
});

it('claims neither completeness nor visibility: partial, no URLs, and the limits are stored with the run', function () {
    $agentRun = ashbyPersist();

    expect($agentRun->inspection_outcome)->toBe(InspectionOutcome::Partial)
        ->and($agentRun->inspection_stop_reason)->toBeNull()
        ->and($agentRun->result_summary['warnings'])->toContain('provider_schema_only', 'visibility_not_established', 'conditional_logic_not_in_schema')
        ->and($agentRun->result_summary['requested_url'])->toBeNull()
        ->and($agentRun->result_summary['resolved_url'])->toBeNull();
});

it('does not persist or accept the internal-only facts (sensitivity, widget, visibility, form)', function () {
    $payload = ashbyPayload();
    $payload['fields'][0] += ['sensitive' => true, 'widget' => 'checkbox', 'visibility' => 'visible', 'form' => 'survey_form'];
    $validated = ashbyValidate($payload)->validated();

    expect(array_keys($validated['fields'][0]))->not->toContain('sensitive', 'widget', 'visibility', 'form');
    expect(array_keys(ApplicationQuestion::factory()->make()->getAttributes()))->not->toContain('sensitive', 'widget', 'visibility', 'form');
});

it('refuses a source the contract does not know, and a missing one', function () {
    $payload = ashbyPayload();
    $payload['fields'][0]['label_source'] = 'provider_schema_v2';
    $payload['fields'][1]['extraction_source'] = 'schema';
    unset($payload['fields'][2]['label_source']);

    expect(ashbyValidate($payload)->errors()->keys())->toContain('fields.0.label_source', 'fields.1.extraction_source', 'fields.2.label_source');
});

it('keeps an unknown provider type as unknown, and an undetermined required flag as null', function () {
    $payload = ashbyPayload();
    $payload['fields'][3]['control_type'] = 'unknown';
    $payload['fields'][4]['required'] = null;
    $agentRun = ashbyPersist($payload);
    $questions = ApplicationQuestion::where('agent_run_id', $agentRun->id)->orderBy('position')->get();

    expect($questions[3]->control_type)->toBe('unknown')
        ->and($questions[4]->required)->toBeNull();
});

it('refuses an oversized control type or a malformed option list', function () {
    $payload = ashbyPayload();
    $payload['fields'][0]['control_type'] = str_repeat('x', 101);
    $payload['fields'][1]['options'] = [['label' => 'nested']];

    expect(ashbyValidate($payload)->errors()->keys())->toContain('fields.0.control_type', 'fields.1.options.0');
});

it('shows the presented questions with the provider source, so a person can tell them from page-observed ones', function () {
    $agentRun = ashbyPersist();
    $application = $agentRun->workflowStep->workflowRun->application;
    $presented = app(PresentApplicationInspection::class)->present($application);
    $questions = $presented['latest_result']['questions'];

    expect($questions)->toHaveCount(29)
        ->and(collect($questions)->pluck('extraction_source')->unique()->all())->toBe(['provider_schema'])
        ->and($presented['latest_result']['unresolved_count'])->toBe(1);
});

it('keeps the application and survey field counts with the run, since the contract has no column for the form', function () {
    $summary = ashbyPersist()->result_summary;

    expect($summary['warnings'])->toContain('provider_schema_application_fields:22', 'provider_schema_survey_fields:7');
});

it('accepts and stores a security refusal as a failed run with its evidence, never as a result with fields', function () {
    $payload = [
        'status' => 'failed',
        'failure_category' => 'unexpected_error',
        'failure_message' => 'Ashby schema retrieval was refused by security validation (job_posting/redirect_refused). No browser fallback was attempted.',
        'warnings' => ['ashby_schema_security_refusal', 'ashby_schema_refused:job_posting/redirect_refused'],
        'diagnostics' => ['http_status' => null, 'navigation' => [], 'candidates' => []],
    ];
    expect(ashbyValidate($payload)->fails())->toBeFalse();

    $agentRun = ashbyRunningAgentRun();
    app(PersistInspectionResult::class)->persist($agentRun, ashbyValidate($payload)->validated());
    $agentRun->refresh();

    expect($agentRun->status)->toBe(AgentRunStatus::Failed)
        ->and($agentRun->failure_message)->toContain('refused by security validation')
        ->and($agentRun->result_summary['warnings'])->toContain('ashby_schema_refused:job_posting/redirect_refused')
        ->and(ApplicationQuestion::where('agent_run_id', $agentRun->id)->count())->toBe(0);
});
