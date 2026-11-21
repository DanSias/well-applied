<?php

use App\Models\JobPosting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/PilotRunnerTest.php';

function pcTmp(): string
{
    return sys_get_temp_dir().'/plc_'.uniqid();
}

it('refuses live mode outright: no request, no database access, no output file', function () {
    Http::fake();
    $out = pcTmp();
    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    $this->artisan('jobs:freshness-pilot', ['--mode' => 'live', '--manifest' => 'x', '--manifest-hash' => 'y', '--out' => $out])->expectsOutputToContain('Live mode is unavailable')->assertFailed();

    Http::assertNothingSent();
    expect($queries)->toBe(0)->and(file_exists($out))->toBeFalse();
});

it('prints the plan with no request and no write', function () {
    $s = plSample();
    Http::fake();
    $writes = [];
    DB::listen(function ($q) use (&$writes) {
        if (! preg_match('/^\s*(select|pragma)/i', $q->sql)) {
            $writes[] = $q->sql;
        }
    });

    $this->artisan('jobs:freshness-pilot', ['--manifest' => $s['path'], '--manifest-hash' => $s['hash']])
        ->expectsOutputToContain('Plan (nothing is sent)')->expectsOutputToContain('9 planned requests, cap 10, at least 2 s apart, no retries, no pagination.')->assertSuccessful();

    Http::assertNothingSent();
    expect($writes)->toBe([]);
});

it('refuses to plan or dry-run with a wrong, missing or unapproved manifest hash', function (?string $hash) {
    $s = plSample();
    $args = ['--manifest' => $s['path']] + ($hash === null ? [] : ['--manifest-hash' => $hash]);

    $this->artisan('jobs:freshness-pilot', $args)->expectsOutputToContain('Refused')->assertFailed();
    $this->artisan('jobs:freshness-pilot', $args + ['--mode' => 'dry-run', '--out' => pcTmp()])->expectsOutputToContain('Refused')->assertFailed();
})->with([[null], [str_repeat('a', 64)]]);

it('dry-runs offline with synthetic answers, writes new evidence and observation files, and makes no request', function () {
    $s = plSample();
    Http::fake();
    $out = pcTmp();

    $this->artisan('jobs:freshness-pilot', ['--manifest' => $s['path'], '--manifest-hash' => $s['hash'], '--mode' => 'dry-run', '--out' => $out])->expectsOutputToContain('Dry run completed (9 offline answers, no request made)')->assertSuccessful();

    Http::assertNothingSent();
    $evidence = json_decode(file_get_contents($out), true);
    $obs = json_decode(file_get_contents($out.'.observations.json'), true);
    expect($evidence)->toMatchArray(['mode' => 'dry-run', 'status' => 'completed', 'requests_made' => 9, 'manifest_sha256' => $s['hash']])
        ->and(count($obs))->toBe(31)->and(file_get_contents($out))->not->toContain('://');
    // a second run refuses to overwrite either file
    $this->artisan('jobs:freshness-pilot', ['--manifest' => $s['path'], '--manifest-hash' => $s['hash'], '--mode' => 'dry-run', '--out' => $out])->assertFailed();
});

it('dry-runs recorded responses (a 403, a 429, a connection failure) and stops as the policy says', function (array $recorded, string $reason, int $made) {
    $s = plSample();
    $responses = pcTmp();
    file_put_contents($responses, json_encode($recorded));
    $out = pcTmp();

    $this->artisan('jobs:freshness-pilot', ['--manifest' => $s['path'], '--manifest-hash' => $s['hash'], '--mode' => 'dry-run', '--responses' => $responses, '--out' => $out])->assertSuccessful();

    expect(json_decode(file_get_contents($out), true))->toMatchArray(['status' => 'stopped', 'stopped_because' => $reason, 'requests_made' => $made]);
})->with([
    '403 on the first' => [[['http_status' => 403, 'body' => '{}']], 'forbidden', 1],
    '429 with Retry-After on the third' => [[[], [], ['http_status' => 429, 'headers' => ['content-type' => 'application/json', 'retry-after' => '60'], 'body' => '{}']], 'rate_limited', 3],
    'a connection failure on the second' => [[[], ['fail' => true]], 'connection_failure', 2],
    'two 404s' => [[['http_status' => 404], ['http_status' => 404]], 'two_consecutive_404', 2],
]);

it('produces observations that the read-only report replays offline, with no request and no write', function () {
    $s = plSample();
    $out = pcTmp();
    Http::fake();
    $this->artisan('jobs:freshness-pilot', ['--manifest' => $s['path'], '--manifest-hash' => $s['hash'], '--mode' => 'dry-run', '--out' => $out])->assertSuccessful();
    $before = DB::table('job_postings')->orderBy('id')->get()->toJson();

    $this->artisan('jobs:freshness-report', ['--observations' => $out.'.observations.json', '--json' => true])->assertSuccessful();

    Http::assertNothingSent();
    expect(DB::table('job_postings')->orderBy('id')->get()->toJson())->toBe($before)->and(JobPosting::count())->toBe(31);
});

it('writes the manifest only to a new file and refuses a missing job', function () {
    $s = plSample();
    $out = pcTmp();
    touch($out);

    $this->artisan('jobs:freshness-pilot-manifest', ['--out' => $out])->assertFailed();   // exists
    unlink($out);
    // the real sample ids do not exist in the test database: refused, and nothing is written
    $this->artisan('jobs:freshness-pilot-manifest', ['--out' => $out])->assertFailed();
    expect(file_exists($out))->toBeFalse()->and($s['path'])->toBeString();
});
