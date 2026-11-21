<?php

namespace App\Console\Commands;

use App\Support\JobFreshness\Pilot\PilotManifest;
use App\Support\JobFreshness\Pilot\PilotRefused;
use App\Support\JobFreshness\Pilot\PilotRunner;
use App\Support\JobFreshness\Pilot\RecordedPilotTransport;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The freshness pilot. PLAN and DRY-RUN only: live mode is unavailable (it needs a separate authorization and a code change). Both modes verify the approved manifest and re-read
 * the source records, make NO request and write NO database row. Dry-run feeds the runner recorded (or synthetic) answers and writes an evidence file and an observations file
 * (new files only) that `jobs:freshness-report --observations=` can replay.
 */
#[Signature('jobs:freshness-pilot
    {--manifest= : The approved manifest file}
    {--manifest-hash= : The approved manifest SHA-256 (named by the authorization)}
    {--mode=plan : plan | dry-run (live is unavailable)}
    {--responses= : dry-run only: a JSON file of recorded responses, in request order}
    {--out= : dry-run only: a new evidence file; the observations go to <out>.observations.json}')]
#[Description('Freshness pilot (plan and offline dry-run only; no request is ever made, nothing is written to the database)')]
class JobsFreshnessPilot extends Command
{
    public function handle(): int
    {
        $mode = (string) $this->option('mode');
        if ($mode === 'live') {
            $this->error('Live mode is unavailable: the pilot has not been authorized to run live.');

            return self::FAILURE;
        }
        if (! in_array($mode, ['plan', 'dry-run'], true)) {
            $this->error('--mode must be plan or dry-run.');

            return self::INVALID;
        }

        try {
            $manifest = PilotManifest::fromFile((string) $this->option('manifest'));
            $manifest->assertApproved($this->option('manifest-hash') === null ? null : (string) $this->option('manifest-hash'));

            if ($mode === 'plan') {
                $plan = $manifest->resolve();
                $this->info('Plan (nothing is sent): manifest '.$manifest->sha256);
                foreach ($plan['himalayas'] as $i => $row) {
                    $this->line(sprintf('  %d. himalayas.app  GET single job   local job %d', $i + 1, $row['job']->id));
                }
                $this->line(sprintf('  %d. jobicy.com     GET status batch  %d ids (local jobs %s)', count($plan['himalayas']) + 1, count($plan['jobicy']['jobs']), implode(',', array_map(fn ($j) => $j->id, $plan['jobicy']['jobs']))));
                $this->line(sprintf('  %d planned requests, cap %d, at least %d s apart, no retries, no pagination.', PilotManifest::PLANNED_REQUESTS, PilotManifest::REQUEST_CAP, PilotManifest::MIN_GAP_SECONDS));

                return self::SUCCESS;
            }

            $out = (string) $this->option('out');
            if ($out === '' || file_exists($out) || file_exists($out.'.observations.json') || ! is_dir(dirname($out))) {
                $this->error('--out must be a new file in an existing directory.');

                return self::INVALID;
            }
            $recorded = [];
            if ((string) $this->option('responses') !== '') {
                $recorded = json_decode((string) @file_get_contents((string) $this->option('responses')), true);
                if (! is_array($recorded)) {
                    $this->error('The responses file could not be read as a JSON list.');

                    return self::INVALID;
                }
            }
            $result = (new PilotRunner(new RecordedPilotTransport(array_values($recorded))))->run($manifest, 'dry-run');
            file_put_contents($out, json_encode($result['evidence'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n", LOCK_EX);
            file_put_contents($out.'.observations.json', json_encode($result['observations'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n", LOCK_EX);
            $this->info("Dry run {$result['evidence']['status']} ({$result['evidence']['requests_made']} offline answers, no request made). Evidence and observations written.");

            return self::SUCCESS;
        } catch (PilotRefused $e) {
            $this->error('Refused ('.$e->reason.'): '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
