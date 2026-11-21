<?php

namespace App\Console\Commands;

use App\Support\JobDiscovery\JobicyCommercial\JobicyApiClient;
use App\Support\JobDiscovery\JobicyCommercial\SampleRun;
use App\Support\JobDiscovery\JobicyCommercial\SpendBudget;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * A SUPERVISED, PAID, one-off sample of Jobicy's Commercial Jobs API against its free tier. It is not part of discovery, is never scheduled, and writes no
 * database row: only a sanitised report file. It refuses to run unless EVERY gate is met, and makes at most two requests (one free, one paid).
 *
 * Gates: JOBICY_COMMERCIAL_ENABLED is true; the key is in configuration (never an argument, so it is not in shell history); --confirm-spend;
 * an explicit --max-cost-cents within the hard ceiling that also covers the worst case (count x 1 cent); a new --out file.
 */
#[Signature('jobicy:commercial-sample
    {--count=100 : Jobs requested per page (1 to 100). The worst case of the paid page is count cents}
    {--max-cost-cents= : Required. The most you allow this run to cost, in cents (hard ceiling 200)}
    {--confirm-spend : Required. Acknowledges that the paid request charges the Jobicy wallet}
    {--out= : Required. A NEW file for the sanitised report}')]
#[Description('Supervised, paid sample comparing Jobicy free and Commercial API responses by job id. Writes a report file only.')]
class JobicyCommercialSample extends Command
{
    public function handle(): int
    {
        if (! (bool) config('services.jobicy_commercial.enabled')) {
            return $this->refuse('JOBICY_COMMERCIAL_ENABLED is not true.');
        }
        $client = JobicyApiClient::fromConfig();
        if (! $client->hasKey()) {
            return $this->refuse('No Jobicy Commercial key is configured (JOBICY_COMMERCIAL_API_KEY).');
        }
        if (! $this->option('confirm-spend')) {
            return $this->refuse('This run charges the Jobicy wallet: pass --confirm-spend to acknowledge it.');
        }
        $count = (int) $this->option('count');
        $cents = $this->option('max-cost-cents');
        if ($count < 1 || $count > 100) {
            return $this->refuse('--count must be 1 to 100.');
        }
        if (! is_string($cents) || ! ctype_digit($cents) || (int) $cents < $count || (int) $cents > SpendBudget::MAX_COST_CENTS_CEILING) {
            return $this->refuse('--max-cost-cents is required, must cover the worst case ('.$count.' cents) and may not exceed '.SpendBudget::MAX_COST_CENTS_CEILING.'.');
        }
        $out = $this->option('out');
        if (! is_string($out) || $out === '' || file_exists($out)) {
            return $this->refuse('--out must name a file that does not exist yet.');
        }

        $report = (new SampleRun($client))->run(new SpendBudget((int) $cents, 2), $count);

        file_put_contents($out, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
        $this->line('status: '.$report['run']['status'].' | requests: '.$report['run']['requests_made'].' | reported cost: '.$report['run']['reported_cents'].' cents | report: '.$out);

        return $report['run']['status'] === 'completed' ? self::SUCCESS : self::FAILURE;
    }

    private function refuse(string $why): int
    {
        $this->error('Refused: '.$why);

        return self::INVALID;
    }
}
