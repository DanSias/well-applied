<?php

namespace App\Console\Commands;

use App\Support\CareerData\ApprovedEvidenceFile;
use App\Support\CurrentCareerProfile;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('career:export-approved {path? : Output path, relative to the project root. Defaults to data/approved-career-evidence.json}')]
#[Description('Export approved user evidence (facts, limitations, presentation preferences) to portable JSON. Idempotent.')]
class ExportApprovedCareerEvidence extends Command
{
    public function handle(ApprovedEvidenceFile $file): int
    {
        $path = $this->argument('path') ?? ApprovedEvidenceFile::DEFAULT_PATH;
        $full = str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : base_path($path);

        $payload = $file->export(CurrentCareerProfile::resolve());
        $contents = $file->encode($payload);

        if (is_file($full) && file_get_contents($full) === $contents) {
            $this->info("Already up to date: {$full}");

            return self::SUCCESS;
        }

        if (! is_dir(dirname($full))) {
            mkdir(dirname($full), 0755, true);
        }

        file_put_contents($full, $contents);
        $this->info(sprintf('Exported %d facts, %d limitations, %d preferences to %s', count($payload['facts']), count($payload['limitations']), count($payload['presentation_preferences']), $full));

        return self::SUCCESS;
    }
}
