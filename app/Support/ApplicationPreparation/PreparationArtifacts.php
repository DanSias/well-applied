<?php

namespace App\Support\ApplicationPreparation;

use App\Models\AgentRun;
use Illuminate\Support\Facades\Storage;

/**
 * Everything one preparation attempt keeps on disk, and the only code that touches it. All of it lives on the private
 * `local` disk (storage/app/private), never a public path, under `application-preparations/{agent_run_id}/`, and every
 * path is built from the integer run id and a fixed file name or a strictly validated screenshot name, so nothing a
 * worker sends can choose a location:
 *
 * - input.json: the exact applicant values this attempt was given. Private, write-once, SHA-256 recorded in
 *   agent_runs.input_summary and checked on every read.
 * - resume-{sha256}.pdf: the resume bytes this attempt was given. Write-once, hash-checked on every read. Not a
 *   Submission snapshot (see SubmittedResumeSnapshot): nothing here implies anything was submitted.
 * - screenshots/{name}.png: uploaded by the worker; PNG only, size-capped, write-once (an identical re-upload is a
 *   no-op, a different file under the same name is refused).
 * - report.json: the sanitized report (see SanitizePreparationReport), write-once.
 */
final class PreparationArtifacts
{
    public const DISK = 'local';

    public const ROOT = 'application-preparations';

    public const MAX_RESUME_BYTES = 10 * 1024 * 1024;

    public const MAX_SCREENSHOT_BYTES = 3 * 1024 * 1024;

    public const MAX_SCREENSHOTS = 60;

    public const MAX_JSON_BYTES = 2 * 1024 * 1024;

    /** The worker's own screenshot names: phase-NNofMM-yNNNNN.png (see workers/application-preparer/src/evidence.ts). */
    public const SCREENSHOT_NAME = '/^[a-z][a-z_]{0,19}-\d{2}of\d{2}-y\d{5}\.png$/';

    private const PNG_MAGIC = "\x89PNG\r\n\x1a\n";

    public static function isScreenshotName(string $name): bool
    {
        return preg_match(self::SCREENSHOT_NAME, $name) === 1;
    }

    public function directory(AgentRun $run): string
    {
        return self::ROOT.'/'.(int) $run->id;
    }

