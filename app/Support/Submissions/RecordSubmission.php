<?php

namespace App\Support\Submissions;

use App\Enums\SubmissionMethod;
use App\Models\Application;
use App\Models\JobPosting;
use App\Models\ResumeVariant;
use App\Models\Submission;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of a submission. A submission is a person's statement that they submitted an application: it is
 * recorded when they say so and never inferred from an inspection, a finished resume, a pursued job or a worker run,
 * and it is not proof the employer received anything. The rules, in one place so they hold whoever calls:
 *
 * - the job must exist, and is re-read under a lock inside the transaction so two requests cannot both pass the check
 *   for an already-active submission;
 * - a retry with the same idempotency key returns the submission already recorded rather than making a second (a key
 *   reused for a different job is refused);
 * - a job may have any number of submissions over time, but recording another while one is still active (not ended,
 *   not marked in error) needs `allowAnother: true`, so a double click or a forgotten earlier record never duplicates;
 * - the time is the one the person gives (not in the future), kept apart from the time of recording;
 * - nothing here changes the job's disposition, and nothing requires an inspection record, a resume or a link.
 */
final class RecordSubmission
{
    /** Clock slack for a submitted-just-now time. */
    private const FUTURE_TOLERANCE_MINUTES = 5;

    public function __construct(private readonly SummarizeSubmission $summaries) {}

    /**
     * @throws SubmissionException
     */
    public function record(
        JobPosting|int $job,
        CarbonInterface $submittedAt,
        ?string $idempotencyKey = null,
        bool $allowAnother = false,
        SubmissionMethod $method = SubmissionMethod::Manual,
        ?ResumeVariant $resumeVariant = null,
        ?Application $application = null,
        ?string $applicationUrl = null,
        ?string $notes = null,
    ): Submission {
        $jobId = $job instanceof JobPosting ? $job->id : $job;
        $idempotencyKey = $idempotencyKey === null || trim($idempotencyKey) === '' ? null : trim($idempotencyKey);

        if (! in_array($method, SubmissionMethod::supported(), true)) {
            throw new SubmissionException("A {$method->value} submission cannot be recorded yet; only manual ones can.", 'method_unsupported');
        }

        if ($submittedAt->isAfter(now()->addMinutes(self::FUTURE_TOLERANCE_MINUTES))) {
            throw new SubmissionException('The submission time cannot be in the future.', 'submitted_in_future');
        }

        $applicationUrl = $this->url($applicationUrl);
        $notes = $notes === null || trim($notes) === '' ? null : mb_substr(trim($notes), 0, 5000);

        return DB::transaction(function () use ($jobId, $submittedAt, $idempotencyKey, $allowAnother, $method, $resumeVariant, $application, $applicationUrl, $notes) {
            $posting = JobPosting::query()->lockForUpdate()->find($jobId)
                ?? throw new SubmissionException('That job does not exist.', 'job_not_found');

            if ($idempotencyKey !== null) {
                $existing = Submission::query()->where('idempotency_key', $idempotencyKey)->first();

                if ($existing !== null) {
                    if ($existing->job_posting_id !== $posting->id) {
                        throw new SubmissionException('That idempotency key was already used for a different job.', 'idempotency_key_reused');
                    }

                    return $existing;
                }
            }

            if ($resumeVariant !== null && $resumeVariant->jobAnalysis?->job_posting_id !== $posting->id) {
                throw new SubmissionException('That resume was not made for this job.', 'resume_not_for_job');
            }

            if ($application !== null && $application->job_posting_id !== $posting->id) {
                throw new SubmissionException('That inspection record belongs to a different job.', 'inspection_not_for_job');
            }

            if (! $allowAnother && $this->hasActiveSubmission($posting)) {
                throw new SubmissionException('An application to this job is already being tracked. Record another only if you really applied again.', 'active_submission_exists');
            }

            return Submission::query()->create([
                'job_posting_id' => $posting->id,
                'application_id' => $application?->id,
                'resume_variant_id' => $resumeVariant?->id,
                'application_url' => $applicationUrl,
                'method' => $method,
                'submitted_at' => $submittedAt,
                'notes' => $notes,
                'idempotency_key' => $idempotencyKey,
            ]);
        });
    }

    private function hasActiveSubmission(JobPosting $posting): bool
    {
        return $posting->submissions()->get()->contains(fn (Submission $s) => $this->summaries->forSubmission($s)->active);
    }

    private function url(?string $url): ?string
    {
        $url = $url === null ? null : trim($url);

        if ($url === null || $url === '') {
            return null;
        }

        if (strlen($url) > 2048 || ! in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            throw new SubmissionException('The application link must be a web address (http or https).', 'url_invalid');
        }

        return $url;
    }
}
