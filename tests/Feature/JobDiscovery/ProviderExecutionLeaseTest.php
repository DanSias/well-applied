<?php

use App\Enums\DiscoveryStatus;
use App\Enums\JobDiscoverySource;
use App\Models\CareerProfile;
use App\Models\DiscoveryProviderAttempt;
use App\Models\DiscoveryRun;
use App\Support\JobDiscovery\ExecutionHeartbeat;
use App\Support\JobDiscovery\ProviderExecutionLeases;
use App\Support\JobDiscovery\RunJobDiscovery;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Sleep::fake();
    CareerProfile::factory()->create();
    config([
        'services.adzuna.app_id' => 'id', 'services.adzuna.app_key' => 'key', 'services.adzuna.country' => 'us',
        'job_discovery.execution.lease_seconds' => 900,
        'job_discovery.search.keywords' => ['software engineer'],
    ]);
    Http::preventStrayRequests();
});

function fakeFeeds(): void
{
    Http::fake([
        'himalayas.app/*' => Http::response(['jobs' => []]),
        'api.adzuna.com/*' => Http::response(['results' => []]),
        'jobicy.com/*' => Http::response(['jobs' => []]),
    ]);
}

function leases(): ProviderExecutionLeases
{
    return app(ProviderExecutionLeases::class);
}

it('refuses a second lease on the same provider and lets another provider through', function () {
    $first = leases()->acquire(JobDiscoverySource::Himalayas);

    expect($first)->not->toBeNull()
        ->and(leases()->acquire(JobDiscoverySource::Himalayas))->toBeNull()
        ->and(leases()->acquire(JobDiscoverySource::Jobicy))->not->toBeNull()
        ->and(leases()->isHeld(JobDiscoverySource::Himalayas))->toBeTrue();

    $first->release();

    expect(leases()->isHeld(JobDiscoverySource::Himalayas))->toBeFalse()
        ->and(leases()->acquire(JobDiscoverySource::Himalayas))->not->toBeNull();
});

it('lets an expired lease be taken over, and never releases the new owner\'s lease', function () {
    $old = leases()->acquire(JobDiscoverySource::Himalayas);

    $this->travel(901)->seconds();
    $new = leases()->acquire(JobDiscoverySource::Himalayas);

    expect($new)->not->toBeNull()
        ->and($old->renew())->toBeFalse();

    $old->release();

    expect(leases()->isHeld(JobDiscoverySource::Himalayas))->toBeTrue();
    $new->release();
    expect(leases()->isHeld(JobDiscoverySource::Himalayas))->toBeFalse();
});

it('renews a lease so it outlives its own lifetime while the holder is working', function () {
    $lease = leases()->acquire(JobDiscoverySource::Himalayas);

    $this->travel(800)->seconds();
    expect($lease->renew())->toBeTrue();

    $this->travel(800)->seconds();
    expect(leases()->isHeld(JobDiscoverySource::Himalayas))->toBeTrue()
        ->and(leases()->acquire(JobDiscoverySource::Himalayas))->toBeNull();
});

it('releases by owner token only', function () {
    leases()->acquire(JobDiscoverySource::Jobicy, 'queued-attempt-7');

    leases()->releaseIfOwned(JobDiscoverySource::Jobicy, 'queued-attempt-8');
    expect(leases()->isHeld(JobDiscoverySource::Jobicy))->toBeTrue();

    leases()->releaseIfOwned(JobDiscoverySource::Jobicy, 'queued-attempt-7');
    expect(leases()->isHeld(JobDiscoverySource::Jobicy))->toBeFalse();
});

it('holds the provider\'s lease for the whole attempt and frees it afterwards', function () {
    $heldDuringRun = null;
    Http::fake([
        'himalayas.app/*' => function () use (&$heldDuringRun) {
            $heldDuringRun = [leases()->isHeld(JobDiscoverySource::Himalayas), leases()->isHeld(JobDiscoverySource::Jobicy)];

            return Http::response(['jobs' => []]);
        },
        '*' => Http::response(['jobs' => [], 'results' => []]),
    ]);

    (new RunJobDiscovery)->run([JobDiscoverySource::Himalayas]);

    expect($heldDuringRun)->toBe([true, false])
        ->and(leases()->isHeld(JobDiscoverySource::Himalayas))->toBeFalse();
});

