<?php

use App\Enums\DiscoveryStatus;
use App\Enums\JobDiscoverySource;
use App\Models\CareerProfile;
use App\Models\DiscoveryProviderAttempt;
use App\Models\DiscoveryRun;
use App\Models\JobPosting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Sleep::fake();
    CareerProfile::factory()->create();
    config([
        'services.adzuna.app_id' => 'test-id',
        'services.adzuna.app_key' => 'test-key',
        'services.adzuna.country' => 'us',
        'job_discovery.search.keywords' => ['software engineer'],
        'job_discovery.search.excluded_keywords' => [],
        'job_discovery.search.remote_preference' => 'any',
        'job_discovery.search.allowed_locations' => [],
        'job_discovery.search.employment_type' => null,
    ]);
});

it('runs the discovery pipeline and reports a summary', function () {
    Http::fake([
        'himalayas.app/*' => Http::response(['jobs' => [[
            'title' => 'Software Engineer',
            'companyName' => 'Acme',
            'description' => 'Build things.',
            'applicationLink' => 'https://himalayas.app/jobs/1',
            'guid' => 'https://himalayas.app/jobs/1',
        ]]]),
        'api.adzuna.com/*' => Http::response(['results' => []]),
    ]);

    $this->artisan('discovery:run')
        ->expectsOutputToContain('Discovery run')
        ->assertSuccessful();

    expect(JobPosting::count())->toBe(1);
});

it('exits successfully even when a provider fails, since the run still completes', function () {
    Http::fake([
        'himalayas.app/*' => Http::response(null, 500),
        'api.adzuna.com/*' => Http::response(['results' => []]),
    ]);

    $this->artisan('discovery:run')->assertSuccessful();
});

it('returns failure when both providers fail and persists total failure', function () {
    Http::fake(['*' => Http::response(null, 500)]);
    $this->artisan('discovery:run')->assertFailed();
    expect(DiscoveryRun::first()->status)->toBe(DiscoveryStatus::Failed);
});

it('manual execution runs every provider regardless of recent attempts', function () {
    foreach (['himalayas', 'adzuna', 'jobicy'] as $provider) {
        DiscoveryProviderAttempt::factory()->create(['provider' => $provider, 'status' => DiscoveryStatus::Succeeded]);
    }
    Http::fake(['*' => Http::response(['jobs' => [], 'results' => []])]);

    $this->artisan('discovery:run')->assertSuccessful();

    expect(DiscoveryRun::latest('id')->first()->providerAttempts()->count())->toBe(3);
});

it('--due does nothing and makes no requests when no provider is due', function () {
    foreach (['himalayas', 'adzuna', 'jobicy'] as $provider) {
        DiscoveryProviderAttempt::factory()->create(['provider' => $provider, 'status' => DiscoveryStatus::Succeeded]);
    }
    $runsBefore = DiscoveryRun::count();
    Http::fake();

    $this->artisan('discovery:run --due')
        ->expectsOutputToContain('No discovery providers are due')
        ->assertSuccessful();

    Http::assertNothingSent();
    expect(DiscoveryRun::count())->toBe($runsBefore);
});

it('--due runs only the failed provider that is past its retry window', function () {
    $recent = now()->subHour();
    foreach (['himalayas', 'jobicy'] as $provider) {
        DiscoveryProviderAttempt::factory()->create(['provider' => $provider, 'status' => DiscoveryStatus::Succeeded, 'started_at' => $recent, 'finished_at' => $recent]);
    }
    DiscoveryProviderAttempt::factory()->create(['provider' => 'adzuna', 'status' => DiscoveryStatus::Failed, 'started_at' => now()->subHours(7), 'finished_at' => now()->subHours(7)]);
    Http::fake(['api.adzuna.com/*' => Http::response(['results' => []])]);

    $this->artisan('discovery:run --due')->assertSuccessful();

    $attempts = DiscoveryRun::latest('id')->first()->providerAttempts;
    expect($attempts)->toHaveCount(1)
        ->and($attempts->first()->provider)->toBe(JobDiscoverySource::Adzuna)
        ->and($attempts->first()->status)->toBe(DiscoveryStatus::Succeeded);
    Http::assertSentCount(1);
});

it('a transient failure is retried by the next due scheduled run and recovers', function () {
    $adzunaDown = true;
    Http::fake([
        'himalayas.app/*' => Http::response(['jobs' => []]),
        'jobicy.com/*' => Http::response(['jobs' => []]),
        'api.adzuna.com/*' => function () use (&$adzunaDown) {
            return $adzunaDown ? Http::response(null, 500) : Http::response(['results' => []]);
        },
    ]);

    $this->artisan('discovery:run --due');
    expect(DiscoveryRun::latest('id')->first()->status)->toBe(DiscoveryStatus::Partial);

    // Hourly ticks inside the retry window do nothing.
    $this->travel(2)->hours();
    $this->artisan('discovery:run --due')->expectsOutputToContain('No discovery providers are due');

    $this->travel(5)->hours();
    $adzunaDown = false;
    $this->artisan('discovery:run --due')->assertSuccessful();

    $latest = DiscoveryRun::latest('id')->first();
    expect($latest->status)->toBe(DiscoveryStatus::Succeeded)
        ->and($latest->providerAttempts->pluck('provider')->all())->toBe([JobDiscoverySource::Adzuna]);

    $this->artisan('discovery:run --due')->expectsOutputToContain('No discovery providers are due');
});
