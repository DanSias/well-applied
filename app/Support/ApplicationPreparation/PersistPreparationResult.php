<?php

namespace App\Support\ApplicationPreparation;

use App\Enums\AgentRunFailureCategory;
use App\Enums\AgentRunStatus;
use App\Enums\WorkflowStatus;
use App\Models\AgentRun;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Persists one already-validated preparer result onto its AgentRun, on the same atomic conditional-update pattern as
 * PersistInspectionResult: the UPDATE ... WHERE status = 'running' AND worker_identity = ? AND the lease has not
 * lapsed IS the check. A result from a worker that does not own the run, from one whose lease lapsed, or for a run that
 * already finished never matches, so it cannot overwrite a completed, failed or superseded attempt.
 *
 * Execution status and report outcome stay apart. A delivered report of any outcome, including `input_required` and
 * `safety_failure`, is a Succeeded execution: the worker finished and told us what it found. Only the worker not
 * delivering (a failure report, or a lapsed claim) is a failed execution.
 *
 * This writes the AgentRun, its step and workflow, and the attempt's private report file. It never writes a
 * Submission or anything that could be read as one.
 *
 * Returns ['outcome' => 'accepted'] | ['outcome' => 'duplicate'] | ['outcome' => 'stale', 'current_status' => string].
 */
final class PersistPreparationResult
{
    public function __construct(
        private readonly PreparationArtifacts $artifacts,
        private readonly SanitizePreparationReport $sanitizer,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  the validated request body
     * @return array<string, mixed>
     */
    public function persist(AgentRun $agentRun, string $workerIdentity, array $payload): array
    {
        $reportPath = null;

        try {
            return DB::transaction(function () use ($agentRun, $workerIdentity, $payload, &$reportPath) {
                return $payload['status'] === 'succeeded'
                    ? $this->succeeded($agentRun, $workerIdentity, $payload, $reportPath)
                    : $this->failed($agentRun, $workerIdentity, $payload);
            });
        } catch (\Throwable $e) {
            if ($reportPath !== null) {
                $this->artifacts->deleteFile($reportPath);
            }
            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function succeeded(AgentRun $agentRun, string $workerIdentity, array $payload, ?string &$reportPath): array
    {
        $projected = $this->sanitizer->project($agentRun, $payload['report'], $payload['screenshots'] ?? [], $this->artifacts);
        $reportJson = json_encode($projected['report'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $summary = [...$projected['summary'], 'report_sha256' => hash('sha256', $reportJson)];

        $now = now();
        $affected = $this->conditional($agentRun, $workerIdentity, $now)->update([
            'status' => AgentRunStatus::Succeeded->value,
            'ats_detected' => $payload['report']['ats'],
            'result_summary' => $summary,
            'finished_at' => $now,
        ]);

        if ($affected === 0) {
            return $this->rejection($agentRun, $workerIdentity, AgentRunStatus::Succeeded);
        }

        $written = $this->artifacts->writeReport($agentRun, $projected['report']);
        $reportPath = $written['path'];
        // The summary recorded the hash of the exact bytes written; they must be the same bytes.
        if ($written['sha256'] !== $summary['report_sha256']) {
            throw new PreparationException('The report could not be kept intact.', 'report_failed');
        }

        $this->settle($agentRun, WorkflowStatus::Succeeded, null, $now);

        return ['outcome' => 'accepted'];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function failed(AgentRun $agentRun, string $workerIdentity, array $payload): array
    {
        $now = now();
        $message = $this->message($payload['failure_message']);

        $affected = $this->conditional($agentRun, $workerIdentity, $now)->update([
            'status' => AgentRunStatus::Failed->value,
            'failure_category' => $payload['failure_category'],
            'failure_message' => $message,
            'finished_at' => $now,
        ]);

        if ($affected === 0) {
            return $this->rejection($agentRun, $workerIdentity, AgentRunStatus::Failed);
        }

        $this->settle($agentRun, WorkflowStatus::Failed, $message, $now);

        return ['outcome' => 'accepted'];
    }

    /**
     * @return Builder<AgentRun>
     */
    private function conditional(AgentRun $agentRun, string $workerIdentity, CarbonImmutable $now)
    {
        return AgentRun::query()
            ->where('id', $agentRun->id)
            ->where('agent_type', ClaimNextPreparationRun::AGENT_TYPE)
            ->where('status', AgentRunStatus::Running->value)
            ->where('worker_identity', $workerIdentity)
            ->where('claim_expires_at', '>=', $now);
    }

    private function settle(AgentRun $agentRun, WorkflowStatus $status, ?string $failureMessage, CarbonImmutable $now): void
    {
        $step = $agentRun->workflowStep;
        $step->update(['status' => $status, 'failure_message' => $failureMessage, 'finished_at' => $now]);
        $step->workflowRun->update(['status' => $status, 'failure_message' => $failureMessage, 'finished_at' => $now]);
    }

    /**
     * Same delivery again is a duplicate (nothing is written); anything else about a finished or lapsed run is stale.
     *
     * @return array<string, mixed>
     */
    private function rejection(AgentRun $agentRun, string $workerIdentity, AgentRunStatus $delivered): array
    {
        $current = $agentRun->fresh();
        $sameDelivery = $current->status === $delivered
            && $current->worker_identity === $workerIdentity
            && $current->failure_category !== AgentRunFailureCategory::ClaimTimeout;

        return [
            'outcome' => $sameDelivery ? 'duplicate' : 'stale',
            'current_status' => $current->status->value,
        ];
    }

    private function message(string $message): string
    {
        $clean = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $message) ?? '';

        return mb_substr(trim(preg_replace('/\s+/u', ' ', $clean) ?? ''), 0, 1000);
    }
}
