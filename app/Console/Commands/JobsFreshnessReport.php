<?php

namespace App\Console\Commands;

use App\Enums\JobDiscoverySource;
use App\Models\JobPosting;
use App\Support\JobFreshness\CheckRequests;
use App\Support\JobFreshness\JobFreshness;
use App\Support\JobFreshness\Observation;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * READ-ONLY dry run of job freshness. It reads job_postings (and their metadata) and prints how many jobs would be classified active, expired, closed, uncertain or
 * unchecked, with the evidence and the INTENDED action. It makes no request to any provider, writes no row and changes no status; `--observations` replays recorded
 * observations from a local file so the policy can be reviewed on real data before any check is ever made.
 *
 * Observations file: [{"job_id": 12, "observations": [{provider, source, result, basis, observed_at, ...}]}]
 */
#[Signature('jobs:freshness-report
    {--json : Print the report as JSON}
    {--observations= : A local JSON file of recorded observations to replay (never fetched)}')]
#[Description('Read-only dry run: how many jobs are active, expired, closed, uncertain or unchecked, and what would be done. Writes nothing.')]
class JobsFreshnessReport extends Command
{
    public function handle(): int
    {
        $replay = $this->replay((string) $this->option('observations'));
        if ($replay === false) {
            $this->error('The observations file could not be read as the expected JSON.');

            return self::INVALID;
        }

        $report = ['read_only' => true, 'requests_made' => 0, 'feature_enabled' => JobFreshness::enabled(), 'sources' => [], 'totals' => ['jobs' => 0], 'intended_actions' => [], 'would_need_checks' => ['himalayas_requests' => 0, 'jobicy_batches' => 0], 'examples' => []];
        $jobicy = [];

        foreach (JobPosting::query()->orderBy('id')->cursor() as $job) {
            $source = $job->discovery_source->value;
            $d = JobFreshness::decide($job, null, $replay[$job->id] ?? []);

            $supported = in_array($job->discovery_source, [JobDiscoverySource::Himalayas, JobDiscoverySource::Jobicy], true);
            $state = ($d->state->value === 'unchecked' && ! $supported) ? 'unchecked' : $d->state->value;

            $report['totals']['jobs']++;
            $report['sources'][$source][$state] = ($report['sources'][$source][$state] ?? 0) + 1;
            $report['sources'][$source]['provider_check_exists'] = $supported;
            $report['intended_actions'][$d->action] = ($report['intended_actions'][$d->action] ?? 0) + 1;
            if ($d->protected) {
                $report['intended_actions']['protected_jobs'] = ($report['intended_actions']['protected_jobs'] ?? 0) + 1;
            }
            if ($d->conflict) {
                $report['intended_actions']['conflicting_observations'] = ($report['intended_actions']['conflicting_observations'] ?? 0) + 1;
            }
            if ($supported && in_array($d->state->value, ['unchecked', 'uncertain'], true)) {
                if ($job->discovery_source === JobDiscoverySource::Himalayas && CheckRequests::himalayas($job) !== null) {
                    $report['would_need_checks']['himalayas_requests']++;
                } elseif ($job->discovery_source === JobDiscoverySource::Jobicy) {
                    $jobicy[] = $job;
                }
            }
            if (count($report['examples'][$d->state->value] ?? []) < 3 && ($d->state->value !== 'unchecked' || $supported)) {
                $report['examples'][$d->state->value][] = ['job_id' => $job->id, 'source' => $source, 'action' => $d->action, 'reason' => $d->reason, 'protected' => $d->protected, 'evidence' => array_map(fn ($e) => array_intersect_key($e, array_flip(['source', 'result', 'basis'])), $d->evidence)];
            }
        }
        $report['would_need_checks']['jobicy_batches'] = count(CheckRequests::jobicyBatches($jobicy));
        ksort($report['sources']);

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('Job freshness (dry run: read-only, no request made, nothing changed). Feature flag: '.($report['feature_enabled'] ? 'ON' : 'off'));
        foreach ($report['sources'] as $source => $counts) {
            $this->line(sprintf('  %-10s %s', $source, collect($counts)->except('provider_check_exists')->map(fn ($n, $s) => "$s $n")->implode(' · ').($counts['provider_check_exists'] ? '' : '  (no provider-supported check)')));
        }
        $this->line('  intended actions: '.collect($report['intended_actions'])->map(fn ($n, $a) => "$a $n")->implode(' · '));
        $this->line("  a real check would need: {$report['would_need_checks']['himalayas_requests']} Himalayas requests, {$report['would_need_checks']['jobicy_batches']} Jobicy batch requests");

        return self::SUCCESS;
    }

    /** @return array<int, list<Observation>>|false */
    private function replay(string $path): array|false
    {
        if ($path === '') {
            return [];
        }
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (! is_array($data)) {
            return false;
        }
        $out = [];
        foreach ($data as $row) {
            if (! is_array($row) || ! is_int($row['job_id'] ?? null) || ! is_array($row['observations'] ?? null)) {
                return false;
            }
            foreach ($row['observations'] as $o) {
                $obs = Observation::fromArray($o);
                if ($obs !== null) {
                    $out[$row['job_id']][] = $obs;
                }
            }
        }

        return $out;
    }
}
