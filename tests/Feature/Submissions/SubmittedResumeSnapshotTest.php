<?php

use App\Enums\SubmissionEventType;
use App\Models\JobAnalysis;
use App\Models\JobMatch;
use App\Models\JobPosting;
use App\Models\ResumeVariant;
use App\Models\Submission;
use App\Support\ResumeDocument\GenerateResumeDocument;
use App\Support\ResumeDocument\ResumePdfStore;
use App\Support\Submissions\RecordSubmission;
use App\Support\Submissions\RecordSubmissionEvent;
use App\Support\Submissions\SubmissionException;
use App\Support\Submissions\SubmittedResumeSnapshot;
use Illuminate\Support\Facades\Storage;

const PDF_A = "%PDF-1.7\nexact bytes A\n%%EOF";
const PDF_B = "%PDF-1.7\nexact bytes B\n%%EOF";

function snapshotVariant(?JobPosting $job = null): ResumeVariant
{
    $job ??= JobPosting::factory()->create();
    $analysis = JobAnalysis::factory()->for($job, 'jobPosting')->create();
    $match = JobMatch::factory()->create(['job_analysis_id' => $analysis->id]);

    return ResumeVariant::factory()->create(['job_analysis_id' => $analysis->id, 'job_match_id' => $match->id]);
}

function cacheServedPdf(ResumeVariant $variant, string $bytes): void
{
    app(ResumePdfStore::class)->put(app(GenerateResumeDocument::class)->generate($variant), $bytes);
}

it('keeps the exact bytes and their SHA-256 on the private disk', function () {
    $variant = snapshotVariant();
    $job = $variant->jobAnalysis->jobPosting;

    $submission = app(RecordSubmission::class)->record($job, now()->subHour(), resumeVariant: $variant, pdfBytes: PDF_A);

    expect($submission->submitted_pdf_sha256)->toBe(hash('sha256', PDF_A))
        ->and($submission->submitted_pdf_path)->toBe("submissions/{$submission->id}/resume-".hash('sha256', PDF_A).'.pdf')
        ->and(Storage::disk('local')->get($submission->submitted_pdf_path))->toBe(PDF_A)
        ->and(app(SubmittedResumeSnapshot::class)->bytes($submission->fresh()))->toBe(PDF_A);
});

it('never puts a snapshot on the public disk', function () {
    Storage::fake('public');
    $submission = app(RecordSubmission::class)->record(JobPosting::factory()->create(), now(), pdfBytes: PDF_A);

    expect(Storage::disk('public')->allFiles())->toBe([])
        ->and(Storage::disk('local')->exists($submission->submitted_pdf_path))->toBeTrue()
        ->and(config('filesystems.disks.local.root'))->toEndWith('storage/app/private')
        ->and($submission->submitted_pdf_path)->not->toStartWith('public');
});

it('allows a PDF made elsewhere, with no Well Applied resume', function () {
    $submission = app(RecordSubmission::class)->record(JobPosting::factory()->create(), now(), pdfBytes: PDF_A);

    expect($submission->resume_variant_id)->toBeNull()->and($submission->submitted_pdf_sha256)->toBe(hash('sha256', PDF_A));
});

it('records no snapshot when there is no PDF, and does not invent one', function () {
    $submission = app(RecordSubmission::class)->record(JobPosting::factory()->create(), now());

    expect($submission->submitted_pdf_path)->toBeNull()->and($submission->submitted_pdf_sha256)->toBeNull()
        ->and(Storage::disk('local')->allFiles())->toBe([])
        ->and(app(SubmittedResumeSnapshot::class)->bytes($submission))->toBeNull();
});

describe('the PDF as served', function () {
    it('is kept when Well Applied has it cached', function () {
        $variant = snapshotVariant();
        cacheServedPdf($variant, PDF_A);

        $submission = app(RecordSubmission::class)->record($variant->jobAnalysis->jobPosting, now(), resumeVariant: $variant, snapshotServedPdf: true);

        expect($submission->submitted_pdf_sha256)->toBe(hash('sha256', PDF_A))
            ->and(app(SubmittedResumeSnapshot::class)->bytes($submission))->toBe(PDF_A);
    });

    it('is skipped, never regenerated, when it is not cached', function () {
        $variant = snapshotVariant();
        $files = Storage::disk('local')->allFiles();

        $submission = app(RecordSubmission::class)->record($variant->jobAnalysis->jobPosting, now(), resumeVariant: $variant, snapshotServedPdf: true);

        expect($submission->submitted_pdf_path)->toBeNull()
            ->and($submission->resume_variant_id)->toBe($variant->id)
            ->and(Storage::disk('local')->allFiles())->toBe($files);
    });

    it('stays as it was when the cache later changes, because it is a copy', function () {
        $variant = snapshotVariant();
        cacheServedPdf($variant, PDF_A);
        $submission = app(RecordSubmission::class)->record($variant->jobAnalysis->jobPosting, now(), resumeVariant: $variant, snapshotServedPdf: true);

        cacheServedPdf($variant, PDF_B);

        expect(app(SubmittedResumeSnapshot::class)->bytes($submission->fresh()))->toBe(PDF_A);
    });

    it('needs a resume to take it from, and cannot be combined with supplied bytes', function () {
        $job = JobPosting::factory()->create();
        $refuse = function (callable $fn, string $reason) {
            try {
                $fn();
            } catch (SubmissionException $e) {
                expect($e->reason)->toBe($reason);

                return;
            }
            throw new RuntimeException('Expected a refusal.');
        };

        $refuse(fn () => app(RecordSubmission::class)->record($job, now(), snapshotServedPdf: true), 'snapshot_needs_resume');
        $refuse(fn () => app(RecordSubmission::class)->record($job, now(), resumeVariant: snapshotVariant($job), pdfBytes: PDF_A, snapshotServedPdf: true), 'snapshot_ambiguous');
        expect(Submission::count())->toBe(0);
    });
});

