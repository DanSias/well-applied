<?php

namespace App\Support\ApplicationInspection;

use App\Enums\AgentRunStatus;
use App\Enums\ApplicationQuestionLabelSource;
use App\Enums\WorkflowStatus;
use App\Enums\WorkflowType;
use App\Models\AgentRun;
use App\Models\Application;
use App\Models\ApplicationQuestion;
use App\Models\WorkflowRun;

/**
 * The one safe, minimal JSON shape a browser is ever handed for an
 * Application's inspection state — used identically by the polling
 * endpoint and by ApplicationController::show()'s initial page props,
 * mirroring App\Support\GenerationAttempt\PresentGenerationAttempt's
 * own established convention exactly.
 *
 * Deliberately shows the latest inspection WorkflowRun's own status
 * (for polling: pending/running keeps polling; any terminal status
 * stops) separately from the latest SUCCESSFUL AgentRun's result — a
 * later failed run never erases a prior successful extraction. When the
 * latest successful run found no fields (a blocked page, a listing with
 * no Apply link) but an earlier one did, that earlier extraction is
 * handed over as `earlier_result`, so a later unsuccessful inspection
 * never silently hides a useful one. See docs/application-inspector.md
 * "Latest successful inspection".
 *
 * Every URL here is origin + path only (InspectionUrl::redact): a query
 * string can carry a token. A record from before URL evidence existed
 * has no such keys, and they stay null rather than being invented.
 */
final class PresentApplicationInspection
{
    /**
     * @return array<string, mixed>
     */
    public function present(Application $application): array
    {
        $latestRun = $application->workflowRuns()
            ->where('workflow_type', WorkflowType::ApplicationInspection)
            ->latest('id')
            ->first();

        $latestSuccessfulAgentRun = $this->latestSuccessfulAgentRun($application);
        $latestResult = $latestSuccessfulAgentRun !== null ? $this->presentResult($latestSuccessfulAgentRun) : null;

        return [
            'application_id' => $application->id,
            'application_url' => $this->presentApplicationUrl($application),
            'workflow_run' => $latestRun !== null ? $this->presentWorkflowRun($latestRun) : null,
            'latest_attempt' => $latestRun !== null ? $this->presentLatestAttempt($latestRun) : null,
            'latest_result' => $latestResult,
            'earlier_result' => $latestResult !== null && $latestResult['field_count'] === 0
                ? $this->earlierResult($application, $latestSuccessfulAgentRun)
                : null,
        ];
    }

    private function latestSuccessfulAgentRun(Application $application): ?AgentRun
    {
        return AgentRun::query()
            ->whereHas('workflowStep.workflowRun', function ($query) use ($application) {
                $query->where('application_id', $application->id)
                    ->where('workflow_type', WorkflowType::ApplicationInspection);
            })
            ->where('status', AgentRunStatus::Succeeded)
            ->latest('id')
            ->first();
    }

    /**
     * The newest earlier successful run that actually extracted fields, shown only when the latest one did not.
     *
     * @return array<string, mixed>|null
     */
    private function earlierResult(Application $application, AgentRun $latest): ?array
    {
        $earlier = AgentRun::query()
            ->whereHas('workflowStep.workflowRun', function ($query) use ($application) {
                $query->where('application_id', $application->id)
                    ->where('workflow_type', WorkflowType::ApplicationInspection);
            })
            ->where('status', AgentRunStatus::Succeeded)
            ->where('id', '<', $latest->id)
            ->whereHas('applicationQuestions')
            ->latest('id')
            ->first();

        return $earlier !== null ? $this->presentResult($earlier) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function presentApplicationUrl(Application $application): ?array
    {
        if ($application->application_url === null) {
            return null;
        }

        return [
            'url' => InspectionUrl::redact($application->application_url),
            'origin' => $application->application_url_origin,
            'set_at' => $application->application_url_set_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentWorkflowRun(WorkflowRun $run): array
    {
        return [
            'id' => $run->id,
            'status' => $run->status->value,
            'failure_message' => $run->status === WorkflowStatus::Failed ? $run->failure_message : null,
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
        ];
    }

    /**
     * The newest attempt itself, successful or not: why a failed one failed, and what it learned before it did.
     *
     * @return array<string, mixed>|null
     */
    private function presentLatestAttempt(WorkflowRun $run): ?array
    {
        $agentRun = AgentRun::query()
            ->whereHas('workflowStep', fn ($query) => $query->where('workflow_run_id', $run->id))
            ->latest('id')
            ->first();

        if ($agentRun === null) {
            return null;
        }

        return [
            'agent_run_id' => $agentRun->id,
            'status' => $agentRun->status->value,
            'failure_category' => $agentRun->failure_category?->value,
            'diagnostics' => $this->presentDiagnostics($agentRun),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentResult(AgentRun $agentRun): array
    {
        $questions = $agentRun->applicationQuestions()->orderBy('position')->get();

        return [
            'agent_run_id' => $agentRun->id,
            'ats_detected' => $agentRun->ats_detected,
            'inspection_outcome' => $agentRun->inspection_outcome?->value,
            'stop_reason' => $agentRun->inspection_stop_reason,
            'finished_at' => $agentRun->finished_at?->toIso8601String(),
            'warnings' => $agentRun->result_summary['warnings'] ?? [],
            'diagnostics' => $this->presentDiagnostics($agentRun),
            'field_count' => $questions->count(),
            'unresolved_count' => $questions->where('label_source', ApplicationQuestionLabelSource::Unresolved)->count(),
            'questions' => $questions->map(fn (ApplicationQuestion $q) => [
                'position' => $q->position,
                'raw_label' => $q->raw_label,
                'label_source' => $q->label_source->value,
                'control_type' => $q->control_type,
                'required' => $q->required,
                'options' => $q->options,
                'section' => $q->section,
                'extraction_source' => $q->extraction_source->value,
            ])->values()->all(),
        ];
    }

    /**
     * The technical evidence for one run, for the drawer's collapsed section. Null where a record never captured it:
     * `recorded` says whether this run kept any URL evidence at all, so an older record reads as "not recorded", never
     * as an empty trace.
     *
     * @return array<string, mixed>
     */
    private function presentDiagnostics(AgentRun $agentRun): array
    {
        $summary = $agentRun->result_summary ?? [];

        return [
            'recorded' => array_key_exists('requested_url', $summary),
            'requested_url' => $summary['requested_url'] ?? null,
            'resolved_url' => $summary['resolved_url'] ?? null,
            'final_url' => $summary['final_url'] ?? null,
            'page_title' => $summary['page_title'] ?? null,
            'http_status' => $summary['http_status'] ?? null,
            'navigation' => $summary['navigation'] ?? [],
            'candidates' => $summary['candidates'] ?? [],
            'warnings' => $summary['warnings'] ?? [],
            'start_origin' => ($agentRun->input_summary ?? [])['start_origin'] ?? null,
            'total_ms' => $summary['total_ms'] ?? null,
            'timestamp' => $summary['timestamp'] ?? null,
            'failure_message' => $agentRun->failure_message,
        ];
    }
}
