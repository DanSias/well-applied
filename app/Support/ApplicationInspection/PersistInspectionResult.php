<?php

namespace App\Support\ApplicationInspection;

use App\Enums\AgentRunStatus;
use App\Enums\InspectionOutcome;
use App\Enums\WorkflowStatus;
use App\Models\AgentRun;
use App\Models\ApplicationQuestion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Persists one already-validated worker result (see
 * App\Http\Requests\Worker\ReportInspectionResultRequest) onto its
 * AgentRun and, on success, the ApplicationQuestion rows it produced.
 *
 * The atomic-conditional-update pattern is the sole concurrency/
 * idempotency mechanism here — no explicit row lock, no pre-check
 * read-then-decide: the UPDATE ... WHERE status = 'running' clause,
 * checked via affected-row-count, IS the check. A second delivery for
 * an already-succeeded AgentRun, or any delivery for one a stale-claim
 * recovery already marked Failed, simply never matches that WHERE
 * clause — the caller then reads the row's actual current status to
 * classify the outcome. See docs/application-inspector.md "Result
 * idempotency" / "Stale result protection".
 *
 * Returns ['outcome' => 'accepted'] | ['outcome' => 'duplicate'] |
 * ['outcome' => 'stale', 'current_status' => string].
 */
final class PersistInspectionResult
{
    /**
     * @param  array<string, mixed>  $payload  the worker's already-validated request body
     * @return array<string, mixed>
     */
    public function persist(AgentRun $agentRun, array $payload): array
    {
        return DB::transaction(function () use ($agentRun, $payload) {
            return $payload['status'] === 'succeeded'
                ? $this->attemptSucceeded($agentRun, $payload)
                : $this->attemptFailed($agentRun, $payload);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function attemptSucceeded(AgentRun $agentRun, array $payload): array
    {
        $now = now();

        $affected = AgentRun::query()
            ->where('id', $agentRun->id)
            ->where('status', AgentRunStatus::Running->value)
            ->update([
                'status' => AgentRunStatus::Succeeded->value,
                'inspection_outcome' => $payload['inspection_outcome'],
                'inspection_stop_reason' => $payload['stop_reason'] ?? null,
                'ats_detected' => $payload['ats'] ?? null,
                // result_summary is the one bounded, non-content
                // operational bag (see docs/domain-model.md) — the
                // worker's own diagnostics plus its warnings, never a
                // raw DOM/page dump.
                'result_summary' => $this->summary($payload),
                'finished_at' => $now,
            ]);

        if ($affected === 0) {
            return $this->rejection($agentRun, AgentRunStatus::Succeeded);
        }

        // Never delete/replace prior inspections' questions — each
        // already carries its own agent_run_id provenance. See
        // docs/application-inspector.md "ApplicationQuestion ordering"
        // and the "Repeated inspection question history" requirement.
        foreach ($payload['fields'] as $field) {
            ApplicationQuestion::create([
                'application_id' => $agentRun->workflowStep->workflowRun->application_id,
                'agent_run_id' => $agentRun->id,
                'position' => $field['position'],
                'external_field_id' => $field['external_field_id'] ?? null,
                'raw_label' => $field['raw_label'] ?? null,
                'label_source' => $field['label_source'],
                'control_type' => $field['control_type'],
                'required' => $field['required'] ?? null,
                'options' => $field['options'] ?? null,
                'section' => $field['section'] ?? null,
                'extraction_source' => $field['extraction_source'],
            ]);
        }

        $this->settle($agentRun, WorkflowStatus::Succeeded, null, $now);
        $this->recordApplicationUrl($agentRun, $payload);

        return ['outcome' => 'accepted'];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function attemptFailed(AgentRun $agentRun, array $payload): array
    {
        $now = now();

        $affected = AgentRun::query()
            ->where('id', $agentRun->id)
            ->where('status', AgentRunStatus::Running->value)
            ->update([
                'status' => AgentRunStatus::Failed->value,
                'failure_category' => $payload['failure_category'],
                'failure_message' => InspectionUrl::redactText((string) $payload['failure_message']),
                // A failed run keeps what the worker had learned by then (how far navigation got, the page title):
                // that is exactly when a person needs the evidence.
                'result_summary' => $this->summary($payload),
                'finished_at' => $now,
            ]);

        if ($affected === 0) {
            return $this->rejection($agentRun, AgentRunStatus::Failed);
        }

        $this->settle($agentRun, WorkflowStatus::Failed, InspectionUrl::redactText((string) $payload['failure_message']), $now);

        return ['outcome' => 'accepted'];
    }

    /**
     * The bounded, non-content evidence kept for one run. Every URL in it is origin + path only (a query string can carry
     * a token); the one full URL the worker may report, `resolved_url`, is used for the Application and never stored here.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function summary(array $payload): array
    {
        $diagnostics = $payload['diagnostics'] ?? [];

        foreach (['navigation' => 8, 'candidates' => 5] as $key => $limit) {
            if (isset($diagnostics[$key]) && is_array($diagnostics[$key])) {
                $diagnostics[$key] = array_values(array_map(
                    fn ($entry) => is_array($entry) ? [...$entry, 'url' => InspectionUrl::redact($entry['url'] ?? null)] : $entry,
                    array_slice($diagnostics[$key], 0, $limit),
                ));
            }
        }

        if (isset($diagnostics['page_title']) && is_string($diagnostics['page_title'])) {
            $diagnostics['page_title'] = mb_substr($diagnostics['page_title'], 0, 300);
        }

        return [
            ...$diagnostics,
            'warnings' => $payload['warnings'] ?? [],
            'requested_url' => InspectionUrl::redact($payload['requested_url'] ?? null),
            'resolved_url' => InspectionUrl::redact($payload['resolved_url'] ?? null),
            'final_url' => InspectionUrl::redact($payload['final_url'] ?? null),
        ];
    }

    /**
     * When the worker reached an application form somewhere other than the job's source URL, remember where, so the next
     * inspection starts there. The URL is validated again here (the worker's report is evidence, not authority), and a
     * person's own URL is never replaced.
     *
     * @param  array<string, mixed>  $payload
     */
    private function recordApplicationUrl(AgentRun $agentRun, array $payload): void
    {
        $outcome = InspectionOutcome::from($payload['inspection_outcome']);
        $url = InspectionUrl::acceptable($payload['resolved_url'] ?? null);

        if ($url === null || ! $outcome->reachedForm($payload['stop_reason'] ?? null)) {
            return;
        }

        $application = $agentRun->workflowStep->workflowRun->application;
        $application->recordResolvedUrl($url, $application->jobPosting->source_url);
    }

    private function settle(AgentRun $agentRun, WorkflowStatus $status, ?string $failureMessage, CarbonImmutable $now): void
    {
        $step = $agentRun->workflowStep;
        $step->update([
            'status' => $status,
            'failure_message' => $failureMessage,
            'finished_at' => $now,
        ]);

        $run = $step->workflowRun;
        $run->update([
            'status' => $status,
            'failure_message' => $failureMessage,
            'finished_at' => $now,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rejection(AgentRun $agentRun, AgentRunStatus $expectedAcceptedStatus): array
    {
        $current = $agentRun->fresh()->status;

        return [
            'outcome' => $current === $expectedAcceptedStatus ? 'duplicate' : 'stale',
            'current_status' => $current->value,
        ];
    }
}