    // ---- input snapshot -------------------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $input
     * @return array{path: string, sha256: string}
     */
    public function writeInput(AgentRun $run, array $input): array
    {
        $json = json_encode($input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return $this->writeOnce($this->directory($run).'/input.json', $json);
    }

    /**
     * The attempt's input exactly as written, only if it still matches the hash recorded at dispatch.
     *
     * @return array<string, mixed>|null
     */
    public function readInput(AgentRun $run): ?array
    {
        $bytes = $this->readChecked($this->directory($run).'/input.json', $run->input_summary['input_sha256'] ?? null);
        $decoded = $bytes === null ? null : json_decode($bytes, true);

        return is_array($decoded) ? $decoded : null;
    }

    // ---- resume ---------------------------------------------------------------------------------------------------

    /**
     * @return array{path: string, sha256: string}
     */
    public function writeResume(AgentRun $run, string $bytes): array
    {
        if (! str_starts_with($bytes, '%PDF-')) {
            throw new PreparationException('That file is not a PDF.', 'resume_not_pdf');
        }
        if (strlen($bytes) > self::MAX_RESUME_BYTES) {
            throw new PreparationException('That PDF is too large to hand to the worker.', 'resume_too_large');
        }

        return $this->writeOnce($this->directory($run).'/resume-'.hash('sha256', $bytes).'.pdf', $bytes);
    }

    /**
     * The attempt's resume bytes, only if they still match the SHA-256 recorded at dispatch.
     */
    public function readResume(AgentRun $run): ?string
    {
        $sha = $run->input_summary['resume_sha256'] ?? null;

        return is_string($sha) && preg_match('/^[0-9a-f]{64}$/', $sha) === 1
            ? $this->readChecked($this->directory($run).'/resume-'.$sha.'.pdf', $sha)
            : null;
    }

    // ---- screenshots ----------------------------------------------------------------------------------------------

    /**
     * @return 'created'|'duplicate'
     *
     * @throws PreparationException
     */
    public function putScreenshot(AgentRun $run, string $name, string $bytes): string
    {
        if (! self::isScreenshotName($name)) {
            throw new PreparationException('That is not a screenshot name.', 'artifact_name_invalid');
        }
        if (! str_starts_with($bytes, self::PNG_MAGIC)) {
            throw new PreparationException('A screenshot must be a PNG.', 'artifact_not_png');
        }
        if (strlen($bytes) > self::MAX_SCREENSHOT_BYTES) {
            throw new PreparationException('That screenshot is too large.', 'artifact_too_large');
        }

        $path = $this->directory($run).'/screenshots/'.$name;
        $disk = Storage::disk(self::DISK);

        if ($disk->exists($path)) {
            if (hash('sha256', (string) $disk->get($path)) !== hash('sha256', $bytes)) {
                throw new PreparationException('A different screenshot is already kept under that name.', 'artifact_conflict');
            }

            return 'duplicate';
        }
        if (count($this->screenshotNames($run)) >= self::MAX_SCREENSHOTS) {
            throw new PreparationException('Too many screenshots for one attempt.', 'artifact_limit');
        }
        if (! $disk->put($path, $bytes)) {
            throw new PreparationException('The screenshot could not be kept.', 'artifact_failed');
        }

        return 'created';
    }

    public function screenshot(AgentRun $run, string $name): ?string
    {
        if (! self::isScreenshotName($name)) {
            return null;
        }
        $bytes = Storage::disk(self::DISK)->get($this->directory($run).'/screenshots/'.$name);

        return is_string($bytes) ? $bytes : null;
    }

    /**
     * @return array<int, string>
     */
    public function screenshotNames(AgentRun $run): array
    {
        $names = [];
        foreach (Storage::disk(self::DISK)->files($this->directory($run).'/screenshots') as $file) {
            $name = basename($file);
            if (self::isScreenshotName($name)) {
                $names[] = $name;
            }
        }
        sort($names);

        return $names;
    }

    // ---- report ---------------------------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $report
     * @return array{path: string, sha256: string}
     */
    public function writeReport(AgentRun $run, array $report): array
    {
        $json = json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (strlen($json) > self::MAX_JSON_BYTES) {
            throw new PreparationException('That report is too large.', 'report_too_large');
        }

        return $this->writeOnce($this->directory($run).'/report.json', $json);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function readReport(AgentRun $run): ?array
    {
        $sha = $run->result_summary['report_sha256'] ?? null;
        $bytes = is_string($sha) ? $this->readChecked($this->directory($run).'/report.json', $sha) : null;
        $decoded = $bytes === null ? null : json_decode($bytes, true);

        return is_array($decoded) ? $decoded : null;
    }

    // ---- housekeeping ---------------------------------------------------------------------------------------------

    /**
     * Removes what a dispatch or a delivery wrote when the database work around it did not complete, so no file is
     * left without a row that explains it.
     */
    public function discard(AgentRun $run): void
    {
        Storage::disk(self::DISK)->deleteDirectory($this->directory($run));
    }

    /**
     * Removes the frozen applicant values and resume of an attempt that was cancelled before any worker had them. Other
     * files (there are none for a queued attempt) are left alone.
     */
    public function discardInputs(AgentRun $run): void
    {
        $disk = Storage::disk(self::DISK);
        $dir = $this->directory($run);
        $disk->delete($dir.'/input.json');
        foreach ($disk->files($dir) as $file) {
            if (str_starts_with(basename($file), 'resume-')) {
                $disk->delete($file);
            }
        }
    }

    public function deleteFile(string $path): void
    {
        Storage::disk(self::DISK)->delete($path);
    }

    // ---- internals ------------------------------------------------------------------------------------------------

    /**
     * @return array{path: string, sha256: string}
     */
    private function writeOnce(string $path, string $bytes): array
    {
        $sha256 = hash('sha256', $bytes);
        $disk = Storage::disk(self::DISK);

        if ($disk->exists($path)) {
            if (hash('sha256', (string) $disk->get($path)) !== $sha256) {
                throw new PreparationException('A different file is already kept under that name.', 'snapshot_conflict');
            }

            return ['path' => $path, 'sha256' => $sha256];
        }
        if (! $disk->put($path, $bytes)) {
            throw new PreparationException('The file could not be kept.', 'snapshot_failed');
        }

        return ['path' => $path, 'sha256' => $sha256];
    }

    private function readChecked(string $path, mixed $expectedSha256): ?string
    {
        if (! is_string($expectedSha256)) {
            return null;
        }
        $bytes = Storage::disk(self::DISK)->get($path);

        return is_string($bytes) && hash_equals($expectedSha256, hash('sha256', $bytes)) ? $bytes : null;
    }
}
