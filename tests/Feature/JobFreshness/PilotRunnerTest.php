<?php

use App\Console\Commands\JobsFreshnessPilotManifest;
use App\Enums\JobDiscoverySource;
use App\Models\AgentRun;
use App\Models\Application;
use App\Models\JobPosting;
use App\Support\JobFreshness\Pilot\HttpPilotTransport;
use App\Support\JobFreshness\Pilot\PilotManifest;
use App\Support\JobFreshness\Pilot\PilotRefused;
use App\Support\JobFreshness\Pilot\PilotResponse;
use App\Support\JobFreshness\Pilot\PilotRunner;
use App\Support\JobFreshness\Pilot\PilotTransport;
use App\Support\JobFreshness\Pilot\PilotTransportException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/SurfacesTest.php';

/** @return array{path: string, hash: string, himalayas: list<JobPosting>, jobicy: list<JobPosting>} the approved sample, built from rows in the test database */
function plSample(): array
{
    $himalayas = [];
    foreach (range(1, 8) as $n) {
        $himalayas[] = frJob(['title' => "Secret Title {$n}", 'discovery_source_id' => "g-{$n}", 'source_url' => "https://himalayas.app/companies/acme/jobs/engineer-{$n}"], now()->addMonth()->timestamp);
    }
    $jobicy = [];
    foreach (range(1, 23) as $n) {
        $jobicy[] = JobPosting::factory()->create(['career_profile_id' => $himalayas[0]->career_profile_id, 'discovery_source' => JobDiscoverySource::Jobicy, 'discovery_source_id' => (string) (9000 + $n), 'source_url' => 'https://jobicy.com/jobs/'.(9000 + $n).'-x']);
    }
    $manifest = PilotManifest::build(array_map(fn ($j) => $j->id, $himalayas), array_map(fn ($j) => $j->id, $jobicy), '2026-11-24T00:00:00+00:00');
    $path = tempnam(sys_get_temp_dir(), 'plm');
    file_put_contents($path, $manifest->json());
    config(['job_freshness.pilot.approved_manifest_sha256' => hash('sha256', $manifest->json())]);

    return ['path' => $path, 'hash' => hash('sha256', $manifest->json()), 'himalayas' => $himalayas, 'jobicy' => $jobicy];
}

function plJobicyBody(array $jobs, string $status = 'active'): array
{
    return ['apiVersion' => '2.2.22', 'checkedAt' => '2026-11-24 10:00:00', 'count' => count($jobs), 'jobs' => array_map(fn ($j) => ['id' => (int) $j->discovery_source_id, 'status' => $status], $jobs), 'statusCode' => 200, 'success' => true];
}

function plRun(array $s, ?PilotTransport $transport = null, ?array &$sleeps = null): array
{
    config(['job_freshness.pilot.live_enabled' => true]);
    $sleeps = [];
    $runner = new PilotRunner($transport ?? new HttpPilotTransport, function (int $s) use (&$sleeps) {
        $sleeps[] = $s;
    });

    return $runner->run(PilotManifest::fromFile($s['path']), 'test');
}

function plAllOk(array $s): void
{
    Http::fake([
        'himalayas.app/*' => Http::response(['expiryDate' => now()->addMonth()->timestamp, 'title' => 'Secret Title', 'description' => 'SECRET DESCRIPTION', 'applicationLink' => 'https://himalayas.app/x?token=SECRET'], 200, ['Content-Type' => 'application/json', 'Cache-Control' => 'max-age=600']),
        'jobicy.com/*' => Http::response(plJobicyBody($s['jobicy']), 200, ['Content-Type' => 'application/json']),
    ]);
}

beforeEach(function () {
    Http::preventStrayRequests();
});

// ---- the shape of a run ----------------------------------------------------------------------------------------------------------------------

