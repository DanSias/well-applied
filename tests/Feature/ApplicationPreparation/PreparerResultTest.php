<?php

use App\Enums\AgentRunFailureCategory;
use App\Enums\AgentRunStatus;
use App\Enums\WorkflowStatus;
use App\Models\AgentRun;
use App\Models\ApplicationQuestion;
use App\Models\Submission;
use App\Models\SubmissionEvent;
use App\Models\WorkflowRun;
use App\Support\ApplicationPreparation\DispatchApplicationPreparation;
use App\Support\ApplicationPreparation\PreparationArtifacts;
use App\Support\ApplicationPreparation\PreparationException;
use Illuminate\Support\Facades\Storage;
use Tests\Support\PreparationFixtures as F;

beforeEach(function () {
    config(['services.browser_worker.token' => 'inspector-token', 'services.preparer_worker.token' => 'preparer-token', 'services.preparer_worker.claim_ttl' => 120]);
});

function prepClaimedRun(): array
{
    $s = F::queuedRun();
    test()->withHeaders(['Authorization' => 'Bearer preparer-token'])->postJson('/api/worker/preparer/agent-runs/claim', ['worker_identity' => 'prep-1'])->assertOk();

    return $s + ['run' => $s['run']->fresh()];
}

function prepDeliver($t, AgentRun $run, array $payload, string $identity = 'prep-1')
{
    return $t->withHeaders(['Authorization' => 'Bearer preparer-token', 'X-Worker-Identity' => $identity])
        ->postJson("/api/worker/preparer/agent-runs/{$run->id}/result", $payload);
}

function prepStoredReport(AgentRun $run): array
{
    return app(PreparationArtifacts::class)->readReport($run->fresh());
}

it('keeps a delivered input_required report as a successful execution with its outcome kept apart', function () {
    ['run' => $run] = prepClaimedRun();

    prepDeliver($this, $run, F::delivery(F::report($run)))->assertOk()->assertJson(['accepted' => true, 'duplicate' => false]);

    $run = $run->fresh();
    expect($run->status)->toBe(AgentRunStatus::Succeeded)
        ->and($run->failure_category)->toBeNull()
        ->and($run->ats_detected)->toBe('greenhouse')
        ->and($run->workflowStep->status)->toBe(WorkflowStatus::Succeeded)
        ->and($run->workflowStep->workflowRun->status)->toBe(WorkflowStatus::Succeeded)
        ->and($run->workflowStep->workflowRun->failure_message)->toBeNull()
        ->and($run->result_summary['outcome'])->toBe('input_required')
        ->and($run->result_summary['reviewable'])->toBeTrue()
        ->and($run->result_summary['counts']['unresolvedRequired'])->toBe(1)
        ->and($run->result_summary['question_summary']['needsAnswer'])->toBe(1)
        ->and($run->result_summary['upload'])->toBe(['outcome' => 'accepted_filename_visible', 'http_status' => 201, 'filename_visible' => true, 'verified' => true, 'verification' => 'upload_accepted'])
        ->and($run->result_summary['safety']['failure'])->toBeFalse()
        ->and($run->result_summary['safety']['event_counts'])->toBe(['blocked_telemetry_request' => 1, 'allowed_exception_used' => 1])
        ->and(Submission::count())->toBe(0);

    // The ordinary summary carries no applicant value.
    expect(json_encode($run->result_summary))->not->toContain(F::EMAIL)->not->toContain('Alex');
});

