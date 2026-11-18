<?php

use App\Models\AgentRun;
use App\Models\Application;
use App\Models\CareerProfile;
use App\Models\JobPosting;
use App\Models\Submission;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Support\ApplicationPreparation\DispatchApplicationPreparation;
use App\Support\ApplicationPreparation\PreparationArtifacts;
use Illuminate\Support\Facades\Storage;
use Tests\Support\PreparationFixtures as F;

beforeEach(function () {
    config(['services.browser_worker.token' => 'inspector-token', 'services.preparer_worker.token' => 'preparer-token']);
});

/** A finished attempt with a delivered report and its screenshots, built through the real protocol. */
function finishedAttempt(array $report = [], array $shots = ['before-01of02-y00000.png', 'after_fill-01of02-y00000.png'], array $job = [], string $png = F::PNG, ?JobPosting $on = null): array
{
    if ($on !== null) {
        app(DispatchApplicationPreparation::class)->dispatch($on);
        $s = ['job' => $on, 'run' => AgentRun::where('agent_type', 'application_preparer')->latest('id')->firstOrFail()];
    } else {
        $s = F::queuedRun(job: $job);
    }
    $run = $s['run'];
    test()->withHeaders(['Authorization' => 'Bearer preparer-token'])->postJson('/api/worker/preparer/agent-runs/claim', ['worker_identity' => 'prep-1'])->assertOk();
    $artifacts = app(PreparationArtifacts::class);
    foreach ($shots as $name) {
        $artifacts->putScreenshot($run, $name, $png.$run->id);
    }
    $payload = F::delivery(F::report($run->fresh(), $report), []);
    $payload['screenshots'] = array_map(fn ($f) => ['file' => $f, 'sha256' => hash('sha256', $png.$run->id)], $shots);
    test()->withHeaders(['Authorization' => 'Bearer preparer-token', 'X-Worker-Identity' => 'prep-1'])->postJson("/api/worker/preparer/agent-runs/{$run->id}/result", $payload)->assertOk();

    return $s + ['run' => $run->fresh()];
}

it('shows a finished attempt as a review: summary, lists, evidence and an explicit "not submitted"', function () {
    ['run' => $run] = finishedAttempt();

    $r = $this->getJson("/preparation-attempts/{$run->id}")->assertOk();

    expect($r->json('available'))->toBeTrue()
        ->and($r->json('outcome'))->toBe('input_required')
        ->and($r->json('submission.occurred'))->toBeFalse()
        ->and($r->json('submission.statement'))->toContain('Nothing was submitted')
        ->and($r->json('resume.sha256'))->toBe(hash('sha256', F::PDF))
        ->and($r->json('resume.verified'))->toBeTrue()
        ->and($r->json('resume.reported_sha256_matches'))->toBeTrue()
        ->and($r->json('resume.upload'))->toMatchArray(['outcome' => 'accepted_filename_visible', 'http_status' => 201, 'filename_visible' => true])
        ->and(collect($r->json('verified'))->pluck('label')->all())->toBe(['First Name'])
        ->and(collect($r->json('needs_answer'))->pluck('label')->all())->toBe(['Why do you want to join Example?'])
        ->and($r->json('counts.filledVerified'))->toBe(2)
        ->and($r->json('screenshots'))->toHaveCount(2)
        ->and($r->json('screenshots.0.url'))->toBe("/preparation-attempts/{$run->id}/screenshots/before-01of02-y00000.png")
        ->and($r->json('screenshots.0.stored'))->toBeTrue()
        ->and($r->headers->get('Cache-Control'))->toContain('no-store');
});

it('never puts a typed value, an option, a path or a worker claim into the review', function () {
    ['run' => $run] = finishedAttempt();

    $text = $this->getJson("/preparation-attempts/{$run->id}")->getContent();

    expect($text)->not->toContain(F::EMAIL)->not->toContain('"expected"')->not->toContain('"actual"')
        ->not->toContain('"options"')->not->toContain('application-preparations/')->not->toContain('%PDF');
});

