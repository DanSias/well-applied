<?php

use App\Enums\JobDiscoverySource;
use App\Support\JobDiscovery\DiscoveryProviderInventory;
use App\Support\JobDiscovery\DiscoveryScheduleIsDue;
use App\Support\JobDiscovery\RunJobDiscovery;

function inventoryRow(string $provider): array
{
    return collect((new DiscoveryProviderInventory)->all())->firstWhere('provider', $provider);
}

function registeredProviders(): array
{
    $property = new ReflectionProperty(RunJobDiscovery::class, 'providers');

    return array_keys($property->getValue(new RunJobDiscovery));
}

it('lists exactly the five discovery providers, without Manual or the canonical adapters', function () {
    $providers = array_column((new DiscoveryProviderInventory)->all(), 'provider');

    expect($providers)->toBe(['himalayas', 'adzuna', 'jobicy', 'eightfold', 'usajobs'])
        ->and($providers)->not->toContain('manual', 'greenhouse', 'lever', 'ashby');
});

it('reports the public feeds as registered, configured and automatic', function (string $provider) {
    expect(inventoryRow($provider))->toMatchArray([
        'registered' => true, 'configured' => true, 'unavailable_reason' => null,
        'default_schedule' => 'automatic', 'effective_schedule' => 'automatic', 'manual_run_available' => true,
    ]);
})->with(['himalayas', 'jobicy']);

it('reports Adzuna as registered but unconfigured without credentials, and says why', function () {
    config(['services.adzuna.app_id' => null, 'services.adzuna.app_key' => '']);

    expect(inventoryRow('adzuna'))->toMatchArray([
        'registered' => true, 'configured' => false, 'unavailable_reason' => 'Adzuna credentials are not configured.',
        // Existing behaviour, deliberately unchanged: it is still scheduled.
        'default_schedule' => 'automatic', 'effective_schedule' => 'automatic', 'manual_run_available' => false,
    ]);
});

it('needs both Adzuna credentials to count it configured', function () {
    config(['services.adzuna.app_id' => 'id', 'services.adzuna.app_key' => '  ']);
    expect(inventoryRow('adzuna')['configured'])->toBeFalse();

    config(['services.adzuna.app_key' => 'key']);
    expect(inventoryRow('adzuna'))->toMatchArray(['configured' => true, 'unavailable_reason' => null, 'manual_run_available' => true]);
});

it('registers Eightfold only when enabled with at least one enabled tenant', function () {
    config(['job_discovery.eightfold.enabled' => false]);
    expect(inventoryRow('eightfold'))->toMatchArray([
        'registered' => false, 'configured' => false, 'unavailable_reason' => 'Eightfold discovery is switched off.',
        'effective_schedule' => 'unavailable', 'manual_run_available' => false,
    ]);

    config(['job_discovery.eightfold.enabled' => true, 'job_discovery.eightfold.tenants' => ['x' => ['enabled' => false]]]);
    expect(inventoryRow('eightfold'))->toMatchArray(['registered' => false, 'unavailable_reason' => 'No Eightfold tenants are enabled.']);

    config(['job_discovery.eightfold.tenants' => ['x' => ['enabled' => true]]]);
    expect(inventoryRow('eightfold'))->toMatchArray([
        'registered' => true, 'configured' => true, 'unavailable_reason' => null,
        'default_schedule' => 'manual_only', 'effective_schedule' => 'manual_only', 'manual_run_available' => true,
    ]);
});

it('treats Eightfold and USAJOBS as opt-in for scheduling', function () {
    config([
        'job_discovery.eightfold.enabled' => true, 'job_discovery.eightfold.tenants' => ['x' => ['enabled' => true]],
        'services.usajobs.api_key' => 'k', 'services.usajobs.user_agent' => 'me@example.test',
    ]);
    expect(inventoryRow('eightfold')['effective_schedule'])->toBe('manual_only')
        ->and(inventoryRow('usajobs')['effective_schedule'])->toBe('manual_only');

    config(['job_discovery.eightfold.scheduled' => true, 'job_discovery.usajobs.scheduled' => true]);
    expect(inventoryRow('eightfold'))->toMatchArray(['default_schedule' => 'automatic', 'effective_schedule' => 'automatic'])
        ->and(inventoryRow('usajobs'))->toMatchArray(['default_schedule' => 'automatic', 'effective_schedule' => 'automatic']);
});

it('does not schedule an opted-in provider that is not active', function () {
    config(['job_discovery.usajobs.scheduled' => true, 'services.usajobs.api_key' => 'k', 'services.usajobs.user_agent' => '']);

    expect(inventoryRow('usajobs'))->toMatchArray([
        'registered' => false, 'configured' => false, 'unavailable_reason' => 'USAJOBS credentials are not configured.',
        'effective_schedule' => 'unavailable', 'manual_run_available' => false,
    ]);
});

it('requires both USAJOBS credentials', function () {
    config(['services.usajobs.api_key' => '', 'services.usajobs.user_agent' => 'me@example.test']);
    expect(inventoryRow('usajobs')['registered'])->toBeFalse();

    config(['services.usajobs.api_key' => 'k', 'services.usajobs.user_agent' => 'me@example.test']);
    expect(inventoryRow('usajobs'))->toMatchArray(['registered' => true, 'configured' => true, 'manual_run_available' => true]);
});

it('never exposes a credential or the registered email address', function () {
    config([
        'services.adzuna.app_id' => 'ADZ-ID-SECRET', 'services.adzuna.app_key' => 'ADZ-KEY-SECRET',
        'services.usajobs.api_key' => 'USA-KEY-SECRET', 'services.usajobs.user_agent' => 'private.person@example.test',
    ]);

    $json = json_encode((new DiscoveryProviderInventory)->all(), JSON_THROW_ON_ERROR);

    expect($json)->not->toContain('SECRET')->not->toContain('private.person');
});

it('agrees with RunJobDiscovery about which providers are registered', function (array $config) {
    config($config);

    $registered = array_values(array_filter(
        DiscoveryProviderInventory::sources(),
        fn (JobDiscoverySource $s) => DiscoveryProviderInventory::isRegistered($s),
    ));

    expect(registeredProviders())->toBe(array_map(fn ($s) => $s->value, $registered));
})->with([
    'nothing optional' => [[]],
    'eightfold' => [['job_discovery.eightfold.enabled' => true, 'job_discovery.eightfold.tenants' => ['x' => ['enabled' => true]]]],
    'usajobs' => [['services.usajobs.api_key' => 'k', 'services.usajobs.user_agent' => 'u']],
]);

it('agrees with DiscoveryScheduleIsDue about which providers are scheduled', function () {
    config([
        'job_discovery.eightfold.enabled' => true, 'job_discovery.eightfold.tenants' => ['x' => ['enabled' => true]],
        'job_discovery.eightfold.scheduled' => true,
    ]);

    $automatic = collect((new DiscoveryProviderInventory)->all())->where('effective_schedule', 'automatic')->pluck('provider')->all();

    expect($automatic)->toBe(array_map(fn ($s) => $s->value, DiscoveryScheduleIsDue::scheduledSources()));
});
