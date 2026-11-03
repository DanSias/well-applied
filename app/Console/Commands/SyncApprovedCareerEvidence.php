<?php

namespace App\Console\Commands;

use App\Support\CareerData\ApprovedEvidenceFile;
use App\Support\CurrentCareerProfile;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('career:sync-approved
    {path? : Input path, relative to the project root. Defaults to data/approved-career-evidence.json}
    {--accept-file : Explicitly replace database facts that differ from the file (otherwise differences are reported as conflicts and skipped)}')]
#[Description('Load approved user evidence from portable JSON. Creates what is missing, reports conflicts, never overwrites silently.')]
class SyncApprovedCareerEvidence extends Command
{
    public function handle(ApprovedEvidenceFile $file): int
    {
        $path = $this->argument('path') ?? ApprovedEvidenceFile::DEFAULT_PATH;
        $full = str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : base_path($path);

        if (! is_file($full)) {
            $this->error("Approved-evidence file not found at [{$full}].");

            return self::FAILURE;
        }

        try {
            $payload = json_decode((string) file_get_contents($full), associative: true, flags: JSON_THROW_ON_ERROR);
            $result = $file->sync(CurrentCareerProfile::resolve(), $payload, (bool) $this->option('accept-file'));
        } catch (Throwable $e) {
            $this->error('Sync failed and was rolled back: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Created %d, unchanged %d, replaced %d.', $result['created'], $result['unchanged'], $result['replaced']));

        foreach ($result['conflicts'] as $conflict) {
            $this->warn("Conflict [{$conflict['key']}]: database has \"{$conflict['database']}\" but the file has \"{$conflict['file']}\". Neither was changed.");
        }

        return $result['conflicts'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