it('frees the lease even when the attempt fails', function () {
    Http::fake(['*' => Http::response(null, 500)]);

    (new RunJobDiscovery)->run([JobDiscoverySource::Himalayas]);

    expect(DiscoveryProviderAttempt::query()->first()->status)->toBe(DiscoveryStatus::Failed)
        ->and(leases()->isHeld(JobDiscoverySource::Himalayas))->toBeFalse();
});

it('skips a busy provider without recording an attempt, and still runs the others', function () {
    fakeFeeds();
    leases()->acquire(JobDiscoverySource::Himalayas);

    $execution = (new RunJobDiscovery)->execute();

    expect($execution->skipped)->toBe([JobDiscoverySource::Himalayas])
        ->and(DiscoveryProviderAttempt::query()->pluck('provider')->map(fn ($p) => $p->value)->sort()->values()->all())->toBe(['adzuna', 'jobicy'])
        ->and($execution->run->status)->toBe(DiscoveryStatus::Succeeded);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'himalayas.app'));
});

it('records no run at all when every requested provider is busy', function () {
    fakeFeeds();
    leases()->acquire(JobDiscoverySource::Himalayas);

    $execution = (new RunJobDiscovery)->execute([JobDiscoverySource::Himalayas]);

    expect($execution->skipped)->toBe([JobDiscoverySource::Himalayas])
        ->and($execution->recorded())->toBeFalse()
        ->and(DiscoveryRun::query()->count())->toBe(0)
        ->and(DiscoveryProviderAttempt::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('still records an empty successful run when nothing was busy and nothing matched, as before', function () {
    $execution = (new RunJobDiscovery)->execute([]);

    expect($execution->skipped)->toBe([])
        ->and($execution->recorded())->toBeTrue()
        ->and($execution->run->status)->toBe(DiscoveryStatus::Succeeded);
});

it('keeps the lease and the attempt fresh on every outgoing request', function () {
    Http::fake(['*' => Http::response([])]);
    $lease = leases()->acquire(JobDiscoverySource::Himalayas);
    $attempt = DiscoveryProviderAttempt::factory()->create(['status' => DiscoveryStatus::Running, 'finished_at' => null, 'updated_at' => now()->subMinutes(10)]);

    $heartbeat = app(ExecutionHeartbeat::class);
    $heartbeat->start($lease, $attempt);

    $this->travel(600)->seconds();
    Http::get('https://example.test/anything');

    expect($attempt->fresh()->updated_at->gt(now()->subSeconds(5)))->toBeTrue()
        ->and(leases()->isHeld(JobDiscoverySource::Himalayas))->toBeTrue();

    // 600s of silence would have left a 900s lease with 300s; the renewal means a further 800s is still covered.
    $this->travel(800)->seconds();
    expect(leases()->isHeld(JobDiscoverySource::Himalayas))->toBeTrue();

    $heartbeat->stop();
    $lease->release();
});

it('tells the CLI user a busy provider was skipped, and exits non-zero for a manual run that did nothing', function () {
    fakeFeeds();
    leases()->acquire(JobDiscoverySource::Himalayas);

    $this->artisan('discovery:run', ['--only' => ['himalayas']])
        ->expectsOutputToContain('Skipped, already running elsewhere: himalayas.')
        ->expectsOutputToContain('Nothing was run')
        ->assertFailed();

    expect(DiscoveryRun::query()->count())->toBe(0);
});

it('lets a scheduled tick find everything busy without failing the scheduler', function () {
    fakeFeeds();
    leases()->acquire(JobDiscoverySource::Himalayas);

    $this->artisan('discovery:run', ['--due' => true, '--only' => ['himalayas']])->assertSuccessful();

    expect(DiscoveryRun::query()->count())->toBe(0);
});

it('runs the other providers from the CLI and reports the skipped one', function () {
    fakeFeeds();
    leases()->acquire(JobDiscoverySource::Himalayas);

    $this->artisan('discovery:run')
        ->expectsOutputToContain('Skipped, already running elsewhere: himalayas.')
        ->assertSuccessful();

    expect(DiscoveryProviderAttempt::query()->count())->toBe(2);
});
