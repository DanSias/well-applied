<?php

namespace App\Support\Submissions;

use App\Models\ResumeVariant;
use App\Models\Submission;
use App\Support\ResumeDocument\GenerateResumeDocument;
use App\Support\ResumeDocument\ResumePdfStore;
use Illuminate\Support\Facades\Storage;

/**
 * The exact PDF a person submitted, kept so what went out never depends on regenerating anything. ResumePdfStore is a
 * content-addressed cache that rebuilds on demand and changes with the print template, so it cannot prove what was sent;
 * this keeps the bytes themselves:
 *
 * - on the private `local` disk (storage/app/private), never the public one, under `submissions/{id}/resume-{sha256}.pdf`;
 * - written once: an existing file is never overwritten, and a same-named file with different bytes is refused;
 * - recorded with its SHA-256 on the submission, and checked against it whenever it is read back.
 *
 * Only real bytes are snapshotted. Taking the PDF "as served" reads the cache and never renders one: if it is not
 * cached, there is no snapshot, and nothing is invented. A submission without a Well Applied resume simply has none.
 */
final class SubmittedResumeSnapshot
{
    public const DISK = 'local';

    public const MAX_BYTES = 10 * 1024 * 1024;

    public function __construct(
        private readonly GenerateResumeDocument $documents,
        private readonly ResumePdfStore $store,
    ) {}

    /**
     * The validated PDF Well Applied has cached for this variant, or null. Never renders or regenerates.
     */
    public function servedPdf(ResumeVariant $variant): ?string
    {
        return $this->store->get($this->documents->generate($variant));
    }

    /**
     * The largest PDF that can actually be received: the snapshot limit, or less when PHP is configured to accept smaller
     * uploads (upload_max_filesize, post_max_size). The form must promise the smaller number, or a file under the stated
     * limit fails with "failed to upload" before any rule can say why.
     */
    public static function maxUploadBytes(): int
    {
        $limits = [self::MAX_BYTES];

        foreach (['upload_max_filesize', 'post_max_size'] as $setting) {
            $bytes = ini_parse_quantity((string) ini_get($setting));

            if ($bytes > 0) {
                $limits[] = $bytes;
            }
        }

        return min($limits);
    }

    /** Whether a served PDF is cached for this variant (so a snapshot of it could be kept). Never renders one. */
    public function isServedPdfCached(ResumeVariant $variant): bool
    {
        return $this->servedPdf($variant) !== null;
    }

    /**
     * Writes the bytes and returns where and what they are. Does not touch the submission row; see RecordSubmission.
     *
     * @return array{path: string, sha256: string, created: bool}
     *
     * @throws SubmissionException
     */
    public function write(Submission $submission, string $bytes): array
    {
        if (! str_starts_with($bytes, '%PDF-')) {
            throw new SubmissionException('That file is not a PDF.', 'snapshot_not_pdf');
        }

        if (strlen($bytes) > self::MAX_BYTES) {
            throw new SubmissionException('That PDF is too large to keep.', 'snapshot_too_large');
        }

        $sha256 = hash('sha256', $bytes);
        $path = "submissions/{$submission->id}/resume-{$sha256}.pdf";
        $disk = Storage::disk(self::DISK);

        if ($disk->exists($path)) {
            if (hash('sha256', (string) $disk->get($path)) !== $sha256) {
                throw new SubmissionException('A different file is already kept under that name.', 'snapshot_conflict');
            }

            return ['path' => $path, 'sha256' => $sha256, 'created' => false];
        }

        if (! $disk->put($path, $bytes)) {
            throw new SubmissionException('The submitted PDF could not be kept.', 'snapshot_failed');
        }

        return ['path' => $path, 'sha256' => $sha256, 'created' => true];
    }

    /**
     * Removes a file this class just created, when the recording that wanted it did not complete.
     */
    public function discard(string $path): void
    {
        Storage::disk(self::DISK)->delete($path);
    }

    /**
     * The kept bytes, only if they still match the recorded SHA-256; null when there is no snapshot or it is damaged.
     */
    public function bytes(Submission $submission): ?string
    {
        if ($submission->submitted_pdf_path === null || $submission->submitted_pdf_sha256 === null) {
            return null;
        }

        $bytes = Storage::disk(self::DISK)->get($submission->submitted_pdf_path);

        return is_string($bytes) && hash('sha256', $bytes) === $submission->submitted_pdf_sha256 ? $bytes : null;
    }
}
