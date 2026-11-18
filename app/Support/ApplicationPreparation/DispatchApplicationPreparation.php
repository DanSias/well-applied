<?php

namespace App\Support\ApplicationPreparation;

use App\Enums\AgentRunStatus;
use App\Enums\ApplicationStatus;
use App\Enums\WorkflowStatus;
use App\Enums\WorkflowType;
use App\Models\AgentRun;
use App\Models\Application;
use App\Models\JobPosting;
use App\Models\ResumeVariant;
use App\Models\WorkflowRun;
use App\Support\ResumeDocument\ResumePdfFilename;
use App\Support\ResumeRevision\JobResumes;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * "Prepare Application" for one JobPosting: reuse or create its one Application (the same row inspection uses; there is
 * at most one per job), refuse if a preparation is already in flight, otherwise create WorkflowRun
 * (application_preparation) -> WorkflowStep (prepare_application) -> queued AgentRun (application_preparer), with the
 * attempt's inputs frozen first.
 *
 * What an attempt is given is fixed at dispatch and never changes afterwards:
 * - the resume: the explicitly chosen variant, else the job's preferred (final) one, else its current one, rendered and
 *   validated to PDF bytes, kept write-once and private with its SHA-256;
 * - the applicant values (BuildPreparationApplicant), kept write-once and private with their SHA-256;
 * - agent_runs.input_summary: the variant, the hashes, and only the KEYS of what was sent and what was left out.
 *
 * A retry is always a new attempt: after a failure, a lapsed claim or any delivered result, calling this creates a
 * brand-new WorkflowRun, step and AgentRun. Nothing is rerun automatically, and this never creates a Submission.
 */
final class DispatchApplicationPreparation
{
    public const INPUT_SCHEMA_VERSION = 1;

    /** Hosts the preparer supports (workers/application-preparer/src/url-safety.ts); anything else is refused up front. */
    private const SUPPORTED_HOSTS = ['jobs.lever.co', 'jobs.eu.lever.co', 'boards.greenhouse.io', 'job-boards.greenhouse.io', 'job-boards.eu.greenhouse.io'];

    public function __construct(
        private readonly JobResumes $resumes,
        private readonly PreparationResumePdf $pdfs,
        private readonly BuildPreparationApplicant $applicants,
        private readonly PreparationArtifacts $artifacts,
        private readonly ClaimNextPreparationRun $claims,
    ) {}

    /**
     * @return array{workflowRun: WorkflowRun, created: bool}
     *
     * @throws PreparationException
     */
    public function dispatch(JobPosting $job, ?ResumeVariant $variant = null): array
    {
        $url = $this->applicationUrl($job);
        $variant = $this->resume($job, $variant);

        // A lapsed claim must not hold the job hostage: settle it (as failed, never rerun) before checking for work in flight.
        $this->claims->recoverExpired();

        $application = $this->draftApplication($job);
        if (($active = $this->activeRun($application)) !== null) {
            return ['workflowRun' => $active, 'created' => false];
        }

        // Rendering can take seconds (headless Chromium), so it happens before the write transaction opens.
        $pdf = $this->pdfs->bytes($variant);
        ['applicant' => $applicant, 'omitted' => $omitted] = $this->applicants->build($variant->careerProfile);

        $written = null;

        try {
            return DB::transaction(function () use ($application, $url, $variant, $pdf, $applicant, $omitted, &$written) {
                // Re-checked under the transaction: two clicks that both got past the first look converge on one run.
                if (($active = $this->activeRun($application)) !== null) {
                    return ['workflowRun' => $active, 'created' => false];
                }

                $workflowRun = $application->workflowRuns()->create([
                    'workflow_type' => WorkflowType::ApplicationPreparation,
                    'status' => WorkflowStatus::Pending,
                ]);
                $step = $workflowRun->workflowSteps()->create([
                    'step_key' => 'prepare_application',
                    'status' => WorkflowStatus::Pending,
                ]);
                $agentRun = $step->agentRuns()->create([
                    'agent_type' => ClaimNextPreparationRun::AGENT_TYPE,
                    'status' => AgentRunStatus::Queued,
                ]);
                $written = $agentRun;

                $resume = $this->artifacts->writeResume($agentRun, $pdf);
                $input = $this->artifacts->writeInput($agentRun, [
                    'schema_version' => self::INPUT_SCHEMA_VERSION,
                    'application_url' => $url,
                    'applicant' => $applicant,
                    'answers' => (object) [],
                    'resume' => [
                        'sha256' => $resume['sha256'],
                        'bytes' => strlen($pdf),
                        'filename' => ResumePdfFilename::build($variant),
                    ],
                ]);

                $agentRun->update(['input_summary' => [
                    'schema_version' => self::INPUT_SCHEMA_VERSION,
                    'application_url' => $this->withoutQuery($url),
                    'resume_variant_id' => $variant->id,
                    'resume_sha256' => $resume['sha256'],
                    'resume_bytes' => strlen($pdf),
                    'input_sha256' => $input['sha256'],
                    'applicant_keys' => array_keys($applicant),
                    'omitted_keys' => $omitted,
                ]]);

                return ['workflowRun' => $workflowRun, 'created' => true];
            });
        } catch (\Throwable $e) {
            // Files written for a dispatch that did not complete would have no row to explain them.
            if ($written !== null) {
                $this->artifacts->discard($written);
            }
            throw $e;
        }
    }

