<?php

use App\Models\AgentRun;
use App\Models\Application;
use App\Models\Submission;
use App\Support\ApplicationPreparation\DispatchApplicationPreparation;
use App\Support\ApplicationPreparation\PreparationArtifacts;
use App\Support\ApplicationPreparation\PresentPreparationPanel;
use Tests\Support\PreparationFixtures as F;

beforeEach(function () {
    config(['services.browser_worker.token' => 'inspector-token', 'services.preparer_worker.token' => 'preparer-token']);
});

function q(array $over): array
{
    return array_replace([
        'label' => 'Email', 'labelSource' => 'dom_label_for', 'kind' => 'text', 'required' => true, 'status' => 'filled_verified',
        'review' => 'complete', 'sensitive' => false, 'provenance' => ['source' => 'applicant_fact', 'key' => 'email'],
        'entered' => F::EMAIL, 'intended' => null, 'page_selected' => [], 'note' => null,
    ], $over);
}

function deliverQuestions(array $questions, array $job = []): AgentRun
{
    $s = F::queuedRun(job: $job);
    $run = $s['run'];
    test()->withHeaders(['Authorization' => 'Bearer preparer-token'])->postJson('/api/worker/preparer/agent-runs/claim', ['worker_identity' => 'prep-1'])->assertOk();
    test()->withHeaders(['Authorization' => 'Bearer preparer-token', 'X-Worker-Identity' => 'prep-1'])
        ->postJson("/api/worker/preparer/agent-runs/{$run->id}/result", F::delivery(F::report($run->fresh(), ['questions' => $questions])))->assertOk();

    return $run->fresh();
}

it('shows what the worker read back, with where it came from, in the review only', function () {
    $run = deliverQuestions([q([])]);

    $r = $this->getJson("/preparation-attempts/{$run->id}")->assertOk();

    expect($r->json('evidence'))->toBe(['observed_at' => 'preparation', 'final_state_captured' => false])
        ->and($r->json('verified.0'))->toMatchArray(['label' => 'Email', 'entered' => F::EMAIL, 'intended' => null, 'page_selected' => []])
        ->and($r->json('verified.0.provenance'))->toBe(['source' => 'applicant_fact', 'key' => 'email']);
});

it('distinguishes what was entered, what a person must still do, and what the page already had', function () {
    $run = deliverQuestions([
        q([]),
        q(['label' => 'Current location', 'status' => 'manual_action_required', 'review' => 'needs_person', 'entered' => null, 'intended' => 'Springfield, USA', 'provenance' => ['source' => 'applicant_fact', 'key' => 'location'], 'note' => 'typeahead: a suggestion must be selected by a person']),
        q(['label' => 'Preferred contact', 'kind' => 'radio', 'status' => 'unanswered_optional', 'review' => 'optional_skipped', 'required' => false, 'entered' => null, 'provenance' => ['source' => 'none'], 'page_selected' => ['Email']]),
        q(['label' => 'Attach', 'kind' => 'file', 'provenance' => ['source' => 'chosen_resume'], 'entered' => null, 'intended' => 'resume.pdf']),
    ]);

    $r = $this->getJson("/preparation-attempts/{$run->id}");

    expect($r->json('needs_person.0'))->toMatchArray(['label' => 'Current location', 'entered' => null, 'intended' => 'Springfield, USA'])
        ->and($r->json('needs_person.0.note'))->toContain('typeahead')
        ->and($r->json('optional_skipped.0'))->toMatchArray(['entered' => null, 'intended' => null, 'page_selected' => ['Email']])
        ->and(collect($r->json('verified'))->firstWhere('label', 'Attach'))->toMatchArray(['entered' => null, 'intended' => 'resume.pdf']);
});

it('redacts every value of a sensitive question, by the worker\'s flag and by its label alone', function (array $over) {
    $run = deliverQuestions([q($over + ['entered' => 'Female', 'intended' => 'Female', 'page_selected' => ['Female'], 'note' => 'a private note'])]);

    $item = collect($this->getJson("/preparation-attempts/{$run->id}")->json())->only(['verified', 'needs_answer', 'optional_skipped', 'needs_person', 'needs_check', 'failed'])->flatten(1)->first();
    $stored = json_encode(app(PreparationArtifacts::class)->readReport($run));

    expect($item)->toMatchArray(['entered' => null, 'intended' => null, 'page_selected' => [], 'note' => null])
        ->and($stored)->not->toContain('Female')->not->toContain('a private note');
})->with([
    'the worker\'s sensitive flag' => [['label' => 'Tell us more', 'sensitive' => true, 'provenance' => ['source' => 'approved_answer']]],
    'a demographic label' => [['label' => 'Gender', 'sensitive' => false, 'provenance' => ['source' => 'approved_answer']]],
    'a veteran label' => [['label' => 'Veteran status', 'sensitive' => false, 'provenance' => ['source' => 'none']]],
    'a hispanic label' => [['label' => 'Are you Hispanic/Latino?', 'sensitive' => false, 'provenance' => ['source' => 'none']]],
    'a disability label' => [['label' => 'Disability status', 'sensitive' => false, 'provenance' => ['source' => 'none']]],
    'a password label' => [['label' => 'Password', 'sensitive' => false, 'provenance' => ['source' => 'applicant_fact']]],
]);