it('plans exactly 9 requests under a cap of 10: 8 Himalayas single-job checks and one Jobicy status batch of 23', function () {
    $s = plSample();
    plAllOk($s);

    $r = plRun($s, null, $sleeps);

    expect($r['evidence'])->toMatchArray(['status' => 'completed', 'stopped_because' => null, 'planned_requests' => 9, 'requests_made' => 9, 'request_cap' => 10, 'min_gap_seconds' => 2])
        ->and(PilotManifest::PLANNED_REQUESTS)->toBeLessThan(PilotManifest::REQUEST_CAP)
        ->and(PilotManifest::HIMALAYAS_JOBS + 1)->toBe(PilotManifest::PLANNED_REQUESTS);
    Http::assertSentCount(9);
    expect(array_count_values(array_column($r['evidence']['requests'], 'host')))->toBe(['himalayas.app' => 8, 'jobicy.com' => 1])
        ->and(count($r['evidence']['requests'][8]['local_job_ids']))->toBe(23)
        ->and(count($r['observations']))->toBe(31);
});

it('waits at least 2 seconds before every request after the first, and never retries or paginates', function () {
    $s = plSample();
    plAllOk($s);

    plRun($s, null, $sleeps);

    expect($sleeps)->toBe(array_fill(0, 8, 2));
    $urls = collect(Http::recorded())->map(fn ($pair) => (string) $pair[0]->url());
    expect($urls->unique()->count())->toBe(9)->and($urls->filter(fn ($u) => str_contains($u, 'cursor') || str_contains($u, 'offset') || str_contains($u, 'page=')))->toBeEmpty()
        ->and($urls->last())->toStartWith('https://jobicy.com/api/jobs/status?ids=9001,9002,');
});

it('interprets a successful run: provider-confirmed active, with evidence that carries no URL, title, description or secret', function () {
    $s = plSample();
    plAllOk($s);

    $r = plRun($s);
    $json = json_encode($r);

    expect(collect($r['observations'])->flatMap(fn ($o) => $o['observations'])->pluck('result')->unique()->all())->toBe(['active'])
        ->and($json)->not->toContain('://')->not->toContain('Secret Title')->not->toContain('SECRET')->not->toContain('token')->not->toContain('himalayas.app/companies')
        ->and($r['evidence']['requests'][0]['headers'])->toBe(['content-type' => 'application/json', 'cache-control' => 'max-age=600'])
        ->and($r['evidence']['requests'][0]['body_keys'])->toBe(['expiryDate', 'title', 'description', 'applicationLink'])
        ->and($r['evidence']['requests'][0]['check']['body'])->toBe(['expiryDate' => $r['evidence']['requests'][0]['check']['body']['expiryDate']]);
});

it('reads Jobicy statuses: closed is closed, unknown and an absent id are uncertain, an unrecognised value is dropped', function () {
    $s = plSample();
    $j = $s['jobicy'];
    $body = ['jobs' => [['id' => (int) $j[0]->discovery_source_id, 'status' => 'closed'], ['id' => (int) $j[1]->discovery_source_id, 'status' => 'unknown'], ['id' => (int) $j[2]->discovery_source_id, 'status' => 'weird'], ['id' => 1, 'status' => 'active']], 'success' => true];
    Http::fake(['himalayas.app/*' => Http::response(['expiryDate' => null], 200, ['Content-Type' => 'application/json']), 'jobicy.com/*' => Http::response($body, 200, ['Content-Type' => 'application/json'])]);

    $r = plRun($s);
    $byJob = collect($r['observations'])->keyBy('job_id');

    expect($byJob[$j[0]->id]['observations'][0])->toMatchArray(['result' => 'closed', 'basis' => 'status_closed'])
        ->and($byJob[$j[1]->id]['observations'][0])->toMatchArray(['result' => 'uncertain', 'basis' => 'status_unknown'])
        ->and($byJob[$j[2]->id]['observations'][0])->toMatchArray(['result' => 'uncertain', 'basis' => 'not_in_response'])
        ->and($byJob[$j[3]->id]['observations'][0])->toMatchArray(['result' => 'uncertain', 'basis' => 'not_in_response'])
        ->and(json_encode($r['evidence']['requests'][8]['check']))->not->toContain('weird');
});

// ---- uncertainty and the stop conditions ------------------------------------------------------------------------------------------------------