    /**
     * @throws PreparationException
     */
    private function applicationUrl(JobPosting $job): string
    {
        $url = trim((string) $job->source_url);
        $parts = parse_url($url);

        if ($url === '' || $parts === false) {
            throw new PreparationException('This job has no application URL to prepare.', 'no_application_url');
        }
        if ($this->isLocalFixture($parts)) {
            return $url;
        }
        if (($parts['scheme'] ?? null) !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            throw new PreparationException('This job\'s application URL cannot be used (it must be plain https).', 'unsupported_application_url');
        }
        if (! in_array(strtolower((string) ($parts['host'] ?? '')), self::SUPPORTED_HOSTS, true)) {
            throw new PreparationException('Applications on this site cannot be prepared yet (only Lever and Greenhouse).', 'unsupported_ats_host');
        }

        return $url;
    }

    /**
     * A local fixture form (never a real application), and only when explicitly enabled for local testing.
     *
     * @param  array<string, mixed>  $parts
     */
    private function isLocalFixture(array $parts): bool
    {
        return (bool) config('services.preparer_worker.allow_local_fixture')
            && ($parts['scheme'] ?? null) === 'http'
            && in_array($parts['host'] ?? null, ['127.0.0.1', 'localhost'], true)
            && ! isset($parts['user'], $parts['pass']);
    }

    /**
     * @throws PreparationException
     */
    private function resume(JobPosting $job, ?ResumeVariant $variant): ResumeVariant
    {
        if ($variant !== null) {
            $variant->loadMissing('jobAnalysis');
            if ($variant->jobAnalysis?->job_posting_id !== $job->id || $variant->career_profile_id !== $job->career_profile_id) {
                throw new PreparationException('That resume was not made for this job.', 'resume_not_for_job');
            }

            return $variant;
        }

        $variant = $this->resumes->preferred($job) ?? $this->resumes->current($job);
        if ($variant === null) {
            throw new PreparationException('This job has no tailored resume yet.', 'no_resume');
        }
        if ($variant->career_profile_id !== $job->career_profile_id) {
            throw new PreparationException('That resume was not made for this job.', 'resume_not_for_job');
        }

        return $variant;
    }

    private function activeRun(Application $application): ?WorkflowRun
    {
        return $application->workflowRuns()
            ->active()
            ->where('workflow_type', WorkflowType::ApplicationPreparation)
            ->latest('id')
            ->first();
    }

    private function draftApplication(JobPosting $job): Application
    {
        $existing = Application::where('job_posting_id', $job->id)->first();
        if ($existing !== null) {
            return $existing;
        }

        try {
            return Application::create(['job_posting_id' => $job->id, 'status' => ApplicationStatus::Draft]);
        } catch (UniqueConstraintViolationException) {
            return Application::where('job_posting_id', $job->id)->firstOrFail();
        }
    }

    private function withoutQuery(string $url): string
    {
        $parts = parse_url($url);

        return ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '').($parts['path'] ?? '');
    }
}
