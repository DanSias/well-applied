<?php

use App\Enums\AgentRunFailureCategory;
use App\Enums\AgentRunStatus;
use App\Enums\WorkflowStatus;
use App\Models\AgentRun;
use App\Models\WorkflowRun;
use App\Support\ApplicationPreparation\ClaimNextPreparationRun;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\PreparationFixtures as F;

beforeEach(function () {
    config(['services.browser_worker.token' => 'inspector-token', 'services.preparer_worker.token' => 'preparer-token', 'services.preparer_worker.claim_ttl' => 120]);
});

function prepHeaders(string $identity = 'prep-1'): array
{
    return ['Authorization' => 'Bearer preparer-token', 'X-Worker-Identity' => $identity];
}

function prepClaimOver(TestCase $t, string $identity = 'prep-1')
{
    return $t->withHeaders(prepHeaders($identity))->postJson('/api/worker/preparer/agent-runs/claim', ['worker_identity' => $identity]);
}

it('claims one queued run, oldest first, and hands out no applicant data', function () {
    ['run' => $first] = F::queuedRun();

    $response = prepClaimOver($this)->assertOk();

    expect($response->json('agent_run_id'))->toBe($first->id)
        ->and($response->json('application_url'))->toBe('https://job-boards.greenhouse.io/example/jobs/123456')
        ->and($response->json('resume'))->toBe(['sha256' => hash('sha256', F::PDF), 'bytes' => strlen(F::PDF)])
        ->and($response->json('lease_seconds'))->toBe(120);

    // Only identifiers, a lease and a hash: never an applicant value.
    $body = $response->getContent();
    expect($body)->not->toContain(F::EMAIL)->not->toContain('Alex')->not->toContain('Springfield')->not->toContain('github');

    $run = $first->fresh();
    expect($run->status)->toBe(AgentRunStatus::Running)
        ->and($run->worker_identity)->toBe('prep-1')
        ->and($run->claim_expires_at->isFuture())->toBeTrue()
        ->and($run->workflowStep->workflowRun->status)->toBe(WorkflowStatus::Running);
});

it('answers 204 when nothing is queued, and a second poller cannot take a claimed run', function () {
    prepClaimOver($this)->assertNoContent();
    F::queuedRun();

    prepClaimOver($this, 'prep-1')->assertOk();
    prepClaimOver($this, 'prep-2')->assertNoContent();
    expect(AgentRun::sole()->worker_identity)->toBe('prep-1');
});

it('makes the claim atomic: a claim that loses the conditional update takes nothing', function () {
    ['run' => $run] = F::queuedRun();
    $claims = app(ClaimNextPreparationRun::class);

    $a = $claims->claim('a');
    $b = $claims->claim('b');

    expect($a?->id)->toBe($run->id)->and($b)->toBeNull()
        ->and($run->fresh()->worker_identity)->toBe('a');
});

it('renews the lease on heartbeat and records the phase, for the owner only', function () {
    ['run' => $run] = F::queuedRun();
    prepClaimOver($this)->assertOk();
    $run->update(['claim_expires_at' => now()->addSeconds(5)]);

    $r = $this->withHeaders(prepHeaders())->postJson("/api/worker/preparer/agent-runs/{$run->id}/heartbeat", ['phase' => 'filling'])->assertOk();

    expect($r->json('accepted'))->toBeTrue()
        ->and($run->fresh()->claim_expires_at->greaterThan(now()->addSeconds(60)))->toBeTrue()
        ->and($run->fresh()->result_summary['phase'])->toBe('filling');

    // An unknown phase renews the lease but records nothing made up by the worker.
    $this->withHeaders(prepHeaders())->postJson("/api/worker/preparer/agent-runs/{$run->id}/heartbeat", ['phase' => 'rm -rf'])->assertOk();
    expect($run->fresh()->result_summary['phase'])->toBe('filling');

    // Another worker cannot renew it.
    $this->withHeaders(prepHeaders('prep-2'))->postJson("/api/worker/preparer/agent-runs/{$run->id}/heartbeat")->assertForbidden();
    $this->withHeaders(['Authorization' => 'Bearer preparer-token'])->postJson("/api/worker/preparer/agent-runs/{$run->id}/heartbeat")->assertForbidden();
});

it('cannot renew a lapsed claim: it is settled as failed and stays failed', function () {
    ['run' => $run] = F::queuedRun();
    prepClaimOver($this)->assertOk();
    $run->update(['claim_expires_at' => now()->subSecond()]);

    $this->withHeaders(prepHeaders())->postJson("/api/worker/preparer/agent-runs/{$run->id}/heartbeat")
        ->assertStatus(409)->assertJson(['accepted' => false, 'reason' => 'agent_run_not_running']);

    $run = $run->fresh();
    expect($run->status)->toBe(AgentRunStatus::Failed)
        ->and($run->failure_category)->toBe(AgentRunFailureCategory::ClaimTimeout)
        ->and($run->workflowStep->workflowRun->status)->toBe(WorkflowStatus::Failed);

    // A late heartbeat cannot bring it back.
    $this->withHeaders(prepHeaders())->postJson("/api/worker/preparer/agent-runs/{$run->id}/heartbeat")->assertStatus(409);
    expect($run->fresh()->status)->toBe(AgentRunStatus::Failed);
});

