<?php

namespace App\Console\Commands;

use App\Support\JobFreshness\Pilot\PilotManifest;
use App\Support\JobFreshness\Pilot\PilotRefused;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/** Writes the approved sample as a manifest file. READ-ONLY on the database; no request; refuses to overwrite. */
#[Signature('jobs:freshness-pilot-manifest {--out= : A new file to write}')]
#[Description('Build the approved freshness pilot manifest from the current rows (read-only; no request)')]
class JobsFreshnessPilotManifest extends Command
{
    /** The approved sample (local job ids): 8 Himalayas (oldest, middle, newest), 23 Jobicy (7 recent, 7 mid-age, 7 older, 2 older protected). */
    public const HIMALAYAS = [3, 4, 5, 27, 57, 85, 207, 391];

    public const JOBICY = [254, 255, 256, 257, 298, 299, 300, 103, 105, 106, 107, 108, 109, 110, 29, 30, 31, 32, 33, 34, 35, 82, 97];

    public function handle(): int
    {
        $out = (string) $this->option('out');
        if ($out === '' || file_exists($out) || ! is_dir(dirname($out))) {
            $this->error('--out must be a new file in an existing directory.');

            return self::INVALID;
        }
        try {
            $manifest = PilotManifest::build(self::HIMALAYAS, self::JOBICY, now()->toIso8601String());
        } catch (PilotRefused $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        file_put_contents($out, $manifest->json(), LOCK_EX);
        $this->info('Manifest written. SHA-256: '.hash('sha256', $manifest->json()));

        return self::SUCCESS;
    }
}