it('keeps an unlabelled or ambiguous question unlabelled, never borrowed from an option', function () {
    ['run' => $run] = finishedAttempt(['questions' => [
        ['label' => null, 'labelSource' => 'unresolved', 'kind' => 'checkbox', 'required' => true, 'status' => 'unresolved_required', 'review' => 'needs_answer', 'sensitive' => false, 'provenance' => ['source' => 'none']],
        ['label' => 'Attach', 'labelSource' => 'dom_label_for', 'kind' => 'file', 'required' => false, 'status' => 'filled_verified', 'review' => 'complete', 'sensitive' => false, 'provenance' => ['source' => 'chosen_resume']],
        ['label' => 'Pronouns', 'labelSource' => 'unresolved', 'kind' => 'select', 'required' => false, 'status' => 'unanswered_optional', 'review' => 'optional_skipped', 'sensitive' => false, 'provenance' => ['source' => 'none']],
    ]]);

    $r = $this->getJson("/preparation-attempts/{$run->id}")->assertOk();

    expect($r->json('needs_answer.0.label'))->toBeNull()
        ->and($r->json('verified.0.label'))->toBe('Attach')
        ->and($r->json('optional_skipped.0.label'))->toBeNull();
});

it('buckets the logical questions: manual actions, checks, failures, optional skips', function () {
    ['run' => $run] = finishedAttempt(['questions' => [
        ['label' => 'Location', 'kind' => 'text', 'required' => true, 'status' => 'manual_action_required', 'review' => 'needs_person', 'sensitive' => false],
        ['label' => 'Country', 'kind' => 'text', 'required' => true, 'status' => 'unverified', 'review' => 'needs_check', 'sensitive' => false],
        ['label' => 'Phone', 'kind' => 'text', 'required' => true, 'status' => 'error', 'review' => 'failed', 'sensitive' => false],
        ['label' => 'Languages', 'kind' => 'checkbox', 'required' => false, 'status' => 'unanswered_optional', 'review' => 'optional_skipped', 'sensitive' => false],
    ]]);

    $r = $this->getJson("/preparation-attempts/{$run->id}");

    expect($r->json('needs_person.0.label'))->toBe('Location')
        ->and($r->json('needs_check.0.label'))->toBe('Country')
        ->and($r->json('failed.0.label'))->toBe('Phone')
        ->and($r->json('optional_skipped.0.label'))->toBe('Languages')
        ->and($r->json('needs_answer'))->toBe([]);
});

it('shows a safety_failure with its events and never as reviewable or ready', function () {
    ['run' => $run] = finishedAttempt(['outcome' => 'safety_failure', 'safetyEvents' => [
        ['kind' => 'blocked_mutating_request', 'detail' => 'POST https://x.test/apply?token=SECRET', 'at' => 'n', 'phase' => 'preparation'],
        ['kind' => 'blocked_telemetry_request', 'detail' => 'greenhouse snowplow analytics beacon', 'at' => 'n', 'phase' => 'preparation'],
    ]]);

    $r = $this->getJson("/preparation-attempts/{$run->id}");

    expect($r->json('outcome'))->toBe('safety_failure')
        ->and($r->json('reviewable'))->toBeFalse()
        ->and($r->json('submission.occurred'))->toBeFalse()
        ->and(collect($r->json('safety_events'))->pluck('failure', 'kind')->all())->toBe(['blocked_mutating_request' => true, 'blocked_telemetry_request' => false])
        ->and($r->getContent())->not->toContain('SECRET');
});

it('shows a delivered failure and a cancelled attempt without a report', function () {
    $s = F::queuedRun();
    $run = $s['run'];
    $this->withHeaders(['Authorization' => 'Bearer preparer-token'])->postJson('/api/worker/preparer/agent-runs/claim', ['worker_identity' => 'prep-1'])->assertOk();
    $this->withHeaders(['Authorization' => 'Bearer preparer-token', 'X-Worker-Identity' => 'prep-1'])
        ->postJson("/api/worker/preparer/agent-runs/{$run->id}/result", ['status' => 'failed', 'failure_category' => 'navigation_timeout', 'failure_message' => 'page never loaded'])->assertOk();

    $r = $this->getJson("/preparation-attempts/{$run->id}")->assertOk();
    expect($r->json('available'))->toBeFalse()
        ->and($r->json('failure_category'))->toBe('navigation_timeout')
        ->and($r->json('failure_message'))->toBe('page never loaded')
        ->and($r->json('submission.occurred'))->toBeFalse();
});

