<?php

namespace App\Console\Commands;

use App\Support\CurrentCareerProfile;
use App\Support\ResumeProfile\BuildResumeProfile;
use App\Support\ResumeProfile\ProfileDefinitions;
use Illuminate\Console\Command;

class BuildResumeProfileCommand extends Command
{
    protected $signature = 'profile:build {slug=full-stack-product} {--pilot : Smaller pilot sample} {--no-fetch : Use stored and previously retrieved postings only} {--force : Fetch even inside a provider\'s refetch window} {--enrich : Also phrase the questions with the local model (one batched call)}';

    protected $description = 'Sample the market for a Resume Profile, aggregate its requirements and open the discovery interview (no model calls).';

    public function handle(BuildResumeProfile $build): int
    {
        $profile = ProfileDefinitions::ensure(CurrentCareerProfile::resolve(), (string) $this->argument('slug'));
        $result = $build->run($profile, (bool) $this->option('pilot'), ! $this->option('no-fetch'), (bool) $this->option('force'), $this->option('enrich') ? true : null);

        $this->info("Sample #{$result['sample_id']}: {$result['included']} included of {$result['considered']} considered in {$result['seconds']}s.");
        $this->table(['Decision', 'Count'], array_map(fn (string $d, int $n) => [$d, $n], array_keys($result['decisions']), array_values($result['decisions'])));
        $this->table(['Provider', 'Status', 'Retrieved', 'Reused'], array_map(fn (array $p) => [$p['provider'], $p['status'], $p['retrieved'] ?? 0, $p['reused'] ?? 0], $result['providers']));
        $this->line("Extraction: {$result['extraction']['extracted']} read, {$result['extraction']['cached']} from cache. Interview: {$result['questions']} questions.");

        if ($result['enrichment'] !== null) {
            $e = $result['enrichment'];
            $this->line("Enrichment: {$e['enriched']} phrased, {$e['rejected']} rejected, {$e['cached']} cached, ({$e['latency_ms']}ms, model ".($e['model'] ?? 'none').($e['failed'] ? ', call failed, kept deterministic' : '').'; usage '.json_encode($e['usage']).').');
        }

        return self::SUCCESS;
    }
}
