<?php

use App\Enums\JobDiscoverySource;
use App\Enums\JobDisposition;
use App\Models\Application;
use App\Models\JobPosting;
use App\Support\JobFreshness\Observation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/SurfacesTest.php';

function frReport(array $args = []): array
{
    Artisan::call('jobs:freshness-report', ['--json' => true] + $args);

    return json_decode(Artisan::output(), true);
}

it('reports how many jobs would be active, expired, closed, uncertain or unchecked, with evidence and intended action, and changes nothing', function () {
    Http::fake();
    $queries = [];
    DB::listen(function ($q) use (&$queries) {
        $queries[] = $q->sql;
    });

    frJob(['disposition' => JobDisposition::New], now()->subDay()->timestamp);                       // expired, would be set closed
    frJob(['disposition' => JobDisposition::New], now()->addWeek()->timestamp);                      // unchecked: a future expiry is not evidence of anything
    $pursued = frJob(['disposition' => JobDisposition::Pursued], now()->subDay()->timestamp);        // expired but protected: review
    frJob(['discovery_source' => JobDiscoverySource::Jobicy, 'discovery_source_id' => '555', 'source_url' => 'https://jobicy.com/jobs/555-x'], null);
    frJob(['discovery_source' => JobDiscoverySource::Adzuna, 'discovery_source_id' => 'a1', 'source_url' => 'https://www.adzuna.com/land/ad/1'], null);
    $before = DB::table('job_postings')->orderBy('id')->get()->toJson();
    $queries = [];

    $r = frReport();

    expect($r['read_only'])->toBeTrue()->and($r['requests_made'])->toBe(0)->and($r['feature_enabled'])->toBeFalse()->and($r['totals']['jobs'])->toBe(5)
        ->and($r['sources']['himalayas'])->toMatchArray(['expired' => 2, 'unchecked' => 1, 'provider_check_exists' => true])
        ->and($r['sources']['jobicy'])->toMatchArray(['unchecked' => 1, 'provider_check_exists' => true])
        ->and($r['sources']['adzuna'])->toMatchArray(['unchecked' => 1, 'provider_check_exists' => false])
        ->and($r['intended_actions'])->toMatchArray(['set_closed' => 1, 'review' => 1, 'protected_jobs' => 1, 'none' => 3])
        ->and($r['would_need_checks'])->toBe(['himalayas_requests' => 1, 'jobicy_batches' => 1])
        ->and(collect($r['examples']['expired'])->pluck('job_id')->all())->toContain($pursued->id);
    expect(collect($r['examples']['expired'])->firstWhere('job_id', $pursued->id))->toMatchArray(['action' => 'review', 'protected' => true, 'reason' => 'expired_known']);
    expect(collect($r['examples']['expired'])->first()['evidence'][0])->toBe(['source' => 'expiry_date', 'result' => 'expired', 'basis' => 'expiry_date_passed']);

    Http::assertNothingSent();
    expect(collect($queries)->filter(fn ($q) => ! preg_match('/^select/i', $q))->all())->toBe([])->and(DB::table('job_postings')->orderBy('id')->get()->toJson())->toBe($before);
});

it('replays recorded observations from a local file, and confirms a closure only by the policy', function () {
    $job = frJob(['disposition' => JobDisposition::New], null);
    $obs = fn (int $hours) => (new Observation('himalayas', 'job_endpoint', 'closed', 'http_410', CarbonImmutable::now()->subHours($hours)))->toArray();
    $file = sys_get_temp_dir().'/fr-obs-'.uniqid().'.json';
    file_put_contents($file, json_encode([['job_id' => $job->id, 'observations' => [$obs(30), $obs(1)]]]));

    $r = frReport(['--observations' => $file]);
    unlink($file);

    expect($r['sources']['himalayas'])->toMatchArray(['closed' => 1])->and($r['intended_actions'])->toMatchArray(['set_closed' => 1])->and($r['requests_made'])->toBe(0);
});

it('counts a conflicting observation and a single closure as uncertain, not closed', function () {
    $a = frJob(['disposition' => JobDisposition::New], now()->subDay()->timestamp);
    $b = frJob(['disposition' => JobDisposition::New], null);
    $obs = fn (string $result, string $basis, int $h) => (new Observation('himalayas', 'job_endpoint', $result, $basis, CarbonImmutable::now()->subHours($h)))->toArray();
    $file = sys_get_temp_dir().'/fr-obs-'.uniqid().'.json';
    file_put_contents($file, json_encode([['job_id' => $a->id, 'observations' => [$obs('active', 'http_200', 0)]], ['job_id' => $b->id, 'observations' => [$obs('closed', 'http_410', 1)]]]));

    $r = frReport(['--observations' => $file]);
    unlink($file);

    expect($r['sources']['himalayas'])->toMatchArray(['expired' => 1, 'uncertain' => 1])->and($r['intended_actions']['conflicting_observations'])->toBe(1);
});

it('refuses an unreadable observations file without reading any job', function () {
    $this->artisan('jobs:freshness-report', ['--observations' => '/nonexistent/file.json'])->expectsOutputToContain('could not be read')->assertExitCode(2);
    $file = sys_get_temp_dir().'/fr-bad-'.uniqid().'.json';
    file_put_contents($file, '{"not":"a list of rows"}');
    $this->artisan('jobs:freshness-report', ['--observations' => $file])->assertExitCode(2);
    unlink($file);
});

it('prints a readable summary by default, and the feature flag does not change what it reads', function () {
    frJob([], now()->subDay()->timestamp);

    $this->artisan('jobs:freshness-report')->expectsOutputToContain('dry run: read-only')->expectsOutputToContain('himalayas')->assertExitCode(0);
    config(['job_freshness.enabled' => true]);
    expect(frReport()['sources']['himalayas']['expired'])->toBe(1);
});

it('does not count an Application-bearing job as changed', function () {
    $job = frJob(['disposition' => JobDisposition::Shortlisted], now()->subDay()->timestamp);
    Application::factory()->create(['job_posting_id' => $job->id]);

    expect(frReport()['intended_actions'])->toMatchArray(['review' => 1, 'protected_jobs' => 1])->not->toHaveKey('set_closed');
    expect(JobPosting::find($job->id)->status->value)->toBe('open');
});
