<?php

namespace App\Support\ApplicationPreparation;

use App\Enums\AgentRunStatus;
use App\Enums\WorkflowStatus;
use App\Enums\WorkflowType;
use App\Models\AgentRun;
use App\Models\WorkflowRun;
use Illuminate\Support\Facades\DB;

/**
 * Withdraws a preparation attempt that no worker has claimed yet, and nothing else.
 *
 * The cancellation is the same kind of atomic conditional update the claim is: `UPDATE agent_runs SET status =
 * 'cancelled' WHERE status = 'queued'`. A claim is `UPDATE ... WHERE status = 'queued'` too, so exactly one of them can
 * win a race for the row. If the cancel wins, the claim finds nothing queued; if the claim wins, the cancel changes
 * nothing and reports the run as no longer cancellable. There is no cancelling a running attempt: once a worker has the
 * run, the browser may already be filling a form, and only its own result (or a lapsed lease) ends it.
 *
 * History is kept: the WorkflowRun, step and AgentRun rows stay, as Cancelled. The frozen applicant values and resume of
 * a cancelled attempt were never given to anyone, so they are removed from the private disk; the hashes and key names in
 * input_summary remain as the record of what had been prepared. Nothing here creates or touches a Submission.
 *
 * Returns 'cancelled' (this call did it), 'already_cancelled' (an earlier call did; a repeat changes nothing) or
 * 'not_cancellable' (claimed, finished, or not a preparation).
 */
final class CancelApplicationPreparation
{
    public function __construct(private readonly PreparationArtifacts $artifacts) {}

    /**
     * @return array{outcome: 'cancelled'|'already_cancelled'|'not_cancellable', current_status: string|null}
     */
    public function cancel(WorkflowRun $workflowRun): array
    {
        if ($workflowRun->workflow_type !== WorkflowType::ApplicationPreparation) {
            return ['outcome' => 'not_cancellable', 'current_status' => null];
        }

        $agentRun = AgentRun::query()
            ->where('agent_type', ClaimNextPreparationRun::AGENT_TYPE)
            ->whereHas('workflowStep', fn ($q) => $q->where('workflow_run_id', $workflowRun->id))
            ->latest('id')
            ->first();

        if ($agentRun === null) {
            return ['outcome' => 'not_cancellable', 'current_status' => null];
        }

        $cancelled = DB::transaction(function () use ($agentRun, $workflowRun) {
            $now = now();
            $affected = AgentRun::query()
                ->where('id', $agentRun->id)
                ->where('status', AgentRunStatus::Queued->value)
                ->whereNull('worker_identity')
                ->update(['status' => AgentRunStatus::Cancelled->value, 'finished_at' => $now]);

            if ($affected === 0) {
                return false;
            }

            $agentRun->workflowStep->update(['status' => WorkflowStatus::Cancelled, 'finished_at' => $now]);
            $workflowRun->update(['status' => WorkflowStatus::Cancelled, 'finished_at' => $now]);

            return true;
        });

        if ($cancelled) {
            $this->artifacts->discardInputs($agentRun);

            return ['outcome' => 'cancelled', 'current_status' => AgentRunStatus::Cancelled->value];
        }

        $current = $agentRun->fresh()?->status;

        return [
            'outcome' => $current === AgentRunStatus::Cancelled ? 'already_cancelled' : 'not_cancellable',
            'current_status' => $current?->value,
        ];
    }
}