describe('bad files', function () {
    it('refuses what is not a PDF, or is too large, and records nothing', function (string $bytes, string $reason) {
        try {
            app(RecordSubmission::class)->record(JobPosting::factory()->create(), now(), pdfBytes: $bytes);
        } catch (SubmissionException $e) {
            expect($e->reason)->toBe($reason)->and(Submission::count())->toBe(0)->and(Storage::disk('local')->allFiles())->toBe([]);

            return;
        }
        throw new RuntimeException('Expected a refusal.');
    })->with([
        'not a pdf' => ['<html>hi</html>', 'snapshot_not_pdf'],
        'empty' => ['', 'snapshot_not_pdf'],
        'too large' => ['%PDF-'.str_repeat('x', 10 * 1024 * 1024), 'snapshot_too_large'],
    ]);

    it('writes nothing when recording is refused', function () {
        $job = JobPosting::factory()->create();
        Submission::factory()->create(['job_posting_id' => $job->id, 'idempotency_key' => 'taken']);
        $before = Storage::disk('local')->allFiles();

        // The key belongs to another job's row, so recording is refused before anything is written.
        expect(fn () => app(RecordSubmission::class)->record(JobPosting::factory()->create(), now(), 'taken', pdfBytes: PDF_A))->toThrow(SubmissionException::class)
            ->and(Storage::disk('local')->allFiles())->toBe($before);
    });
});

describe('durability', function () {
    it('never overwrites a kept file, and refuses a different one under the same name', function () {
        $snapshots = app(SubmittedResumeSnapshot::class);
        $submission = Submission::factory()->create();

        $first = $snapshots->write($submission, PDF_A);
        $again = $snapshots->write($submission, PDF_A);

        expect($first['created'])->toBeTrue()->and($again['created'])->toBeFalse()->and($again['path'])->toBe($first['path']);

        Storage::disk('local')->put($first['path'], PDF_B);

        expect(fn () => $snapshots->write($submission, PDF_A))->toThrow(SubmissionException::class);
        expect(Storage::disk('local')->get($first['path']))->toBe(PDF_B);
    });

    it('writes different bytes for the same submission to a different name', function () {
        $snapshots = app(SubmittedResumeSnapshot::class);
        $submission = Submission::factory()->create();

        $a = $snapshots->write($submission, PDF_A);
        $b = $snapshots->write($submission, PDF_B);

        expect($a['path'])->not->toBe($b['path'])->and(Storage::disk('local')->exists($a['path']))->toBeTrue();
    });

    it('does not serve bytes that no longer match the recorded hash', function () {
        $submission = app(RecordSubmission::class)->record(JobPosting::factory()->create(), now(), pdfBytes: PDF_A);

        Storage::disk('local')->put($submission->submitted_pdf_path, PDF_B);

        expect(app(SubmittedResumeSnapshot::class)->bytes($submission->fresh()))->toBeNull();
    });

    it('does not write a second file when a retry returns the same submission', function () {
        $job = JobPosting::factory()->create();
        $first = app(RecordSubmission::class)->record($job, now(), 'snap-1', pdfBytes: PDF_A);
        $files = Storage::disk('local')->allFiles();

        $retry = app(RecordSubmission::class)->record($job, now(), 'snap-1', pdfBytes: PDF_B);

        expect($retry->id)->toBe($first->id)->and($retry->submitted_pdf_sha256)->toBe(hash('sha256', PDF_A))
            ->and(Storage::disk('local')->allFiles())->toBe($files);
    });

    it('survives the submission ending or being marked in error, because history is kept', function () {
        $submission = app(RecordSubmission::class)->record(JobPosting::factory()->create(), now()->subDay(), pdfBytes: PDF_A);

        app(RecordSubmissionEvent::class)->record($submission, SubmissionEventType::RecordedInError, now());

        expect(app(SubmittedResumeSnapshot::class)->bytes($submission->fresh()))->toBe(PDF_A);
    });
});
