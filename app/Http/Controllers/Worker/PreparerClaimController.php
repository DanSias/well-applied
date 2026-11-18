<?php

namespace App\Http\Controllers\Worker;

use App\Http\Controllers\Controller;
use App\Support\ApplicationInspection\RecordWorkerHeartbeat;
use App\Support\ApplicationPreparation\ClaimNextPreparationRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The preparer's poll/claim endpoint, machine-only and guarded by AuthenticatePreparerWorker. It returns only what is
 * needed to start: the run's id, the lease, and the resume's hash and size. The applicant values and the resume bytes
 * are not in this response: they are fetched from the run-scoped endpoints, which only the claimant can open.
 */
class PreparerClaimController extends Controller
{
    public function store(Request $request, ClaimNextPreparationRun $claims, RecordWorkerHeartbeat $heartbeat): JsonResponse|Response
    {
        $identity = trim((string) $request->string('worker_identity', ''));
        $identity = $identity === '' ? 'unknown' : mb_substr($identity, 0, 100);

        $heartbeat->record($identity, ClaimNextPreparationRun::AGENT_TYPE);

        $agentRun = $claims->claim($identity);
        if ($agentRun === null) {
            return response()->noContent();
        }

        $input = $agentRun->input_summary ?? [];

        return response()->json([
            'agent_run_id' => $agentRun->id,
            'application_url' => $input['application_url'] ?? null,
            'lease_expires_at' => $agentRun->claim_expires_at?->toIso8601String(),
            'lease_seconds' => (int) config('services.preparer_worker.claim_ttl'),
            'resume' => [
                'sha256' => $input['resume_sha256'] ?? null,
                'bytes' => $input['resume_bytes'] ?? null,
            ],
        ]);
    }
}
