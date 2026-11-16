<?php

namespace App\Console\Commands;

use App\Enums\DiscoveryStatus;
use App\Enums\JobDiscoverySource;
use App\Support\JobDiscovery\DiscoveryScheduleIsDue;
use App\Support\JobDiscovery\RunJobDiscovery;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Manual trigger for the discovery pipeline — calls the exact same
 * App\Support\JobDiscovery\RunJobDiscovery service a later commit's
 * scheduled execution also calls. No separate manual-vs-scheduled
 * implementation exists: without `--due` every provider runs; with
 * it (the scheduler's invocation) only the currently due ones do. See
 * docs/job-discovery.md "Manual discovery command".
 *
 * A provider another execution (scheduled, queued or manual) is already running is skipped with a warning. If that was
 * everything requested the command exits 0 under `--due` and 1 otherwise; see "Execution safety".
 */
#[Signature('discovery:run {--due : Only run providers whose schedule is currently due (used by the scheduler)} {--only=* : Run only these providers (e.g. --only=eightfold)}')]
#[Description('Run the automatic job discovery pipeline once: query providers, filter, canonically enrich, deduplicate, and ingest into JobPosting.')]
class RunJobDiscoveryCommand extends Command
{
    public function handle(RunJobDiscovery $runJobDiscovery): int
    {
        $only = null;

        $requested = array_filter((array) $this->option('only'), 'is_string');
        if ($requested !== []) {
            $only = [];
            foreach ($requested as $name) {
                $source = JobDiscoverySource::tryFrom($name);
                if ($source === null || $source === JobDiscoverySource::Manual) {
                    $this->components->error("Unknown discovery provider \"{$name}\".");

                    return self::FAILURE;
                }
                $only[] = $source;
            }
        }

        if ($this->option('due')) {
            $due = (new DiscoveryScheduleIsDue)->dueSources();
            $only = $only === null ? $due : array_values(array_filter($only, fn ($source) => in_array($source, $due, true)));

            if ($only === []) {
                $this->components->info('No discovery providers are due.');

                return self::SUCCESS;
            }
        }

        $this->components->info('Running job discovery…');

        $execution = $runJobDiscovery->execute($only, scheduled: (bool) $this->option('due'));
        $discoveryRun = $execution->run;

        // A provider another execution is already running is skipped, never recorded as an attempt. When that was
        // everything requested, nothing ran: a scheduled (--due) tick treats that as fine, a manual run as a failure.
        if ($execution->skipped !== []) {
            $names = implode(', ', array_map(fn (JobDiscoverySource $source) => $source->value, $execution->skipped));
            $this->components->warn("Skipped, already running elsewhere: {$names}.");
        }

        if ($execution->deferred !== []) {
            $names = implode(', ', array_map(fn (JobDiscoverySource $source) => $source->value, $execution->deferred));
            $this->components->warn("Skipped, a queued run is already pending: {$names}.");
        }

        if (! $execution->recorded()) {
            $this->components->info('Nothing was run: every requested provider is already running or has a queued run pending.');

            return $this->option('due') ? self::SUCCESS : self::FAILURE;
        }

        $rows = $discoveryRun->providerAttempts->map(fn ($attempt) => [
            $attempt->provider->value,
            $attempt->status->value,
            $attempt->candidates_retrieved ?? '—',
            $attempt->candidates_accepted ?? '—',
            $attempt->jobs_created ?? '—',
            $attempt->jobs_updated ?? '—',
            $attempt->jobs_canonicalized ?? '—',
            $attempt->failure_message ?? '',
        ])->all();

        $this->table(
            ['Provider', 'Status', 'Retrieved', 'Accepted', 'Created', 'Updated', 'Canonicalized', 'Failure'],
            $rows,
        );

        $anyFailed = $discoveryRun->providerAttempts->contains(fn ($attempt) => $attempt->status === DiscoveryStatus::Failed);
        $anyPartial = $discoveryRun->providerAttempts->contains(fn ($attempt) => $attempt->status === DiscoveryStatus::Partial);

        if ($anyFailed) {
            $this->components->warn('At least one provider failed this run — see the table above. Other providers\' results were still ingested.');
        }

        if ($anyPartial) {
            $this->components->warn('At least one provider only partly succeeded (some of its sources failed) — see the table above. What it did retrieve was ingested.');
        }

        $this->components->info("Discovery run #{$discoveryRun->id} finished with status {$discoveryRun->status->value}.");

        return $discoveryRun->status === DiscoveryStatus::Failed ? self::FAILURE : self::SUCCESS;
    }
}
