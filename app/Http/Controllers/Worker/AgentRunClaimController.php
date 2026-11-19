<?php

namespace App\Http\Controllers\Worker;

use App\Http\Controllers\Controller;
use App\Support\ApplicationInspection\ClaimNextAgentRun;
use App\Support\ApplicationInspection\InspectionUrl;
use App\Support\ApplicationInspection\RecordWorkerHeartbeat;
use App\Support\ApplicationInspection\ResolveInspectionStartUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The worker's poll/claim endpoint — machine-only, guarded by
 * App\Http\Middleware\AuthenticateBrowserWorker (routes/api.php),
 * never reachable from the frontend. Returns the minimum payload a
 * browser_inspector worker needs to run one inspection — never
 * Career Profile data, resume content, or any Application field beyond
 * the one URL it exists to inspect. See docs/application-inspector.md
 * "Claim protocol".
 *
 * Every authenticated call here also records worker presence (see
 * App\Support\ApplicationInspection\RecordWorkerHeartbeat) — a claim
 * poll that found no work still proves the worker is alive and
 * reachable, so no separate heartbeat endpoint/traffic exists. See
 * docs/application-inspector.md "Worker presence".
 */
class AgentRunClaimController extends Controller
{
    public function store(Request $request, ClaimNextAgentRun $claimer, RecordWorkerHeartbeat $heartbeat, ResolveInspectionStartUrl $resolver): JsonResponse|Response
    {
        $workerIdentity = (string) $request->string('worker_identity', 'unknown');

        $heartbeat->record($workerIdentity, 'browser_inspector');

        $agentRun = $claimer->claim($workerIdentity);

        if ($agentRun === null) {
            return response()->noContent();
        }

        $application = $agentRun->workflowStep->workflowRun->application;
        $start = $resolver->resolve($application);

        // Where this attempt began and why, kept with the run (origin + path only: a query string can carry a token).
        $agentRun->update(['input_summary' => [
            'start_origin' => $start['origin'],
            'start_url' => InspectionUrl::redact($start['url']),
        ]]);

        $ttl = (int) config('services.browser_worker.claim_ttl');

        return response()->json([
            'agent_run_id' => $agentRun->id,
            // Kept for older workers: the same URL as start_url.
            'application_url' => $start['url'],
            'start_url' => $start['url'],
            'start_origin' => $start['origin'],
            'source_url' => $start['source_url'],
            // The most the worker may spend, ending with enough of the lease left to deliver the result. The lease is
            // the single source of truth; the worker never decides its own budget.
            'budget_seconds' => max(15, $ttl - (int) config('services.browser_worker.delivery_allowance')),
        ]);
    }
}
