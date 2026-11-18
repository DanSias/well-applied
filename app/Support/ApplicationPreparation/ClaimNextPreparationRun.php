<?php

namespace App\Support\ApplicationPreparation;

use App\Enums\AgentRunFailureCategory;
use App\Enums\AgentRunStatus;
use App\Enums\WorkflowStatus;
use App\Models\AgentRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The preparer's claim and lease rules, on the same atomic conditional-update pattern the inspector's
 * ClaimNextAgentRun uses (a separate class on purpose: the inspector's code is not touched, and the two worker types
 * never claim each other's runs).
 *
 * - claim(): exactly one queued application_preparer AgentRun, oldest first, moved to Running with an owner and a
 *   lease. The conditional UPDATE ... WHERE status = 'queued' is the race check.
 * - renew(): extends the lease of a run the caller owns and that has not expired. Any other caller, or an expired or
 *   finished run, changes nothing.
 * - recoverExpired(): a Running run whose lease passed is marked Failed (claim_timeout), with its step and workflow.
 *   It is never reopened, requeued or retried: whether the form was partly filled, or anything else happened, is
 *   unknown, so only a person starting a new attempt can try again.
 */
final class ClaimNextPreparationRun
{
    public const AGENT_TYPE = 'application_preparer';

    public function claim(string $workerIdentity): ?AgentRun
    {
        $this->recoverExpired();

        return DB::transaction(function () use ($workerIdentity) {
            $candidate = AgentRun::query()
                ->where('agent_type', self::AGENT_TYPE)
                ->where('status', AgentRunStatus::Queued->value)
                ->oldest('id')
                ->first();

            if ($candidate === null) {
                return null;
            }

            $now = now();
            $affected = AgentRun::query()
                ->where('id', $candidate->id)
                ->where('status', AgentRunStatus::Queued->value)
                ->update([
                    'status' => AgentRunStatus::Running->value,
                    'worker_identity' => $workerIdentity,
                    'started_at' => $now,
                    'claim_expires_at' => $now->addSeconds($this->leaseSeconds()),
                ]);

            if ($affected === 0) {
                return null;
            }

            $agentRun = $candidate->fresh();
            $this->markRunning($agentRun, $now);

            return $agentRun;
        });
    }

    /**
     * @param  array<string, mixed>|null  $progress  bounded, non-sensitive progress merged into result_summary while running
     */
    public function renew(AgentRun $agentRun, string $workerIdentity, ?array $progress = null): bool
    {
        return DB::transaction(function () use ($agentRun, $workerIdentity, $progress) {
            $now = now();
            $fresh = AgentRun::query()->find($agentRun->id);
            $values = ['claim_expires_at' => $now->addSeconds($this->leaseSeconds())];

            if ($progress !== null && $fresh !== null) {
                $values['result_summary'] = [...($fresh->result_summary ?? []), ...$progress];
            }

            return AgentRun::query()
                ->where('id', $agentRun->id)
                ->where('agent_type', self::AGENT_TYPE)
                ->where('status', AgentRunStatus::Running->value)
                ->where('worker_identity', $workerIdentity)
                ->where('claim_expires_at', '>=', $now)
                ->update($values) === 1;
        });
    }

    /**
     * Marks every lapsed claim failed. Safe to call from anywhere (dispatch, claim, a worker request): each update is
     * conditional on the row still being Running and still expired.
     */
    public function recoverExpired(): void
    {
        $expired = AgentRun::query()
            ->where('agent_type', self::AGENT_TYPE)
            ->where('status', AgentRunStatus::Running->value)
            ->where('claim_expires_at', '<', now())
            ->get();

        foreach ($expired as $agentRun) {
            DB::transaction(function () use ($agentRun) {
                $now = now();
                $affected = AgentRun::query()
                    ->where('id', $agentRun->id)
                    ->where('status', AgentRunStatus::Running->value)
                    ->where('claim_expires_at', '<', $now)
                    ->update([
                        'status' => AgentRunStatus::Failed->value,
                        'failure_category' => AgentRunFailureCategory::ClaimTimeout->value,
                        'failure_message' => 'Worker claim expired before a result was reported.',
                        'finished_at' => $now,
                    ]);

                if ($affected === 0) {
                    return;
                }

                $step = $agentRun->workflowStep;
                if ($step->status === WorkflowStatus::Running) {
                    $step->update(['status' => WorkflowStatus::Failed, 'failure_message' => 'Preparation worker claim expired.', 'finished_at' => $now]);
                }
                $run = $step->workflowRun;
                if ($run->status === WorkflowStatus::Running) {
                    $run->update(['status' => WorkflowStatus::Failed, 'failure_message' => 'Preparation worker claim expired.', 'finished_at' => $now]);
                }
            });
        }
    }

    private function leaseSeconds(): int
    {
        return max(10, (int) config('services.preparer_worker.claim_ttl'));
    }

    private function markRunning(AgentRun $agentRun, CarbonImmutable $now): void
    {
        $step = $agentRun->workflowStep;
        if ($step->status !== WorkflowStatus::Running) {
            $step->update(['status' => WorkflowStatus::Running, 'started_at' => $step->started_at ?? $now]);
        }
        $run = $step->workflowRun;
        if ($run->status !== WorkflowStatus::Running) {
            $run->update(['status' => WorkflowStatus::Running, 'started_at' => $run->started_at ?? $now]);
        }
    }
}