it('records one 404 as uncertain and carries on', function () {
    $s = plSample();
    Http::fake([
        'himalayas.app/*' => Http::sequence()->push(['x' => 1], 404, ['Content-Type' => 'application/json'])->push(['expiryDate' => null], 200, ['Content-Type' => 'application/json'])->whenEmpty(Http::response(['expiryDate' => null], 200, ['Content-Type' => 'application/json'])),
        'jobicy.com/*' => Http::response(plJobicyBody($s['jobicy']), 200, ['Content-Type' => 'application/json']),
    ]);

    $r = plRun($s);

    expect($r['evidence'])->toMatchArray(['status' => 'completed', 'requests_made' => 9])
        ->and($r['observations'][0]['observations'][0])->toMatchArray(['result' => 'uncertain', 'basis' => 'http_404', 'http_status' => 404]);
});

it('stops after two consecutive Himalayas 404s and never reaches Jobicy', function () {
    $s = plSample();
    Http::fake(['himalayas.app/*' => Http::response(['x' => 1], 404, ['Content-Type' => 'application/json'])]);

    $r = plRun($s);

    expect($r['evidence'])->toMatchArray(['status' => 'stopped', 'stopped_because' => 'two_consecutive_404', 'requests_made' => 2]);
    Http::assertSentCount(2);
});

it('stops at once on a problem answer, records it, and does not retry', function (int $status, array $headers, string $body, string $reason) {
    $s = plSample();
    Http::fake(['*' => Http::response($body, $status, $headers)]);

    $r = plRun($s, null, $sleeps);

    expect($r['evidence'])->toMatchArray(['status' => 'stopped', 'stopped_because' => $reason, 'requests_made' => 1])->and($sleeps)->toBe([]);
    Http::assertSentCount(1);
})->with([
    '403' => [403, ['Content-Type' => 'application/json'], '{}', 'forbidden'],
    '429' => [429, ['Content-Type' => 'application/json', 'Retry-After' => '30'], '{}', 'rate_limited'],
    '500' => [500, ['Content-Type' => 'application/json'], '{}', 'server_error'],
    '503' => [503, ['Content-Type' => 'application/json'], '{}', 'server_error'],
    'redirect 302' => [302, ['Location' => 'https://elsewhere.example/x', 'Content-Type' => 'application/json'], '{}', 'redirect'],
    'html page' => [200, ['Content-Type' => 'text/html'], '<html></html>', 'non_json_content_type'],
    'malformed json' => [200, ['Content-Type' => 'application/json'], '{"expiryDate": ', 'malformed_json'],
    'too large' => [200, ['Content-Type' => 'application/json'], '{"a":"'.str_repeat('x', 262144).'"}', 'body_too_large'],
]);

it('respects Retry-After by stopping: it is recorded, clamped, and nothing is sent afterwards', function () {
    $s = plSample();
    Http::fake(['*' => Http::response('{}', 429, ['Content-Type' => 'application/json', 'Retry-After' => '99999'])]);

    $r = plRun($s);

    expect($r['evidence']['requests'][0]['headers']['retry-after'])->toBe('99999')
        ->and($r['observations'][0]['observations'][0])->toMatchArray(['result' => 'uncertain', 'basis' => 'rate_limited', 'retry_after_seconds' => 3600]);
    Http::assertSentCount(1);
});

it('does not follow a redirect', function () {
    $s = plSample();
    Http::fake(['himalayas.app/*' => Http::response('', 301, ['Location' => 'https://himalayas.app/elsewhere']), 'himalayas.app/elsewhere' => Http::response('{}', 200)]);

    $r = plRun($s);

    expect($r['evidence']['stopped_because'])->toBe('redirect');
    Http::assertSentCount(1);
});

it('treats a connection failure as an interruption of the run, without a retry', function () {
    $s = plSample();
    Http::fake(['himalayas.app/*' => Http::sequence()->push(['expiryDate' => null], 200, ['Content-Type' => 'application/json'])->pushFailedConnection()]);

    $r = plRun($s);

    expect($r['evidence'])->toMatchArray(['status' => 'stopped', 'stopped_because' => 'connection_failure', 'requests_made' => 2])
        ->and($r['observations'][1]['observations'][0])->toMatchArray(['result' => 'uncertain', 'basis' => 'network_error']);
    Http::assertSentCount(2);
});