it('keeps the full sanitized report privately, hashed, with required, manual and upload evidence', function () {
    ['run' => $run] = prepClaimedRun();
    prepDeliver($this, $run, F::delivery(F::report($run)))->assertOk();

    $run = $run->fresh();
    $report = prepStoredReport($run);

    Storage::disk('local')->assertExists("application-preparations/{$run->id}/report.json");
    expect($report['outcome'])->toBe('input_required')
        ->and($report['resume']['upload']['response']['status'])->toBe(201)
        ->and(collect($report['questions'])->where('review', 'needs_answer')->pluck('label')->all())->toBe(['Why do you want to join Example?'])
        ->and($report['submission'])->toBe(['assertion' => 'worker_did_not_submit', 'suspected' => false, 'blocked_attempts' => 0])
        ->and($run->result_summary['report_sha256'])->toBe(hash('sha256', Storage::disk('local')->get("application-preparations/{$run->id}/report.json")));

    // A report that no longer matches its recorded hash is not readable.
    Storage::disk('local')->put("application-preparations/{$run->id}/report.json", '{"outcome":"ready_for_review"}');
    expect(app(PreparationArtifacts::class)->readReport($run->fresh()))->toBeNull();
});

it('preserves a safety_failure report and never treats it as reviewable or ready', function () {
    ['run' => $run] = prepClaimedRun();
    $report = F::report($run, [
        'outcome' => 'safety_failure',
        'safetyEvents' => [['kind' => 'blocked_mutating_request', 'detail' => 'POST https://example.test/apply?token=SECRET [fetch, application/json, 10B]', 'at' => 'x', 'phase' => 'preparation']],
    ]);

    prepDeliver($this, $run, F::delivery($report))->assertOk();

    $run = $run->fresh();
    expect($run->status)->toBe(AgentRunStatus::Succeeded)
        ->and($run->result_summary['outcome'])->toBe('safety_failure')
        ->and($run->result_summary['reviewable'])->toBeFalse()
        ->and($run->result_summary['safety']['failure'])->toBeTrue();
    $stored = prepStoredReport($run);
    expect($stored['safety_events'][0]['kind'])->toBe('blocked_mutating_request')
        // A query string is never kept, even inside a free-text detail.
        ->and(json_encode($stored))->not->toContain('SECRET')->not->toContain('?token');
});

it('refuses to let a worker call a submission-like event ready for review', function (array $override) {
    ['run' => $run] = prepClaimedRun();

    prepDeliver($this, $run, F::delivery(F::report($run, ['outcome' => 'ready_for_review'] + $override)))->assertOk();

    expect($run->fresh()->result_summary['outcome'])->toBe('safety_failure')
        ->and($run->fresh()->result_summary['reported_outcome'])->toBe('ready_for_review')
        ->and($run->fresh()->result_summary['reviewable'])->toBeFalse()
        ->and(Submission::count())->toBe(0);
})->with([
    'a blocked mutating request' => [['safetyEvents' => [['kind' => 'blocked_mutating_request', 'detail' => 'POST https://x.test/', 'phase' => 'preparation']]]],
    'a blocked submit event' => [['safetyEvents' => [['kind' => 'submit_event_blocked', 'detail' => 'submit', 'phase' => 'preparation']]]],
    'an unexpected navigation' => [['safetyEvents' => [['kind' => 'unexpected_navigation', 'detail' => 'nav', 'phase' => 'preparation']]]],
    'a suspected submission' => [['safetyEvents' => [['kind' => 'suspected_submission', 'detail' => 'x', 'phase' => 'preparation']]]],
    'the worker suspecting submission' => [['submission' => ['assertion' => 'worker_did_not_submit', 'blockedAttempts' => 0, 'suspected' => true]]],
    'blocked attempts' => [['submission' => ['assertion' => 'worker_did_not_submit', 'blockedAttempts' => 2, 'suspected' => false]]],
]);

it('does not trust ready_for_review when the resume is not the one it was given, or was not verified', function (array $resume, array $flags) {
    ['run' => $run] = prepClaimedRun();
    $report = F::report($run, ['outcome' => 'ready_for_review']);
    $report['resume'] = $resume + $report['resume'];

    prepDeliver($this, $run, F::delivery($report))->assertOk();

    $summary = $run->fresh()->result_summary;
    expect($summary['outcome'])->toBe('blocked')
        ->and($summary['integrity_flags'])->toContain(...$flags)
        ->and($summary['reviewable'])->toBe(! in_array('resume_sha256_mismatch', $flags));
})->with([
    'another resume hash' => [['sha256' => str_repeat('a', 64)], ['resume_sha256_mismatch']],
    'not verified' => [['verified' => false], ['screenshots_missing']],
]);

