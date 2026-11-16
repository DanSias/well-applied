<?php

use App\Enums\DiscoveryStatus;
use App\Enums\JobDiscoverySource;
use App\Models\CareerProfile;
use App\Models\DiscoveryProviderAttempt;
use App\Support\JobDiscovery\Canonical\GreenhouseCanonicalAdapter;
use App\Support\JobDiscovery\ExecutionHeartbeat;
use App\Support\JobDiscovery\LeaseLostException;
use App\Support\JobDiscovery\ProviderExecutionLeases;
use App\Support\JobDiscovery\Providers\AdzunaDiscoveryProvider;
use App\Support\JobDiscovery\Providers\Eightfold\EightfoldClient;
use App\Support\JobDiscovery\Providers\Eightfold\EightfoldTenant;
use App\Support\JobDiscovery\Providers\HimalayasDiscoveryProvider;
use App\Support\JobDiscovery\Providers\JobicyDiscoveryProvider;
use App\Support\JobDiscovery\Providers\UsajobsDiscoveryProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/*
| Every provider talks through the Http facade, so the heartbeat's one global request middleware must run before each
| request it sends, including every retry. These tests go through each provider's real client path (no stubbed transport
| beyond Http::fake) and compare the number of ownership checkpoints with the number of requests actually sent.
*/

beforeEach(function () {
    Sleep::fake();
    CareerProfile::factory()->create();
    config([
        'services.adzuna.app_id' => 'id', 'services.adzuna.app_key' => 'key', 'services.adzuna.country' => 'us',
        'services.usajobs.api_key' => 'k', 'services.usajobs.user_agent' => 'me@example.test',
        'job_discovery.eightfold.enabled' => true,
    ]);
});

/** Runs `$work` as the current execution and returns [requests sent, checkpoints passed]. */
function transportCounts(Closure $work): array
{
    $heartbeat = app(ExecutionHeartbeat::class);
    $lease = app(ProviderExecutionLeases::class)->acquire(JobDiscoverySource::Himalayas);
    $attempt = DiscoveryProviderAttempt::factory()->create(['status' => DiscoveryStatus::Running, 'finished_at' => null]);
    $before = $heartbeat->beats();
    $heartbeat->start($lease, $attempt);

    try {
        $work();
    } finally {
        $heartbeat->stop();
        $lease->release();
    }

    return [Http::recorded()->count(), $heartbeat->beats() - $before];
}

it('checks ownership before every request each public-feed provider sends, retries included', function (string $pattern, Closure $ok, Closure $call) {
    Http::fake([$pattern => Http::sequence()->push('server error', 500)->push('server error', 500)->push($ok())->whenEmpty(Http::response($ok()))]);

    [$requests, $checkpoints] = transportCounts($call);

    expect($requests)->toBeGreaterThan(1)->and($checkpoints)->toBe($requests);
})->with([
    'himalayas' => ['himalayas.app/*', fn () => ['jobs' => []], fn () => (new HimalayasDiscoveryProvider)->retrieve()],
    'adzuna' => ['api.adzuna.com/*', fn () => ['results' => []], fn () => (new AdzunaDiscoveryProvider)->retrieve()],
    'jobicy' => ['jobicy.com/*', fn () => ['jobs' => []], fn () => (new JobicyDiscoveryProvider)->retrieve()],
    'usajobs' => ['data.usajobs.gov/*', fn () => ['SearchResult' => ['SearchResultCountAll' => 0, 'SearchResultItems' => []]], fn () => (new UsajobsDiscoveryProvider)->retrieve()],
]);

it('checks ownership before every Eightfold search and detail request, retries included', function () {
    $tenant = EightfoldTenant::enabled()[0];
    Http::fake(['*' => Http::sequence()
        ->push('busy', 503)                                  // search, retried
        ->push(['data' => ['positions' => [], 'count' => 0]]) // search ok
        ->push('busy', 503)                                  // detail, retried
        ->push(['data' => ['id' => 1, 'jobDescription' => '<p>x</p>']]), // detail ok
    ]);

    [$requests, $checkpoints] = transportCounts(function () use ($tenant) {
        $client = new EightfoldClient($tenant);
        $client->search('engineer', 0);
        $client->details(1);
    });

    expect($requests)->toBe(4)->and($checkpoints)->toBe(4);
});

it('checks ownership before a canonical board request too', function () {
    Http::fake(['*' => Http::response(['jobs' => []])]);

    [$requests, $checkpoints] = transportCounts(fn () => (new GreenhouseCanonicalAdapter)->retrieveBoard('acme-board'));

    expect($requests)->toBe(1)->and($checkpoints)->toBe(1);
});

it('does nothing outside an execution, and an error from the checkpoint stops the request', function () {
    Http::fake(['*' => Http::response(['jobs' => []])]);
    $heartbeat = app(ExecutionHeartbeat::class);
    $before = $heartbeat->beats();

    Http::get('https://himalayas.app/jobs/api');
    expect($heartbeat->beats())->toBe($before);

    // A lost lease makes the middleware throw, so the request is not sent.
    $lease = app(ProviderExecutionLeases::class)->acquire(JobDiscoverySource::Himalayas);
    $attempt = DiscoveryProviderAttempt::factory()->create(['status' => DiscoveryStatus::Running, 'finished_at' => null]);
    $heartbeat->start($lease, $attempt);
    Cache::lock(app(ProviderExecutionLeases::class)->key(JobDiscoverySource::Himalayas))->forceRelease();
    $sent = Http::recorded()->count();

    expect(fn () => Http::get('https://himalayas.app/jobs/api'))->toThrow(LeaseLostException::class)
        ->and(Http::recorded()->count())->toBe($sent);

    $heartbeat->stop();
});