it('keeps the evidence gathered so far when the run is interrupted', function () {
    $s = plSample();
    $calls = 0;
    $transport = new class($calls) implements PilotTransport
    {
        public function __construct(private int &$calls) {}

        public function get(string $url, int $timeoutSeconds): PilotResponse
        {
            if (++$this->calls === 4) {
                throw new RuntimeException('SIGINT');
            }

            return new PilotResponse(200, ['content-type' => 'application/json'], '{"expiryDate":null}');
        }
    };

    $r = plRun($s, $transport);

    expect($r['evidence'])->toMatchArray(['status' => 'interrupted', 'stopped_because' => 'interrupted', 'interrupted_by' => 'RuntimeException', 'requests_made' => 4])
        ->and(count($r['evidence']['requests']))->toBe(3)->and(count($r['observations']))->toBe(3);
});

it('never sends more than the cap whatever the plan', function () {
    $s = plSample();
    plAllOk($s);

    plRun($s);

    expect(count(Http::recorded()))->toBeLessThanOrEqual(PilotManifest::REQUEST_CAP);
});

// ---- the approved manifest -------------------------------------------------------------------------------------------------------------------

it('refuses, before any request, a manifest that is not the approved one', function (Closure $tamper, string $reason) {
    $s = plSample();
    Http::fake();
    $tamper($s);

    expect(fn () => plRun($s))->toThrow(PilotRefused::class);
    try {
        plRun($s);
    } catch (PilotRefused $e) {
        expect($e->reason)->toBe($reason);
    }
    Http::assertNothingSent();
})->with([
    'an id swapped for another job' => [function (array $s) {
        $d = json_decode(file_get_contents($s['path']), true);
        $d['himalayas'][0]['job_id'] = $s['himalayas'][1]->id;
        file_put_contents($s['path'], json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }, 'manifest_shape'],
    'a limit loosened' => [function (array $s) {
        $d = json_decode(file_get_contents($s['path']), true);
        $d['limits']['min_gap_seconds'] = 1;
        file_put_contents($s['path'], json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }, 'manifest_altered'],
    'a host added' => [function (array $s) {
        $d = json_decode(file_get_contents($s['path']), true);
        $d['hosts'][] = 'evil.example';
        file_put_contents($s['path'], json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }, 'manifest_altered'],
    'a job removed' => [function (array $s) {
        $d = json_decode(file_get_contents($s['path']), true);
        array_pop($d['jobicy']);
        file_put_contents($s['path'], json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }, 'manifest_shape'],
    'a harmless whitespace edit (different bytes, different hash)' => [function (array $s) {
        file_put_contents($s['path'], file_get_contents($s['path'])."\n");
    }, 'manifest_not_approved'],
    'an unreadable file' => [function (array $s) {
        file_put_contents($s['path'], '{nope');
    }, 'manifest_unreadable'],
]);

it('requires the presented hash to be the approved one', function () {
    $s = plSample();
    $m = PilotManifest::fromFile($s['path']);

    $m->assertApproved($s['hash']);
    foreach ([null, '', str_repeat('0', 64), strtoupper(substr($s['hash'], 0, 10))] as $bad) {
        expect(fn () => $m->assertApproved($bad))->toThrow(PilotRefused::class);
    }
    config(['job_freshness.pilot.approved_manifest_sha256' => null]);
    expect(fn () => $m->assertApproved($s['hash']))->toThrow(PilotRefused::class);
});

it('re-reads the source records and refuses to run if any identity changed', function (Closure $change) {
    $s = plSample();
    Http::fake();
    $change($s);

    expect(fn () => plRun($s))->toThrow(PilotRefused::class, 'source record');
    Http::assertNothingSent();
})->with([
    'a Himalayas job now points at another posting' => [fn (array $s) => $s['himalayas'][2]->update(['source_url' => 'https://himalayas.app/companies/acme/jobs/another-posting'])],
    'a Himalayas job changed company slug' => [function (array $s) {
        $m = $s['himalayas'][3]->discovery_metadata;
        $m['discovery']['companySlug'] = 'other';
        $s['himalayas'][3]->update(['discovery_metadata' => $m, 'source_url' => 'https://himalayas.app/companies/other/jobs/engineer-4']);
    }],
    'a Himalayas job changed source id' => [fn (array $s) => $s['himalayas'][4]->update(['discovery_source_id' => 'changed'])],
    'a Jobicy job changed source id' => [fn (array $s) => $s['jobicy'][10]->update(['discovery_source_id' => '12345'])],
    'a Jobicy job now belongs to another provider' => [fn (array $s) => $s['jobicy'][0]->update(['discovery_source' => JobDiscoverySource::Adzuna])],
    'a job was deleted' => [fn (array $s) => $s['jobicy'][22]->delete()],
]);

it('refuses a planned request that is not https on a manifest host', function () {
    $s = plSample();
    Http::fake();
    config(['services.himalayas.base_url' => 'https://elsewhere.example/jobs/api']);

    expect(fn () => plRun($s))->toThrow(PilotRefused::class, 'manifest host');
    Http::assertNothingSent();
});

it('ships a committed manifest that is the approved sample, with a matching approved hash', function () {
    $path = base_path('docs/pilot/job-freshness-pilot-manifest.v1.json');
    $data = json_decode(file_get_contents($path), true);

    expect(hash('sha256', file_get_contents($path)))->toBe(config('job_freshness.pilot.approved_manifest_sha256'))
        ->and(array_column($data['himalayas'], 'job_id'))->toBe(JobsFreshnessPilotManifest::HIMALAYAS)
        ->and(array_column($data['jobicy'], 'job_id'))->toBe(JobsFreshnessPilotManifest::JOBICY)
        ->and(count($data['himalayas']))->toBe(8)->and(count($data['jobicy']))->toBe(23)
        ->and($data['limits'])->toMatchArray(['planned_requests' => 9, 'request_cap' => 10, 'min_gap_seconds' => 2, 'retries' => 0, 'pagination' => false])
        ->and($data['hosts'])->toBe(['himalayas.app', 'jobicy.com'])
        ->and(file_get_contents($path))->not->toContain('http')->not->toContain('title');
});

// ---- offline-only, read-only -----------------------------------------------------------------------------------------------------------------

it('writes nothing to the database and creates no application, agent run or status change', function () {
    $s = plSample();
    plAllOk($s);
    Application::factory()->create(['job_posting_id' => $s['jobicy'][0]->id]);
    $before = [DB::table('job_postings')->orderBy('id')->get()->toJson(), Application::count(), AgentRun::count()];
    $writes = [];
    DB::listen(function ($q) use (&$writes) {
        if (! preg_match('/^\s*(select|pragma)/i', $q->sql)) {
            $writes[] = $q->sql;
        }
    });

    plRun($s);

    expect($writes)->toBe([])->and([DB::table('job_postings')->orderBy('id')->get()->toJson(), Application::count(), AgentRun::count()])->toBe($before);
});

it('does not even build a live HTTP request unless live is enabled, which only a code change can do', function () {
    $s = plSample();
    Http::fake();

    expect(config('job_freshness.pilot.live_enabled'))->toBeFalse();
    expect(fn () => (new PilotRunner(new HttpPilotTransport))->run(PilotManifest::fromFile($s['path']), 'x'))->not->toThrow(ConnectionException::class);
    Http::assertNothingSent();
    expect(file_get_contents(base_path('config/job_freshness.php')))->toContain("'live_enabled' => false,")->not->toMatch("/live_enabled'\s*=>\s*env/");
});

it('has no transport exception escaping as a request: a refusing transport records an interruption', function () {
    $s = plSample();
    $t = new class implements PilotTransport
    {
        public function get(string $url, int $timeoutSeconds): PilotResponse
        {
            throw new PilotTransportException('down');
        }
    };

    $r = plRun($s, $t);

    expect($r['evidence']['stopped_because'])->toBe('connection_failure')->and($r['evidence']['requests_made'])->toBe(1);
});