it('keeps the ordinary reviewable states reviewable', function (string $outcome, bool $reviewable) {
    ['run' => $run] = prepClaimedRun();

    prepDeliver($this, $run, F::delivery(F::report($run, ['outcome' => $outcome])))->assertOk();

    expect($run->fresh()->result_summary['outcome'])->toBe($outcome)
        ->and($run->fresh()->result_summary['reviewable'])->toBe($reviewable);
})->with([
    'ready' => ['ready_for_review', true],
    'input required' => ['input_required', true],
    'blocked' => ['blocked', true],
    'unsupported' => ['unsupported', false],
]);

it('never creates a Submission or an inspection question, and never marks a job pursued or applied', function () {
    ['run' => $run, 'job' => $job] = prepClaimedRun();
    $before = $job->fresh()->only(['disposition', 'title', 'source_url']);

    prepDeliver($this, $run, F::delivery(F::report($run)))->assertOk();

    expect(Submission::count())->toBe(0)
        ->and(SubmissionEvent::count())->toBe(0)
        ->and(ApplicationQuestion::count())->toBe(0)
        ->and($job->fresh()->only(['disposition', 'title', 'source_url']))->toBe($before);
});

it('refuses a report that asserts anything but "worker did not submit", or claims a submission', function (array $change) {
    ['run' => $run] = prepClaimedRun();
    $report = array_replace_recursive(F::report($run), $change);

    prepDeliver($this, $run, F::delivery($report))->assertStatus(422);

    expect($run->fresh()->status)->toBe(AgentRunStatus::Running);
})->with([
    'asserts submitted' => [['submission' => ['assertion' => 'submitted']]],
    'asserts confirmed' => [['submission' => ['assertion' => 'application_confirmed']]],
    'empty assertion' => [['submission' => ['assertion' => '']]],
]);

it('refuses a report with no submission block at all', function () {
    ['run' => $run] = prepClaimedRun();
    $report = F::report($run);
    unset($report['submission']);

    prepDeliver($this, $run, F::delivery($report))->assertStatus(422)->assertJsonValidationErrors(['report.submission']);
});

it('drops everything outside the contract: no raw DOM, no controls, no submitted flag', function () {
    ['run' => $run] = prepClaimedRun();
    $report = F::report($run) + [
        'submitted' => true, 'applicationSubmitted' => true, 'confirmation' => 'Thanks for applying!',
        'controls' => [['text' => 'Submit application']], 'finalState' => ['x' => 1], 'dom' => '<html>secret</html>',
    ];
    $report['questions'][0]['options'] = ['a', 'b'];
    $report['questions'][0]['selected'] = ['a private demographic answer'];

    prepDeliver($this, $run, F::delivery($report))->assertOk();

    $stored = json_encode(prepStoredReport($run)).json_encode($run->fresh()->getAttributes());
    expect($stored)->not->toContain('Thanks for applying')->not->toContain('<html>')->not->toContain('private demographic')
        ->not->toContain('"submitted"')->not->toContain('Submit application');
});

it('keeps no value for a sensitive field', function () {
    ['run' => $run] = prepClaimedRun();
    $report = F::report($run);
    $report['fields'][] = ['label' => 'Gender', 'kind' => 'select', 'required' => false, 'status' => 'unanswered_optional', 'classification' => 'unknown', 'sensitive' => true, 'expected' => 'secret', 'actual' => 'secret'];

    prepDeliver($this, $run, F::delivery($report))->assertOk();

    $gender = collect(prepStoredReport($run)['fields'])->firstWhere('label', 'Gender');
    expect($gender['expected'])->toBeNull()->and($gender['actual'])->toBeNull();
});