it('keeps a value only where its provenance justifies it', function () {
    $run = deliverQuestions([
        // A value for a question the worker did not fill, a verified question with no profile source, a group, a file.
        q(['label' => 'A', 'provenance' => ['source' => 'none'], 'entered' => 'smuggled', 'status' => 'filled_verified']),
        q(['label' => 'B', 'kind' => 'checkbox', 'entered' => 'smuggled', 'provenance' => ['source' => 'applicant_fact']]),
        q(['label' => 'C', 'kind' => 'file', 'entered' => 'smuggled', 'provenance' => ['source' => 'chosen_resume']]),
        q(['label' => 'D', 'status' => 'unverified', 'review' => 'needs_check', 'entered' => 'smuggled', 'intended' => 'ok-intended']),
        q(['label' => 'E', 'provenance' => ['source' => 'applicant_fact'], 'page_selected' => ['smuggled']]),
    ]);

    $text = json_encode($this->getJson("/preparation-attempts/{$run->id}")->json());

    expect($text)->not->toContain('smuggled')->and($text)->toContain('ok-intended');
});

it('bounds every value and ignores an option list or anything outside the contract', function () {
    $run = deliverQuestions([q(['entered' => str_repeat('x', 500), 'options' => ['secret option'], 'selected' => ['secret selected'], 'controls' => ['f1'], 'expected' => 'raw expected'])]);

    $stored = json_encode(app(PreparationArtifacts::class)->readReport($run));

    expect($stored)->not->toContain('secret option')->not->toContain('secret selected')->not->toContain('raw expected');
});

it('rejects an over-long or malformed value outright, writing nothing', function (array $over) {
    $s = F::queuedRun();
    $run = $s['run'];
    $this->withHeaders(['Authorization' => 'Bearer preparer-token'])->postJson('/api/worker/preparer/agent-runs/claim', ['worker_identity' => 'prep-1'])->assertOk();

    $this->withHeaders(['Authorization' => 'Bearer preparer-token', 'X-Worker-Identity' => 'prep-1'])
        ->postJson("/api/worker/preparer/agent-runs/{$run->id}/result", F::delivery(F::report($run->fresh(), ['questions' => [q($over)]])))->assertStatus(422);

    expect($run->fresh()->status->value)->toBe('running');
})->with([
    'a 501-character value' => [['entered' => str_repeat('x', 501)]],
    'a 501-character intended value' => [['intended' => str_repeat('x', 501)]],
    'too many page selections' => [['page_selected' => array_fill(0, 21, 'x')]],
    'an unknown provenance key' => [['provenance' => ['source' => 'applicant_fact', 'key' => 'password']]],
    'a long note' => [['note' => str_repeat('n', 301)]],
]);

it('keeps values out of every ordinary endpoint: the polling state, the workspace props and the worker presence', function () {
    $run = deliverQuestions([q([]), q(['label' => 'Current location', 'status' => 'manual_action_required', 'review' => 'needs_person', 'entered' => null, 'intended' => 'Springfield, USA'])]);
    $job = $run->workflowStep->workflowRun->application->jobPosting;
    $application = Application::where('job_posting_id', $job->id)->sole();

    $polling = $this->getJson("/applications/{$application->id}/preparation")->getContent();
    $props = json_encode(app(PresentPreparationPanel::class)->present($job));

    expect($polling.$props)->not->toContain(F::EMAIL)->not->toContain('Springfield, USA');
    // Only the dedicated review endpoint carries them.
    expect($this->getJson("/preparation-attempts/{$run->id}")->getContent())->toContain(F::EMAIL)->toContain('Springfield, USA');
});

it('scopes the evidence to its own attempt: a second attempt never shows the first one\'s values', function () {
    $first = deliverQuestions([q(['entered' => 'first-attempt-value@example.test'])]);
    $job = $first->workflowStep->workflowRun->application->jobPosting;
    app(DispatchApplicationPreparation::class)->dispatch($job);
    $second = AgentRun::where('agent_type', 'application_preparer')->latest('id')->first();
    $this->withHeaders(['Authorization' => 'Bearer preparer-token'])->postJson('/api/worker/preparer/agent-runs/claim', ['worker_identity' => 'prep-1'])->assertOk();
    $this->withHeaders(['Authorization' => 'Bearer preparer-token', 'X-Worker-Identity' => 'prep-1'])
        ->postJson("/api/worker/preparer/agent-runs/{$second->id}/result", F::delivery(F::report($second->fresh(), ['questions' => [q(['entered' => 'second-attempt-value@example.test'])]])))->assertOk();

    $one = $this->getJson("/preparation-attempts/{$first->id}")->getContent();
    $two = $this->getJson("/preparation-attempts/{$second->id}")->getContent();

    expect($one)->toContain('first-attempt-value')->not->toContain('second-attempt-value')
        ->and($two)->toContain('second-attempt-value')->not->toContain('first-attempt-value')
        ->and(Submission::count())->toBe(0);
});
