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
 * - the exact PDF submitted may be kept, from bytes the caller supplies or, with `snapshotServedPdf`, from the PDF Well
 *   Applied already has cached for the resume (never rendered here, and skipped, not invented, when it is not cached);
 *   a submission with no PDF is fine, and a retry never writes a second file;
 * - nothing here changes the job's disposition, and nothing requires an inspection record, a resume or a link.
 */
final class RecordSubmission
{
    /** Clock slack for a submitted-just-now time. */
    private const FUTURE_TOLERANCE_MINUTES = 5;

    public function __construct(
        private readonly SummarizeSubmission $summaries,
        private readonly SubmittedResumeSnapshot $snapshots,
    ) {}

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
        ?string $pdfBytes = null,
        bool $snapshotServedPdf = false,
    ): Submission {
        $jobId = $job instanceof JobPosting ? $job->id : $job;
        $idempotencyKey = $idempotencyKey === null || trim($idempotencyKey) === '' ? null : trim($idempotencyKey);

        if (! in_array($method, SubmissionMethod::supported(), true)) {
            throw new SubmissionException("A {$method->value} submission cannot be recorded yet; only manual ones can.", 'method_unsupported');
        }

        if ($submittedAt->isAfter(now()->addMinutes(self::FUTURE_TOLERANCE_MINUTES))) {
            throw new SubmissionException('The submission time cannot be in the future.', 'submitted_in_future');
        }

        if ($pdfBytes !== null && $snapshotServedPdf) {
            throw new SubmissionException('Give the PDF bytes or ask for the served PDF, not both.', 'snapshot_ambiguous');
        }

        if ($snapshotServedPdf && $resumeVariant === null) {
            throw new SubmissionException('There is no Well Applied resume to take a PDF from.', 'snapshot_needs_resume');
        }

        $applicationUrl = $this->url($applicationUrl);
        $notes = $notes === null || trim($notes) === '' ? null : mb_substr(trim($notes), 0, 5000);

        $written = null;

        try {
            return DB::transaction(function () use ($jobId, $submittedAt, $idempotencyKey, $allowAnother, $method, $resumeVariant, $application, $applicationUrl, $notes, $pdfBytes, $snapshotServedPdf, &$written) {
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

                $submission = Submission::query()->create([
                    'job_posting_id' => $posting->id,
                    'application_id' => $application?->id,
                    'resume_variant_id' => $resumeVariant?->id,
                    'application_url' => $applicationUrl,
                    'method' => $method,
                    // Eloquent writes the clock string as given, so a time in the person's zone must be moved to the app's first.
                    'submitted_at' => $submittedAt->copy()->setTimezone(config('app.timezone')),
                    'notes' => $notes,
                    'idempotency_key' => $idempotencyKey,
                ]);

                $bytes = $pdfBytes ?? ($snapshotServedPdf ? $this->snapshots->servedPdf($resumeVariant) : null);

                if ($bytes !== null) {
                    $kept = $this->snapshots->write($submission, $bytes);
                    $written = $kept['created'] ? $kept['path'] : null;
                    $submission->forceFill(['submitted_pdf_path' => $kept['path'], 'submitted_pdf_sha256' => $kept['sha256']])->save();
                }

                return $submission;
            });
        } catch (\Throwable $e) {
            // A file written for a recording that did not complete would be an orphan, with no row pointing at it.
            if ($written !== null) {
                $this->snapshots->discard($written);
            }

            throw $e;
        }
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