it('rejects malformed reports and writes nothing', function (callable $break) {
    ['run' => $run] = prepClaimedRun();
    $payload = F::delivery(F::report($run));
    $payload = $break($payload);

    prepDeliver($this, $run, $payload)->assertStatus(422);

    expect($run->fresh()->status)->toBe(AgentRunStatus::Running)
        ->and(Storage::disk('local')->exists("application-preparations/{$run->id}/report.json"))->toBeFalse();
})->with([
    'an unknown status' => [fn ($p) => ['status' => 'done'] + $p],
    'no report' => [fn ($p) => ['status' => 'succeeded']],
    'a report on a failure' => [fn ($p) => ['status' => 'failed', 'failure_category' => 'unexpected_error', 'failure_message' => 'x'] + $p],
    'schema version 1' => [function ($p) {
        $p['report']['schemaVersion'] = 1;

        return $p;
    }],
    'an unknown outcome' => [function ($p) {
        $p['report']['outcome'] = 'submitted';

        return $p;
    }],
    'an unknown ats' => [function ($p) {
        $p['report']['ats'] = 'workday';

        return $p;
    }],
    'an unknown field status' => [function ($p) {
        $p['report']['fields'][0]['status'] = 'submitted';

        return $p;
    }],
    'an unknown safety kind' => [function ($p) {
        $p['report']['safetyEvents'][0]['kind'] = 'nothing_happened';

        return $p;
    }],
    'too many fields' => [function ($p) {
        $p['report']['fields'] = array_fill(0, 401, $p['report']['fields'][0]);

        return $p;
    }],
    'an over-long label' => [function ($p) {
        $p['report']['fields'][0]['label'] = str_repeat('x', 501);

        return $p;
    }],
    'a bad screenshot name' => [function ($p) {
        $p['report']['screenshots'][0]['file'] = '../evil.png';

        return $p;
    }],
    'a bad screenshot hash' => [function ($p) {
        $p['screenshots'] = [['file' => 'before-01of02-y00000.png', 'sha256' => 'nope']];

        return $p;
    }],
    'an upload status outside HTTP' => [function ($p) {
        $p['report']['resume']['upload']['response']['status'] = 99;

        return $p;
    }],
    'a non-array safety list' => [function ($p) {
        $p['report']['safetyEvents'] = 'none';

        return $p;
    }],
]);

it('rejects an oversized delivery outright', function () {
    ['run' => $run] = prepClaimedRun();
    $report = F::report($run);
    $report['warnings'] = array_fill(0, 90, str_repeat('w', 490));
    $report['reasons'] = array_fill(0, 90, str_repeat('r', 490));
    $payload = F::delivery($report);
    $payload['padding'] = str_repeat('p', PreparationArtifacts::MAX_JSON_BYTES);

    prepDeliver($this, $run, $payload)->assertStatus(413);
    expect($run->fresh()->status)->toBe(AgentRunStatus::Running);
});

it('records a delivered failure as a failed execution, with its category and a bounded message', function () {
    ['run' => $run] = prepClaimedRun();

    prepDeliver($this, $run, ['status' => 'failed', 'failure_category' => 'navigation_timeout', 'failure_message' => "page\x00 never\nloaded".str_repeat('x', 2000)])->assertStatus(422);
    prepDeliver($this, $run, ['status' => 'failed', 'failure_category' => 'navigation_timeout', 'failure_message' => "page\x00 never\nloaded"])->assertOk();

    $run = $run->fresh();
    expect($run->status)->toBe(AgentRunStatus::Failed)
        ->and($run->failure_category)->toBe(prepFailureCategory())
        ->and($run->failure_message)->toBe('page never loaded')
        ->and($run->workflowStep->workflowRun->status)->toBe(WorkflowStatus::Failed)
        ->and(Storage::disk('local')->exists("application-preparations/{$run->id}/report.json"))->toBeFalse();
});

function prepFailureCategory(): AgentRunFailureCategory
{
    return AgentRunFailureCategory::NavigationTimeout;
}

it('reserves claim_timeout for the server: a worker cannot report it', function () {
    ['run' => $run] = prepClaimedRun();

    prepDeliver($this, $run, ['status' => 'failed', 'failure_category' => 'claim_timeout', 'failure_message' => 'x'])->assertStatus(422);
});

