<?php

use App\Support\JobDiscovery\JobicyCommercial\JobicyApiClient;
use App\Support\JobDiscovery\JobicyCommercial\JobicyApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

require_once __DIR__.'/JobicyFixtures.php';

beforeEach(function () {
    config(['services.jobicy_commercial.base_url' => 'https://jobicy.test/api/jobs', 'services.jobicy_commercial.api_key' => JBC_KEY]);
});

function jbcCapture(?array $response = null, int $status = 200, array $headers = []): ArrayObject
{
    $sent = new ArrayObject;
    Http::fake(function (Request $request) use ($sent, $response, $status, $headers) {
        $sent[] = $request;

        return Http::response($response ?? jbcEnvelope([jbcPublicJob(1)]), $status, $headers);
    });

    return $sent;
}

it('sends the documented request: GET the one endpoint with count, filters and cursor, and the key only on a commercial request', function () {
    $sent = jbcCapture();
    $client = JobicyApiClient::fromConfig();

    $client->page('public', 25, ['geo' => 'usa']);
    $client->page('commercial', 25, ['geo' => 'usa'], 'abc');

    expect($sent)->toHaveCount(2);
    foreach ($sent as $request) {
        expect($request->method())->toBe('GET')->and($request->url())->toStartWith('https://jobicy.test/api/jobs?');
    }
    expect($sent[0]->hasHeader('Authorization'))->toBeFalse('the free tier sends no credential at all')
        ->and($sent[1]->header('Authorization'))->toBe(['Bearer '.JBC_KEY])
        ->and($sent[1]->url())->toContain('count=25')->toContain('geo=usa')->toContain('cursor=abc')
        ->and($sent[0]->url())->not->toContain('cursor');
});

it('takes the key from configuration only, and refuses a commercial request without one before any request is made', function () {
    config(['services.jobicy_commercial.api_key' => '   ']);
    Http::fake(['*' => Http::response(jbcEnvelope([]))]);
    $client = JobicyApiClient::fromConfig();

    expect($client->hasKey())->toBeFalse();
    expect(fn () => $client->page('commercial', 10))->toThrow(JobicyApiException::class);
    Http::assertNothingSent();
    // ...while the free tier still works without a key.
    expect($client->page('public', 10)['jobs'])->toBe([]);
    Http::assertSentCount(1);
});

it('rejects an invalid tier or count without a request', function () {
    Http::fake();
    $client = JobicyApiClient::fromConfig();
    foreach ([['bogus', 10], ['public', 0], ['public', 101]] as [$tier, $count]) {
        expect(fn () => $client->page($tier, $count))->toThrow(JobicyApiException::class);
    }
    Http::assertNothingSent();
});

it('maps each documented and assumed failure to a reason, with its status', function (int $status, string $reason) {
    jbcCapture(['error' => 'whatever the body says'], $status);

    try {
        JobicyApiClient::fromConfig()->page('commercial', 10);
        $this->fail('expected an exception');
    } catch (JobicyApiException $e) {
        expect([$e->reason, $e->status])->toBe([$reason, $status]);
    }
})->with([[400, 'bad_request'], [401, 'auth_failed'], [403, 'auth_failed'], [402, 'insufficient_balance'], [404, 'client_error'], [429, 'rate_limited'], [503, 'service_unavailable'], [500, 'server_error'], [502, 'server_error']]);

it('reads Retry-After as seconds or as an HTTP date, clamps it, and never waits or retries', function () {
    jbcCapture(['error' => 'slow down'], 429, ['Retry-After' => '7']);
    $caught = null;
    try {
        JobicyApiClient::fromConfig()->page('commercial', 10);
    } catch (JobicyApiException $e) {
        $caught = $e;
    }
    expect($caught->retryAfterSeconds)->toBe(7);
    Http::assertSentCount(1);   // one attempt, no retry

    expect(JobicyApiClient::retryAfter('999999'))->toBe(3600)
        ->and(JobicyApiClient::retryAfter('0'))->toBe(0)
        ->and(JobicyApiClient::retryAfter(null))->toBeNull()
        ->and(JobicyApiClient::retryAfter(''))->toBeNull()
        ->and(JobicyApiClient::retryAfter('soon'))->toBeNull()
        ->and(JobicyApiClient::retryAfter(now()->addSeconds(90)->toRfc7231String()))->toBeBetween(88, 91)
        ->and(JobicyApiClient::retryAfter(now()->subHour()->toRfc7231String()))->toBe(0);
});

it('treats an error inside a 200 envelope as the failure it says it is', function (int $inner, string $reason) {
    jbcCapture(['success' => false, 'statusCode' => $inner, 'error' => 'x'], 200);
    expect(fn () => JobicyApiClient::fromConfig()->page('commercial', 10))->toThrow(fn (JobicyApiException $e) => expect($e->reason)->toBe($reason));
})->with([[401, 'auth_failed'], [402, 'insufficient_balance'], [429, 'rate_limited'], [400, 'bad_request'], [418, 'bad_request']]);

it('rejects a response that is not the documented shape', function (mixed $body) {
    Http::fake(['*' => Http::response($body, 200)]);
    expect(fn () => JobicyApiClient::fromConfig()->page('public', 10))->toThrow(fn (JobicyApiException $e) => expect($e->reason)->toBe('malformed_response'));
})->with(['<html>maintenance</html>', [['not' => 'jobs']], [['jobs' => 'nope']], '']);

it('reports a connection failure without the underlying exception', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out while using Bearer '.JBC_KEY));

    try {
        JobicyApiClient::fromConfig()->page('commercial', 10);
        $this->fail('expected an exception');
    } catch (JobicyApiException $e) {
        expect($e->reason)->toBe('connection_failed')->and($e->getPrevious())->toBeNull()->and($e->getMessage())->not->toContain(JBC_KEY);
    }
});

it('never lets the key into a message, a log line or an exception trace, whatever the server says', function () {
    Log::spy();
    foreach ([400, 401, 402, 429, 500, 503] as $status) {
        jbcCapture(['error' => 'Bearer '.JBC_KEY.' is invalid', 'detail' => JBC_KEY], $status, ['Retry-After' => '1']);
        try {
            JobicyApiClient::fromConfig()->page('commercial', 10);
        } catch (JobicyApiException $e) {
            expect($e->getMessage().$e->getTraceAsString())->not->toContain(JBC_KEY)->and(serialize([$e->reason, $e->status, $e->retryAfterSeconds]))->not->toContain(JBC_KEY);
        }
    }
    Log::shouldNotHaveReceived('warning');
    Log::shouldNotHaveReceived('error');
    Log::shouldNotHaveReceived('info');
});

it('is isolated from the discovery provider: separate config, separate class, and the provider still uses its own endpoint', function () {
    expect(config('services.jobicy.base_url'))->toBe('https://jobicy.com/api/v2/remote-jobs')
        ->and(config('services.jobicy_commercial.enabled'))->toBeFalse('off unless explicitly enabled');

    $provider = file_get_contents(base_path('app/Support/JobDiscovery/Providers/JobicyDiscoveryProvider.php'));
    expect($provider)->not->toContain('JobicyCommercial')->and($provider)->not->toContain('jobicy_commercial');
    foreach (['app/Support/JobDiscovery/IngestDiscoveredCandidate.php', 'app/Support/JobDiscovery/RunJobDiscovery.php', 'bootstrap/app.php', 'routes/console.php'] as $file) {
        expect(file_get_contents(base_path($file)))->not->toContain('JobicyCommercial')->and(file_get_contents(base_path($file)))->not->toContain('jobicy:commercial');
    }
});
