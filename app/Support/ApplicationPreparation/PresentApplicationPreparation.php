<?php

namespace App\Support\ApplicationPreparation;

use App\Enums\AgentRunStatus;
use App\Enums\WorkflowType;
use App\Models\AgentRun;
use App\Models\Application;
use App\Models\WorkflowRun;

/**
 * The one safe JSON shape a browser is handed for an Application's preparation state. It carries execution state
 * (queued, running, succeeded, failed) and, separately, the latest delivered report's outcome and counts. It never
 * carries applicant values, the resume bytes, a worker token or a filesystem path: those stay in the private attempt
 * directory (PreparationArtifacts). Nothing in it says an application was submitted.
 */
final class PresentApplicationPreparation
{
    /**
     * @return array<string, mixed>
     */
    public function present(Application $application): array
    {
        $latestRun = $application->workflowRuns()
            ->where('workflow_type', WorkflowType::ApplicationPreparation)
            ->latest('id')
            ->first();

        $latestAgentRun = $latestRun === null ? null : $this->agentRunOf($latestRun);
        $delivered = $this->latestDelivered($application);

        return [
            'application_id' => $application->id,
            'workflow_run' => $latestRun === null ? null : [
                'id' => $latestRun->id,
                'status' => $latestRun->status->value,
                'failure_message' => $latestRun->failure_message,
                'started_at' => $latestRun->started_at?->toIso8601String(),
                'finished_at' => $latestRun->finished_at?->toIso8601String(),
            ],
            'attempt' => $latestAgentRun === null ? null : [
                'agent_run_id' => $latestAgentRun->id,
                'status' => $latestAgentRun->status->value,
                'queued_at' => $latestAgentRun->created_at?->toIso8601String(),
                'started_at' => $latestAgentRun->started_at?->toIso8601String(),
                'phase' => $latestAgentRun->status === AgentRunStatus::Running ? ($latestAgentRun->result_summary['phase'] ?? null) : null,
                'failure_category' => $latestAgentRun->failure_category?->value,
                'inputs' => $this->inputs($latestAgentRun),
            ],
            'attempts' => $this->attempts($application),
            'latest_result' => $delivered === null ? null : [
                'agent_run_id' => $delivered->id,
                'finished_at' => $delivered->finished_at?->toIso8601String(),
                'inputs' => $this->inputs($delivered),
            ] + array_diff_key($delivered->result_summary ?? [], ['phase' => 1, 'phase_at' => 1]),
        ];
    }

    /**
     * Every earlier and current attempt for this Application, newest first, each on its own: its own resume, its own
     * outcome. Attempts are tries at preparing one form, never separate applications.
     *
     * @return array<int, array<string, mixed>>
     */
    private function attempts(Application $application): array
    {
        return AgentRun::query()
            ->where('agent_type', ClaimNextPreparationRun::AGENT_TYPE)
            ->whereHas('workflowStep.workflowRun', fn ($q) => $q->where('application_id', $application->id)->where('workflow_type', WorkflowType::ApplicationPreparation))
            ->with('workflowStep')
            ->latest('id')
            ->limit(25)
            ->get()
            ->map(fn (AgentRun $run) => [
                'agent_run_id' => $run->id,
                'workflow_run_id' => $run->workflowStep->workflow_run_id,
                'status' => $run->status->value,
                'failure_category' => $run->failure_category?->value,
                'outcome' => $run->status === AgentRunStatus::Succeeded ? ($run->result_summary['outcome'] ?? null) : null,
                'reviewable' => $run->status === AgentRunStatus::Succeeded ? (bool) ($run->result_summary['reviewable'] ?? false) : false,
                'queued_at' => $run->created_at?->toIso8601String(),
                'finished_at' => $run->finished_at?->toIso8601String(),
                'resume_variant_id' => $run->input_summary['resume_variant_id'] ?? null,
                'resume_sha256' => $run->input_summary['resume_sha256'] ?? null,
            ])
            ->values()
            ->all();
    }

    private function agentRunOf(WorkflowRun $run): ?AgentRun
    {
        return AgentRun::query()
            ->whereHas('workflowStep', fn ($q) => $q->where('workflow_run_id', $run->id))
            ->latest('id')
            ->first();
    }

    private function latestDelivered(Application $application): ?AgentRun
    {
        return AgentRun::query()
            ->where('agent_type', ClaimNextPreparationRun::AGENT_TYPE)
            ->where('status', AgentRunStatus::Succeeded)
            ->whereHas('workflowStep.workflowRun', fn ($q) => $q->where('application_id', $application->id))
            ->latest('id')
            ->first();
    }

    /**
     * What was given to the attempt: which resume, its hash, and only the KEYS of the applicant data.
     *
     * @return array<string, mixed>|null
     */
    private function inputs(AgentRun $run): ?array
    {
        $in = $run->input_summary;

        return $in === null ? null : [
            'resume_variant_id' => $in['resume_variant_id'] ?? null,
            'resume_sha256' => $in['resume_sha256'] ?? null,
            'applicant_keys' => $in['applicant_keys'] ?? [],
            'omitted_keys' => $in['omitted_keys'] ?? [],
        ];
    }
}
