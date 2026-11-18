<?php

namespace App\Http\Controllers\Worker;

use App\Http\Controllers\Controller;
use App\Http\Requests\Worker\ReportPreparationResultRequest;
use App\Models\AgentRun;
use App\Support\ApplicationPreparation\ClaimNextPreparationRun;
use App\Support\ApplicationPreparation\PersistPreparationResult;
use App\Support\ApplicationPreparation\PreparationArtifacts;
use App\Support\ApplicationPreparation\PreparationException;
use App\Support\ApplicationPreparation\PreparerRunAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Everything a preparer worker may do to the one run it claimed. {agentRun} is the only way a run is addressed, and
 * PreparerRunAccess proves the caller is its claimant before each action. See docs/application-preparation.md.
 */
class PreparerRunController extends Controller
{
    private const PHASES = ['opening', 'filling', 'uploading', 'verifying', 'capturing', 'delivering'];

    public function __construct(
        private readonly PreparerRunAccess $access,
        private readonly PreparationArtifacts $artifacts,
    ) {}

    /** The exact applicant values and resume descriptor frozen at dispatch, if they still match their recorded hash. */
    public function input(Request $request, AgentRun $agentRun): JsonResponse
    {
        $run = $this->access->active($agentRun, $request);
        $input = $this->artifacts->readInput($run);

        if ($input === null) {
            return response()->json(['error' => 'input_unavailable'], 409);
        }

        return response()->json(['agent_run_id' => $run->id, ...$input], 200, ['Cache-Control' => 'no-store, private']);
    }

    /** The resume bytes frozen at dispatch, if they still match their recorded SHA-256. */
    public function resume(Request $request, AgentRun $agentRun): Response|JsonResponse
    {
        $run = $this->access->active($agentRun, $request);
        $bytes = $this->artifacts->readResume($run);

        if ($bytes === null) {
            return response()->json(['error' => 'resume_unavailable'], 409);
        }

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'X-Resume-Sha256' => $run->input_summary['resume_sha256'],
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function heartbeat(Request $request, AgentRun $agentRun, ClaimNextPreparationRun $claims): JsonResponse
    {
        $identity = $this->access->owned($agentRun, $request);
        $claims->recoverExpired();

        $phase = $request->input('phase');
        $progress = is_string($phase) && in_array($phase, self::PHASES, true) ? ['phase' => $phase, 'phase_at' => now()->toIso8601String()] : null;

        if (! $claims->renew($agentRun, $identity, $progress)) {
            return response()->json(['accepted' => false, 'reason' => 'agent_run_not_running', 'current_status' => $agentRun->fresh()?->status->value], 409);
        }

        return response()->json([
            'accepted' => true,
            'lease_expires_at' => $agentRun->fresh()->claim_expires_at?->toIso8601String(),
        ]);
    }

    /** One screenshot, as the raw PNG body. The name is the worker's own pattern, enforced here and in the route. */
    public function screenshot(Request $request, AgentRun $agentRun, string $name): JsonResponse
    {
        $run = $this->access->active($agentRun, $request);

        try {
            $result = $this->artifacts->putScreenshot($run, $name, (string) $request->getContent());
        } catch (PreparationException $e) {
            return response()->json(['accepted' => false, 'reason' => $e->reason], $e->reason === 'artifact_conflict' ? 409 : 422);
        }

        return response()->json(['accepted' => true, 'duplicate' => $result === 'duplicate']);
    }

    public function result(ReportPreparationResultRequest $request, AgentRun $agentRun, ClaimNextPreparationRun $claims, PersistPreparationResult $persister): JsonResponse
    {
        $identity = $this->access->owned($agentRun, $request);
        $claims->recoverExpired();

        $result = $persister->persist($agentRun, $identity, $request->validated());

        if ($result['outcome'] === 'stale') {
            return response()->json([
                'accepted' => false,
                'reason' => 'agent_run_not_running',
                'current_status' => $result['current_status'],
            ], 409);
        }

        return response()->json(['accepted' => true, 'duplicate' => $result['outcome'] === 'duplicate']);
    }
}
