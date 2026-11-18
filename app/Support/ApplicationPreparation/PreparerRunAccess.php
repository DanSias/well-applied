<?php

namespace App\Support\ApplicationPreparation;

use App\Enums\AgentRunStatus;
use App\Models\AgentRun;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;

/**
 * Scopes a preparer worker to the one run it claimed. Passing the preparer token (AuthenticatePreparerWorker) is not
 * enough to touch a run: the run must be an application_preparer run (an inspector run is simply not found here), the
 * request must name the worker that claimed it (X-Worker-Identity), and for anything that reads inputs or writes
 * evidence the claim must still be live (Running, lease not expired). A lapsed claim is settled first, so a worker
 * that stopped heartbeating is refused rather than trusted.
 */
final class PreparerRunAccess
{
    public function __construct(private readonly ClaimNextPreparationRun $claims) {}

    /**
     * The caller's identity, once it is proven to be the claimant of this preparation run. Status is not checked, so a
     * repeated result delivery can still be recognised as a duplicate.
     */
    public function owned(AgentRun $run, Request $request): string
    {
        abort_unless($run->agent_type === ClaimNextPreparationRun::AGENT_TYPE, 404);

        $identity = trim((string) $request->header('X-Worker-Identity', ''));
        abort_if($identity === '' || $run->worker_identity === null || ! hash_equals($run->worker_identity, $identity), 403, 'This run was not claimed by this worker.');

        return $identity;
    }

    /**
     * The run, fresh from the database, only while the caller's claim is live.
     */
    public function active(AgentRun $run, Request $request): AgentRun
    {
        $this->owned($run, $request);
        $this->claims->recoverExpired();

        $fresh = $run->fresh();
        if ($fresh === null || $fresh->status !== AgentRunStatus::Running) {
            throw new HttpResponseException(response()->json([
                'accepted' => false,
                'reason' => 'agent_run_not_running',
                'current_status' => $fresh?->status->value,
            ], 409));
        }

        return $fresh;
    }
}