// ---- repeated, stale and superseded deliveries -----------------------------------------------------------------

it('treats a repeated delivery as a duplicate and writes nothing again', function () {
    ['run' => $run] = prepClaimedRun();
    $payload = F::delivery(F::report($run));
    prepDeliver($this, $run, $payload)->assertOk()->assertJson(['duplicate' => false]);
    $first = $run->fresh();
    $reportBytes = Storage::disk('local')->get("application-preparations/{$run->id}/report.json");

    prepDeliver($this, $run, $payload)->assertOk()->assertJson(['accepted' => true, 'duplicate' => true]);
    // Even a different body cannot rewrite a finished run: the first delivery stands.
    $different = F::delivery(F::report($run, ['outcome' => 'ready_for_review']));
    prepDeliver($this, $run, $different)->assertOk()->assertJson(['duplicate' => true]);

    $after = $run->fresh();
    expect($after->finished_at->equalTo($first->finished_at))->toBeTrue()
        ->and($after->result_summary)->toBe($first->result_summary)
        ->and(Storage::disk('local')->get("application-preparations/{$run->id}/report.json"))->toBe($reportBytes)
        ->and(AgentRun::count())->toBe(1);
});

it('cannot overturn a finished run with a different kind of delivery', function () {
    ['run' => $run] = prepClaimedRun();
    prepDeliver($this, $run, F::delivery(F::report($run)))->assertOk();

    prepDeliver($this, $run, ['status' => 'failed', 'failure_category' => 'unexpected_error', 'failure_message' => 'late'])
        ->assertStatus(409)->assertJson(['accepted' => false, 'reason' => 'agent_run_not_running', 'current_status' => 'succeeded']);

    expect($run->fresh()->status)->toBe(AgentRunStatus::Succeeded)->and($run->fresh()->failure_message)->toBeNull();
});

it('rejects a result from a worker whose lease lapsed, and leaves the failed attempt untouched', function () {
    ['run' => $run] = prepClaimedRun();
    $run->update(['claim_expires_at' => now()->subSecond()]);

    prepDeliver($this, $run, F::delivery(F::report($run)))->assertStatus(409)->assertJson(['accepted' => false]);

    $run = $run->fresh();
    expect($run->status)->toBe(AgentRunStatus::Failed)
        ->and($run->failure_category)->toBe(AgentRunFailureCategory::ClaimTimeout)
        ->and($run->result_summary)->toBeNull()
        ->and(Storage::disk('local')->exists("application-preparations/{$run->id}/report.json"))->toBeFalse();

    // Retrying the same stale delivery never turns into a "duplicate" success.
    prepDeliver($this, $run, F::delivery(F::report($run)))->assertStatus(409);
});

it('rejects a result from a different worker or an unclaimed run', function () {
    ['run' => $run] = prepClaimedRun();
    ['run' => $queued] = F::queuedRun(job: ['source_url' => 'https://boards.greenhouse.io/other/jobs/9']);

    prepDeliver($this, $run, F::delivery(F::report($run)), 'prep-2')->assertForbidden();
    prepDeliver($this, $queued, F::delivery(F::report($queued)))->assertForbidden();

    expect($run->fresh()->status)->toBe(AgentRunStatus::Running)->and($queued->fresh()->status)->toBe(AgentRunStatus::Queued);
});

it('cannot write onto a run that was failed and superseded by a newer attempt', function () {
    ['run' => $old, 'job' => $job] = prepClaimedRun();
    $old->update(['claim_expires_at' => now()->subMinute()]);

    // The user explicitly starts a new attempt; the lapsed one is settled as failed first.
    app(DispatchApplicationPreparation::class)->dispatch($job);
    $new = AgentRun::latest('id')->first();

    prepDeliver($this, $old, F::delivery(F::report($old)))->assertStatus(409);

    expect($old->fresh()->status)->toBe(AgentRunStatus::Failed)
        ->and($new->fresh()->status)->toBe(AgentRunStatus::Queued)
        ->and($new->fresh()->result_summary)->toBeNull();
});