// ---- scoped, private screenshot access ---------------------------------------------------------------------------

it('serves a screenshot privately, as an inline PNG that no cache keeps', function () {
    ['run' => $run] = finishedAttempt();

    $r = $this->get("/preparation-attempts/{$run->id}/screenshots/before-01of02-y00000.png")->assertOk();

    expect($r->getContent())->toBe(F::PNG.$run->id)
        ->and($r->headers->get('Content-Type'))->toBe('image/png')
        ->and($r->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')
        ->and($r->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($r->headers->get('Content-Disposition'))->toStartWith('inline');
});

it('isolates attempts: the same screenshot name in two attempts serves each its own bytes', function () {
    ['run' => $a, 'job' => $job] = finishedAttempt();
    // The first attempt has finished, so the same job can be prepared again: a second, separate attempt.
    ['run' => $b] = finishedAttempt(on: $job);

    $one = $this->get("/preparation-attempts/{$a->id}/screenshots/before-01of02-y00000.png")->getContent();
    $two = $this->get("/preparation-attempts/{$b->id}/screenshots/before-01of02-y00000.png")->getContent();

    expect($one)->toBe(F::PNG.$a->id)->and($two)->toBe(F::PNG.$b->id)->and($one)->not->toBe($two);
});

it('refuses another profile\'s attempt, an inspection run, an unknown run and a screenshot that does not exist', function () {
    ['run' => $run] = finishedAttempt();
    $foreignJob = JobPosting::factory()->create(['career_profile_id' => CareerProfile::factory()->create()->id, 'source_url' => 'https://boards.greenhouse.io/f/jobs/1']);
    $foreign = Application::factory()->for($foreignJob, 'jobPosting')->create();
    $foreignRun = AgentRun::factory()->create(['agent_type' => 'application_preparer', 'workflow_step_id' => WorkflowStep::factory()->for(WorkflowRun::factory()->for($foreign)->create(['workflow_type' => 'application_preparation']), 'workflowRun')->create()->id]);
    $inspection = AgentRun::factory()->create();

    $this->getJson("/preparation-attempts/{$foreignRun->id}")->assertNotFound();
    $this->get("/preparation-attempts/{$foreignRun->id}/screenshots/before-01of02-y00000.png")->assertNotFound();
    $this->getJson("/preparation-attempts/{$inspection->id}")->assertNotFound();
    $this->getJson('/preparation-attempts/999999')->assertNotFound();
    $this->get("/preparation-attempts/{$run->id}/screenshots/before-09of09-y00000.png")->assertNotFound();
});

it('serves nothing that is not a screenshot name, so no path can be chosen', function (string $name) {
    ['run' => $run] = finishedAttempt();
    Storage::disk('local')->put("application-preparations/{$run->id}/input.json", '{"secret":"x"}');

    $this->get("/preparation-attempts/{$run->id}/screenshots/{$name}")->assertNotFound();
})->with([
    'the input' => ['input.json'], 'the report' => ['report.json'], 'the resume' => ['resume.pdf'],
    'traversal' => ['..%2F..%2Finput.json'], 'a nested path' => ['screenshots%2Fbefore-01of02-y00000.png'],
    'another extension' => ['before-01of02-y00000.php'], 'uppercase' => ['BEFORE-01of02-y00000.png'],
]);

it('does not expose the private disk: nothing is reachable by a storage path', function () {
    ['run' => $run] = finishedAttempt();

    expect($this->get("/storage/application-preparations/{$run->id}/report.json")->status())->toBeIn([403, 404]);
    expect($this->get("/application-preparations/{$run->id}/screenshots/before-01of02-y00000.png")->status())->toBe(404);
    expect(Storage::disk('public')->allFiles())->not->toContain("application-preparations/{$run->id}/report.json");
});

it('never creates a Submission while showing evidence', function () {
    ['run' => $run] = finishedAttempt();
    $this->getJson("/preparation-attempts/{$run->id}")->assertOk();
    $this->get("/preparation-attempts/{$run->id}/screenshots/before-01of02-y00000.png")->assertOk();

    expect(Submission::count())->toBe(0);
});