it('settles a lapsed claim on the next poll, never requeues it and never starts another run', function () {
    ['run' => $run] = F::queuedRun();
    prepClaimOver($this)->assertOk();
    $run->update(['claim_expires_at' => now()->subMinute()]);

    prepClaimOver($this, 'prep-2')->assertNoContent();

    expect($run->fresh()->status)->toBe(AgentRunStatus::Failed)
        ->and(AgentRun::count())->toBe(1)
        ->and(WorkflowRun::count())->toBe(1);
});

// ---- inputs ---------------------------------------------------------------------------------------------------

it('serves the exact frozen applicant values and resume only to the claimant of a live run', function () {
    ['run' => $run] = F::queuedRun();
    prepClaimOver($this)->assertOk();

    $input = $this->withHeaders(prepHeaders())->getJson("/api/worker/preparer/agent-runs/{$run->id}/input")->assertOk();
    expect($input->json('applicant.email'))->toBe(F::EMAIL)
        ->and($input->json('applicant.first_name'))->toBe('Alex')
        ->and($input->json('applicant'))->not->toHaveKeys(['linkedin_url', 'current_company'])
        ->and($input->json('application_url'))->toBe('https://job-boards.greenhouse.io/example/jobs/123456')
        ->and($input->json('resume.sha256'))->toBe(hash('sha256', F::PDF))
        ->and($input->headers->get('Cache-Control'))->toContain('no-store');

    $pdf = $this->withHeaders(prepHeaders())->get("/api/worker/preparer/agent-runs/{$run->id}/resume")->assertOk();
    expect($pdf->getContent())->toBe(F::PDF)
        ->and($pdf->headers->get('X-Resume-Sha256'))->toBe(hash('sha256', F::PDF))
        ->and($pdf->headers->get('Content-Type'))->toBe('application/pdf');
});

it('refuses inputs to a different worker, to an unclaimed run, to a lapsed claim and to a finished run', function () {
    ['run' => $run] = F::queuedRun();

    // Queued, never claimed: nobody owns it, so nobody can read it.
    $this->withHeaders(prepHeaders())->getJson("/api/worker/preparer/agent-runs/{$run->id}/input")->assertForbidden();
    $this->withHeaders(prepHeaders())->get("/api/worker/preparer/agent-runs/{$run->id}/resume")->assertForbidden();

    prepClaimOver($this)->assertOk();
    $this->withHeaders(prepHeaders('prep-2'))->getJson("/api/worker/preparer/agent-runs/{$run->id}/input")->assertForbidden();
    $this->withHeaders(prepHeaders('prep-2'))->get("/api/worker/preparer/agent-runs/{$run->id}/resume")->assertForbidden();

    $run->update(['claim_expires_at' => now()->subSecond()]);
    $this->withHeaders(prepHeaders())->getJson("/api/worker/preparer/agent-runs/{$run->id}/input")->assertStatus(409);
    $this->withHeaders(prepHeaders())->get("/api/worker/preparer/agent-runs/{$run->id}/resume")->assertStatus(409);
});

it('cannot reach another run\'s inputs, nor an inspection run through a preparer route', function () {
    ['run' => $mine] = F::queuedRun();
    prepClaimOver($this)->assertOk();
    $inspection = AgentRun::factory()->create(['status' => 'running', 'worker_identity' => 'prep-1', 'claim_expires_at' => now()->addMinute()]);

    $this->withHeaders(prepHeaders())->getJson("/api/worker/preparer/agent-runs/{$inspection->id}/input")->assertNotFound();
    $this->withHeaders(prepHeaders())->postJson("/api/worker/preparer/agent-runs/{$inspection->id}/heartbeat")->assertNotFound();
    $this->withHeaders(prepHeaders())->postJson('/api/worker/preparer/agent-runs/999999/heartbeat')->assertNotFound();
    expect($mine->fresh()->worker_identity)->toBe('prep-1');
});

it('reports damaged inputs as unavailable rather than serving them', function () {
    ['run' => $run] = F::queuedRun();
    prepClaimOver($this)->assertOk();
    $dir = "application-preparations/{$run->id}";
    Storage::disk('local')->put("{$dir}/input.json", '{"applicant":{"email":"someone-else@example.test"}}');
    Storage::disk('local')->put("{$dir}/resume-".hash('sha256', F::PDF).'.pdf', F::PDF_OTHER);

    $this->withHeaders(prepHeaders())->getJson("/api/worker/preparer/agent-runs/{$run->id}/input")->assertStatus(409)->assertJson(['error' => 'input_unavailable']);
    $this->withHeaders(prepHeaders())->get("/api/worker/preparer/agent-runs/{$run->id}/resume")->assertStatus(409);
});