it('writes the report and the state together: if the report cannot be kept, the run is left running, not half-finished', function () {
    ['run' => $run] = prepClaimedRun();
    // A different report already sits where this one must go; write-once refuses to replace it.
    Storage::disk('local')->put("application-preparations/{$run->id}/report.json", '{"planted":true}');
    $this->withoutExceptionHandling();

    expect(fn () => prepDeliver($this, $run, F::delivery(F::report($run))))->toThrow(PreparationException::class);

    $run = $run->fresh();
    expect($run->status)->toBe(AgentRunStatus::Running)
        ->and($run->result_summary)->toBeNull()
        ->and($run->workflowStep->workflowRun->status)->toBe(WorkflowStatus::Running)
        ->and(Storage::disk('local')->get("application-preparations/{$run->id}/report.json"))->toBe('{"planted":true}');
});

// ---- screenshot manifest ----------------------------------------------------------------------------------------

it('cross-checks the screenshot manifest against what was actually uploaded', function () {
    ['run' => $run] = prepClaimedRun();
    $artifacts = app(PreparationArtifacts::class);
    $artifacts->putScreenshot($run, 'before-01of02-y00000.png', F::PNG);
    // The second one is declared, but never uploaded.

    prepDeliver($this, $run, F::delivery(F::report($run), ['before-01of02-y00000.png', 'after_fill-01of02-y00000.png']))->assertOk();

    $summary = $run->fresh()->result_summary;
    expect($summary['screenshots'])->toBe(['declared' => 2, 'stored' => 1])
        ->and($summary['integrity_flags'])->toBe(['screenshots_missing'])
        ->and(collect(prepStoredReport($run)['screenshots'])->pluck('stored', 'file')->all())->toBe(['before-01of02-y00000.png' => true, 'after_fill-01of02-y00000.png' => false]);
});

it('flags a screenshot whose bytes do not match what the worker declared', function () {
    ['run' => $run] = prepClaimedRun();
    app(PreparationArtifacts::class)->putScreenshot($run, 'before-01of02-y00000.png', F::PNG.'tampered');

    prepDeliver($this, $run, F::delivery(F::report($run), ['before-01of02-y00000.png', 'after_fill-01of02-y00000.png']))->assertOk();

    expect($run->fresh()->result_summary['integrity_flags'])->toContain('screenshot_hash_mismatch');
});

it('accepts a complete, matching set of screenshots as clean evidence', function () {
    ['run' => $run] = prepClaimedRun();
    $artifacts = app(PreparationArtifacts::class);
    $artifacts->putScreenshot($run, 'before-01of02-y00000.png', F::PNG);
    $artifacts->putScreenshot($run, 'after_fill-01of02-y00000.png', F::PNG);

    prepDeliver($this, $run, F::delivery(F::report($run), ['before-01of02-y00000.png', 'after_fill-01of02-y00000.png']))->assertOk();

    expect($run->fresh()->result_summary['screenshots'])->toBe(['declared' => 2, 'stored' => 2])
        ->and($run->fresh()->result_summary['integrity_flags'])->toBe([])
        ->and($run->fresh()->result_summary['reviewable'])->toBeTrue();
});

// ---- never rerun ------------------------------------------------------------------------------------------------

it('never reruns after a safety failure: nothing is requeued and no new attempt appears on its own', function () {
    ['run' => $run] = prepClaimedRun();
    $report = F::report($run, ['outcome' => 'safety_failure', 'safetyEvents' => [['kind' => 'suspected_submission', 'detail' => 'x', 'phase' => 'preparation']]]);
    prepDeliver($this, $run, F::delivery($report))->assertOk();

    $this->withHeaders(['Authorization' => 'Bearer preparer-token'])->postJson('/api/worker/preparer/agent-runs/claim', ['worker_identity' => 'prep-1'])->assertNoContent();

    expect(AgentRun::count())->toBe(1)
        ->and(WorkflowRun::count())->toBe(1)
        ->and($run->fresh()->status)->toBe(AgentRunStatus::Succeeded);
});
