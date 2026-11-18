<?php

namespace App\Support\ApplicationPreparation;

use App\Enums\WorkflowStatus;
use App\Models\Application;
use App\Models\JobPosting;
use App\Support\ResumeRevision\JobResumes;

/**
 * What the Opportunity workspace's preparation panel needs for one job, before and after any attempt: whether a
 * preparation can start (and, if not, why, in words a person can act on), where it would go, which tailored resume it
 * would use and which others are eligible, and the state and history of the attempts so far.
 *
 * It composes existing rules and has none of its own: the URL check is the dispatch's own, the resumes are the job's own
 * (JobResumes), the state is PresentApplicationPreparation. It carries no applicant value, no resume bytes and no path.
 * Nothing here says an application was submitted.
 */
final class PresentPreparationPanel
{
    public function __construct(
        private readonly DispatchApplicationPreparation $dispatch,
        private readonly JobResumes $resumes,
        private readonly PresentApplicationPreparation $state,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function present(JobPosting $job): array
    {
        $problem = null;
        $destination = null;

        try {
            $url = parse_url($this->dispatch->applicationUrl($job));
            $destination = [
                'host' => (string) ($url['host'] ?? '').(isset($url['port']) ? ':'.$url['port'] : ''),
                'path' => (string) ($url['path'] ?? '/'),
            ];
        } catch (PreparationException $e) {
            $problem = ['code' => $e->reason, 'message' => $e->getMessage()];
        }

        $options = array_map(fn (array $h) => [
            'id' => $h['id'],
            'label' => $h['label'],
            'summary' => $h['summary'],
            'generated_at' => $h['created_at'],
            'is_final' => $h['preferred'],
            'is_current' => $h['current'],
        ], $this->resumes->history($job));

        $default = $this->resumes->preferred($job) ?? $this->resumes->current($job);
        if ($default === null && $problem === null) {
            $problem = ['code' => 'no_resume', 'message' => 'This job has no tailored resume yet. Generate one first; a resume is never chosen or created for you.'];
        }

        $application = Application::query()->where('job_posting_id', $job->id)->first();
        $state = $application === null ? null : $this->state->present($application);
        $active = in_array($state['workflow_run']['status'] ?? null, [WorkflowStatus::Pending->value, WorkflowStatus::Running->value], true);

        return [
            'job_id' => $job->id,
            'can_prepare' => $problem === null && ! $active,
            'problem' => $problem,
            'destination' => $destination,
            'resumes' => [
                'default_id' => $default?->id,
                'default_kind' => $default === null ? null : ($this->resumes->preferred($job)?->id === $default->id ? 'final' : 'current'),
                'options' => $options,
            ],
            'state' => $state,
        ];
    }
}
