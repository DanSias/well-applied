<?php

use App\Enums\AgentRunStatus;
use App\Enums\WorkflowStatus;
use App\Enums\WorkflowType;
use App\Models\AgentRun;
use App\Models\Application;
use App\Models\CareerProfile;
use App\Models\JobPosting;
use App\Models\Submission;
use App\Models\WorkflowRun;
use App\Support\ApplicationPreparation\ClaimNextPreparationRun;
use App\Support\ApplicationPreparation\DispatchApplicationPreparation;
use App\Support\ApplicationPreparation\PreparationArtifacts;
use App\Support\ApplicationPreparation\PreparationException;
use Illuminate\Support\Facades\Storage;
use Tests\Support\PreparationFixtures as F;

beforeEach(function () {
    config(['services.browser_worker.token' => 'inspector-token', 'services.preparer_worker.token' => 'preparer-token']);
});

it('cancels a queued attempt, keeping its history and removing the values it never used', function () {
    ['run' => $run, 'workflowRun' => $workflow] = F::queuedRun();
    $dir = "application-preparations/{$run->id}";
    Storage::disk('local')->assertExists("{$dir}/input.json");

    $this->postJson("/preparations/{$workflow->id}/cancel")->assertOk()->assertJson(['outcome' => 'cancelled', 'current_status' => 'cancelled']);

    $run = $run->fresh();
    expect($run->status)->toBe(AgentRunStatus::Cancelled)
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->workflowStep->status)->toBe(WorkflowStatus::Cancelled)
        ->and($workflow->fresh()->status)->toBe(WorkflowStatus::Cancelled)
        // History is kept: the rows, and the record of what had been prepared (hashes and key names, never values).
        ->and(AgentRun::count())->toBe(1)->and(WorkflowRun::count())->toBe(1)
        ->and($run->input_summary['resume_sha256'])->toBe(hash('sha256', F::PDF))
        ->and($run->input_summary['applicant_keys'])->toContain('email')
        ->and(Submission::count())->toBe(0);
    // The applicant values and resume were never handed to anyone, so they are gone.
    Storage::disk('local')->assertMissing("{$dir}/input.json");
    expect(Storage::disk('local')->files($dir))->toBe([]);
});

it('cannot be claimed once cancelled', function () {
    ['workflowRun' => $workflow] = F::queuedRun();
    $this->postJson("/preparations/{$workflow->id}/cancel")->assertOk();

    expect(app(ClaimNextPreparationRun::class)->claim('prep-1'))->toBeNull();
    $this->withHeaders(['Authorization' => 'Bearer preparer-token'])->postJson('/api/worker/preparer/agent-runs/claim', ['worker_identity' => 'prep-1'])->assertNoContent();
    expect(AgentRun::sole()->status)->toBe(AgentRunStatus::Cancelled);
});

it('is safe and predictable to repeat', function () {
    ['workflowRun' => $workflow] = F::queuedRun();

    $first = $this->postJson("/preparations/{$workflow->id}/cancel")->assertOk();
    $again = $this->postJson("/preparations/{$workflow->id}/cancel")->assertOk();
    $third = $this->postJson("/preparations/{$workflow->id}/cancel")->assertOk();

    expect($first->json('outcome'))->toBe('cancelled')
        ->and($again->json('outcome'))->toBe('already_cancelled')
        ->and($third->json('outcome'))->toBe('already_cancelled')
        ->and(AgentRun::count())->toBe(1);
});

it('is atomic with the claim: whichever wins, the other changes nothing', function () {
    // The claim wins: a later cancel cannot touch the claimed run.
    ['run' => $run, 'workflowRun' => $workflow] = F::queuedRun();
    $claimed = app(ClaimNextPreparationRun::class)->claim('prep-1');

    $this->postJson("/preparations/{$workflow->id}/cancel")->assertStatus(409)->assertJson(['outcome' => 'not_cancellable', 'current_status' => 'running']);
    expect($claimed->id)->toBe($run->id)
        ->and($run->fresh()->status)->toBe(AgentRunStatus::Running)
        ->and($run->fresh()->worker_identity)->toBe('prep-1')
        ->and($workflow->fresh()->status)->toBe(WorkflowStatus::Running);
    // Its inputs are untouched: the worker still needs them.
    expect(app(PreparationArtifacts::class)->readInput($run->fresh()))->toBeArray();
});

