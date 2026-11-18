<?php

use App\Models\AgentRun;
use App\Models\Submission;
use App\Models\WorkerHeartbeat;
use Tests\Support\PreparationFixtures as F;

beforeEach(function () {
    config(['services.browser_worker.token' => 'inspector-token', 'services.preparer_worker.token' => 'preparer-token']);
});

function prepAuthHeaders(): array
{
    return ['Authorization' => 'Bearer preparer-token', 'X-Worker-Identity' => 'prep-1'];
}

it('rejects every preparer route without the preparer token', function (string $method, string $uri) {
    ['run' => $run] = F::queuedRun();
    $uri = str_replace('{id}', (string) $run->id, $uri);

    $this->json($method, $uri)->assertUnauthorized();
    $this->withHeader('Authorization', 'Bearer wrong')->json($method, $uri)->assertUnauthorized();
    // The inspector's token opens nothing here.
    $this->withHeader('Authorization', 'Bearer inspector-token')->json($method, $uri)->assertUnauthorized();
})->with([
    ['POST', '/api/worker/preparer/agent-runs/claim'],
    ['GET', '/api/worker/preparer/agent-runs/{id}/input'],
    ['GET', '/api/worker/preparer/agent-runs/{id}/resume'],
    ['POST', '/api/worker/preparer/agent-runs/{id}/heartbeat'],
    ['PUT', '/api/worker/preparer/agent-runs/{id}/screenshots/before-01of01-y00000.png'],
    ['POST', '/api/worker/preparer/agent-runs/{id}/result'],
]);

it('fails closed when the preparer token is unset', function () {
    config(['services.preparer_worker.token' => '']);

    $this->postJson('/api/worker/preparer/agent-runs/claim')->assertUnauthorized();
    $this->withHeader('Authorization', 'Bearer ')->postJson('/api/worker/preparer/agent-runs/claim')->assertUnauthorized();
});

it('refuses a preparer token that equals the inspector token, so one secret never opens both doors', function () {
    config(['services.browser_worker.token' => 'same', 'services.preparer_worker.token' => 'same']);

    $this->withHeader('Authorization', 'Bearer same')->postJson('/api/worker/preparer/agent-runs/claim')->assertUnauthorized();
});

it('accepts the preparer token on the claim route', function () {
    $this->withHeaders(prepAuthHeaders())->postJson('/api/worker/preparer/agent-runs/claim')->assertNoContent();
});

it('does not accept the preparer token on the inspector routes', function () {
    $this->withHeader('Authorization', 'Bearer preparer-token')->postJson('/api/worker/agent-runs/claim')->assertUnauthorized();
    $agentRun = AgentRun::factory()->create();
    $this->withHeader('Authorization', 'Bearer preparer-token')->postJson("/api/worker/agent-runs/{$agentRun->id}/result", ['status' => 'failed'])->assertUnauthorized();
});

it('never lets the inspector read or write a preparation run: its result endpoint does not find one', function () {
    ['run' => $run] = F::queuedRun();
    $run->update(['status' => 'running', 'worker_identity' => 'prep-1', 'claim_expires_at' => now()->addMinute()]);

    $this->withHeader('Authorization', 'Bearer inspector-token')
        ->postJson("/api/worker/agent-runs/{$run->id}/result", ['status' => 'failed', 'failure_category' => 'unexpected_error', 'failure_message' => 'x'])
        ->assertNotFound();

    expect($run->fresh()->status->value)->toBe('running');
});

it('never lets the inspector claim a preparation run, nor the preparer claim an inspection run', function () {
    F::queuedRun();
    AgentRun::factory()->create(); // a queued browser_inspector run, built without a real posting URL

    $this->withHeader('Authorization', 'Bearer inspector-token')->postJson('/api/worker/agent-runs/claim', ['worker_identity' => 'insp'])
        ->assertOk()->assertJsonMissingPath('resume');
    expect(AgentRun::where('agent_type', 'application_preparer')->first()->status->value)->toBe('queued');

    $this->withHeaders(prepAuthHeaders())->postJson('/api/worker/preparer/agent-runs/claim', ['worker_identity' => 'prep-1'])
        ->assertOk()->assertJsonStructure(['agent_run_id', 'resume' => ['sha256', 'bytes']]);
    expect(AgentRun::where('agent_type', 'browser_inspector')->first()->worker_identity)->toBe('insp');
});

it('keeps preparer routes out of the ordinary web surface and out of the inspector heartbeat feed', function () {
    config(['services.browser_worker.poll_interval_seconds' => 5]);
    F::queuedRun();
    $this->withHeaders(prepAuthHeaders())->postJson('/api/worker/preparer/agent-runs/claim', ['worker_identity' => 'prep-1'])->assertOk();

    // A preparer poll says nothing about whether the inspector is reachable.
    $this->getJson('/browser-worker/status')->assertOk()->assertJson(['online' => false, 'identity' => null]);
    expect(WorkerHeartbeat::where('worker_type', 'application_preparer')->count())->toBe(1);
    expect(Submission::count())->toBe(0);
});