it('does not cancel a running, finished or failed attempt', function (AgentRunStatus $status) {
    ['run' => $run, 'workflowRun' => $workflow] = F::queuedRun();
    $run->update(['status' => $status, 'worker_identity' => 'prep-1']);

    $this->postJson("/preparations/{$workflow->id}/cancel")->assertStatus(409)->assertJson(['outcome' => 'not_cancellable']);

    expect($run->fresh()->status)->toBe($status);
})->with([[AgentRunStatus::Running], [AgentRunStatus::Succeeded], [AgentRunStatus::Failed]]);

it('does not cancel an attempt a worker has an owner on, even if it still reads as queued', function () {
    ['run' => $run, 'workflowRun' => $workflow] = F::queuedRun();
    $run->update(['worker_identity' => 'prep-1']);

    $this->postJson("/preparations/{$workflow->id}/cancel")->assertStatus(409);
    expect($run->fresh()->status)->toBe(AgentRunStatus::Queued);
});

it('only cancels preparations of the current profile', function () {
    F::queuedRun();
    $foreign = WorkflowRun::factory()->for(Application::factory()->for(JobPosting::factory()->create(['career_profile_id' => CareerProfile::factory()->create()->id]), 'jobPosting'))
        ->create(['workflow_type' => WorkflowType::ApplicationPreparation]);
    $inspection = WorkflowRun::factory()->create();

    $this->postJson("/preparations/{$foreign->id}/cancel")->assertNotFound();
    $this->postJson("/preparations/{$inspection->id}/cancel")->assertNotFound();
    $this->postJson('/preparations/999999/cancel')->assertNotFound();
});

it('lets a person start a fresh attempt after cancelling, with history intact', function () {
    ['job' => $job, 'workflowRun' => $workflow] = F::queuedRun();
    $this->postJson("/preparations/{$workflow->id}/cancel")->assertOk();

    ['workflowRun' => $second, 'created' => $created] = app(DispatchApplicationPreparation::class)->dispatch($job);

    expect($created)->toBeTrue()
        ->and($second->id)->not->toBe($workflow->id)
        ->and(AgentRun::count())->toBe(2)
        ->and(AgentRun::oldest('id')->first()->status)->toBe(AgentRunStatus::Cancelled)
        ->and(Submission::count())->toBe(0);
});

it('shows a cancelled attempt in the read-only state without values', function () {
    ['workflowRun' => $workflow, 'job' => $job] = F::queuedRun();
    $this->postJson("/preparations/{$workflow->id}/cancel")->assertOk();
    $application = Application::where('job_posting_id', $job->id)->sole();

    $view = $this->getJson("/applications/{$application->id}/preparation")->assertOk();

    expect($view->json('workflow_run.status'))->toBe('cancelled')
        ->and($view->json('attempt.status'))->toBe('cancelled')
        ->and($view->getContent())->not->toContain(F::EMAIL);
});

// ---- the local-fixture opt-in -----------------------------------------------------------------------------------

it('refuses a local fixture URL unless the fixture opt-in is on, and then only http localhost', function () {
    $s = F::scenario(job: ['source_url' => 'http://127.0.0.1:4010/greenhouse-form.html']);

    expect(fn () => app(DispatchApplicationPreparation::class)->dispatch($s['job']))->toThrow(PreparationException::class);

    config(['services.preparer_worker.allow_local_fixture' => true]);
    expect(app(DispatchApplicationPreparation::class)->dispatch($s['job'])['created'])->toBeTrue();
    expect(AgentRun::sole()->input_summary['application_url'])->toBe('http://127.0.0.1:4010/greenhouse-form.html');

    // Even enabled, it is not a way to reach anything that is not local.
    foreach (['http://example.test/form', 'https://127.0.0.1.evil.test/x', 'http://user:pw@127.0.0.1/x', 'http://192.168.1.5/x'] as $url) {
        $s['job']->update(['source_url' => $url]);
        AgentRun::query()->update(['status' => AgentRunStatus::Failed]);
        WorkflowRun::query()->update(['status' => WorkflowStatus::Failed]);
        expect(fn () => app(DispatchApplicationPreparation::class)->dispatch($s['job']->fresh()))->toThrow(PreparationException::class);
    }
});

it('keeps the fixture opt-in off by default', function () {
    expect(config('services.preparer_worker.allow_local_fixture'))->toBeFalse();
});
