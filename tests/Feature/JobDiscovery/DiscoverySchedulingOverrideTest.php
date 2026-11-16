<?php

use App\Enums\DiscoveryStatus;
use App\Enums\JobDiscoverySource;
use App\Enums\ScheduleOverride;
use App\Models\CareerProfile;
use App\Models\DiscoveryProviderAttempt;
use App\Models\DiscoveryProviderSetting;
use App\Models\DiscoveryRun;
use App\Support\Home\PresentDiscoveryHealth;
use App\Support\JobDiscovery\DiscoveryProviderInventory;
use App\Support\JobDiscovery\DiscoveryScheduleIsDue;
use App\Support\Sources\PresentSources;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Sleep::fake();
    Http::preventStrayRequests();
    CareerProfile::factory()->create();
    config([
        'job_discovery.schedule.stale_after_hours' => 23,
        'job_discovery.schedule.retry_after_hours' => 6,
        'job_discovery.search.keywords' => ['software engineer'],
    ]);
});

function override(string $provider, ScheduleOverride $value): void
{
    DiscoveryProviderSetting::query()->updateOrCreate(['provider' => $provider], ['schedule_override' => $value]);
}

function effective(string $provider): string
{
    return DiscoveryProviderInventory::effectiveSchedule(JobDiscoverySource::from($provider));
}

/** USAJOBS configured and active, but manual only by default (its `scheduled` flag stays off). */
function usajobsConfigured(): void
{
    config(['services.usajobs.api_key' => 'k', 'services.usajobs.user_agent' => 'me@example.test', 'job_discovery.usajobs.scheduled' => false]);
}

function dueNow(): array
{
    return array_map(fn (JobDiscoverySource $s) => $s->value, (new DiscoveryScheduleIsDue)->dueSources());
}

function ranProviders(): array
{
    return DiscoveryProviderAttempt::query()->pluck('provider')->map(fn ($p) => $p->value)->sort()->values()->all();
}

it('resolves every override for an automatic-by-default provider', function (?ScheduleOverride $override, string $expected) {
    if ($override !== null) {
        override('himalayas', $override);
    }

    expect(effective('himalayas'))->toBe($expected);
})->with([
    'no row' => [null, 'automatic'],
    'default' => [ScheduleOverride::Default, 'automatic'],
    'paused' => [ScheduleOverride::Paused, 'paused'],
    'enabled' => [ScheduleOverride::Enabled, 'automatic'],
]);

it('resolves every override for a configured manual-only provider', function (?ScheduleOverride $override, string $expected) {
    usajobsConfigured();
    if ($override !== null) {
        override('usajobs', $override);
    }

    expect(effective('usajobs'))->toBe($expected);
})->with([
    'no row' => [null, 'manual_only'],
    'default' => [ScheduleOverride::Default, 'manual_only'],
    'paused' => [ScheduleOverride::Paused, 'paused'],
    'enabled' => [ScheduleOverride::Enabled, 'automatic'],
]);

it('never lets an override bypass configuration or registration', function (ScheduleOverride $override) {
    // Adzuna has no credentials, Eightfold is off and USAJOBS has no key: all three are unavailable.
    foreach (['adzuna', 'eightfold', 'usajobs'] as $provider) {
        override($provider, $override);
        expect(effective($provider))->toBe('unavailable');
    }

    expect(dueNow())->not->toContain('adzuna', 'eightfold', 'usajobs')
        ->and(DiscoveryScheduleIsDue::scheduledSources())->not->toContain(JobDiscoverySource::Adzuna);
})->with([ScheduleOverride::Default, ScheduleOverride::Paused, ScheduleOverride::Enabled]);

it('makes Adzuna schedulable only once both credentials exist', function () {
    expect(effective('adzuna'))->toBe('unavailable');

    config(['services.adzuna.app_id' => 'id']);
    expect(effective('adzuna'))->toBe('unavailable');

    config(['services.adzuna.app_key' => 'key']);
    expect(effective('adzuna'))->toBe('automatic');
});

it('keeps Eightfold and USAJOBS opt-in unless scheduling is configured or enabled', function () {
    usajobsConfigured();
    expect(effective('usajobs'))->toBe('manual_only')
        ->and(dueNow())->not->toContain('usajobs');

    config(['job_discovery.usajobs.scheduled' => true]);
    expect(effective('usajobs'))->toBe('automatic');

    config(['job_discovery.usajobs.scheduled' => false]);
    override('usajobs', ScheduleOverride::Enabled);
    expect(effective('usajobs'))->toBe('automatic')
        ->and(dueNow())->toContain('usajobs');
});

it('reads a stored override fresh each time, so it survives a restart', function () {
    override('jobicy', ScheduleOverride::Paused);

    // Nothing is cached in the process: a new inventory instance, as after a restart, sees the stored row.
    expect((new DiscoveryProviderInventory)->describe(JobDiscoverySource::Jobicy))
        ->toMatchArray(['schedule_override' => 'paused', 'effective_schedule' => 'paused']);
    expect(DiscoveryProviderSetting::query()->where('provider', 'jobicy')->count())->toBe(1);
});

it('keeps one row per provider and rejects a duplicate at the database', function () {
    override('jobicy', ScheduleOverride::Paused);
    override('jobicy', ScheduleOverride::Default);

    expect(DiscoveryProviderSetting::query()->count())->toBe(1);
    expect(fn () => DiscoveryProviderSetting::query()->create(['provider' => 'jobicy', 'schedule_override' => 'paused']))
        ->toThrow(QueryException::class);
});

it('offers the control that fits each effective state', function () {
    usajobsConfigured();
    $control = fn (string $p) => (new DiscoveryProviderInventory)->describe(JobDiscoverySource::from($p))['schedule_control'];

    expect($control('himalayas'))->toBe(['label' => 'Pause scheduling', 'override' => 'paused'])
        ->and($control('usajobs'))->toBe(['label' => 'Enable scheduling', 'override' => 'enabled'])
        ->and($control('adzuna'))->toBeNull();

    override('himalayas', ScheduleOverride::Paused);
    override('usajobs', ScheduleOverride::Enabled);

    // Resume restores the default for an automatic provider, and an explicit enable for an opt-in one.
    expect($control('himalayas'))->toBe(['label' => 'Resume scheduling', 'override' => 'default'])
        ->and($control('usajobs'))->toBe(['label' => 'Pause scheduling', 'override' => 'paused']);

    override('usajobs', ScheduleOverride::Paused);
    expect($control('usajobs'))->toBe(['label' => 'Resume scheduling', 'override' => 'enabled']);
});

it('leaves a paused provider out of the due list, but not its neighbours', function () {
    override('himalayas', ScheduleOverride::Paused);

    expect(dueNow())->toBe(['jobicy']);
});

it('retries a failed provider on the retry cadence once it is resumed', function () {
    $run = DiscoveryRun::factory()->create(['status' => DiscoveryStatus::Failed, 'started_at' => now()->subHours(7), 'finished_at' => now()->subHours(7)]);
    DiscoveryProviderAttempt::factory()->create([
        'discovery_run_id' => $run->id, 'provider' => JobDiscoverySource::Himalayas, 'status' => DiscoveryStatus::Failed,
        'started_at' => now()->subHours(7), 'finished_at' => now()->subHours(7),
    ]);
    DiscoveryProviderAttempt::factory()->create([
        'discovery_run_id' => $run->id, 'provider' => JobDiscoverySource::Jobicy, 'status' => DiscoveryStatus::Succeeded,
        'started_at' => now()->subHours(7), 'finished_at' => now()->subHours(7),
    ]);

    override('himalayas', ScheduleOverride::Paused);
    expect(dueNow())->toBe([]);

    override('himalayas', ScheduleOverride::Default);
    expect(dueNow())->toBe(['himalayas']);
});

it('runs only due, unpaused providers with --due, and respects overrides with --only', function () {
    Http::fake(['*' => Http::response(null, 500)]);
    override('himalayas', ScheduleOverride::Paused);

    $this->artisan('discovery:run', ['--due' => true])->run();
    // Jobicy alone was due; the paused Himalayas and the unconfigured Adzuna were not run.
    expect(ranProviders())->toBe(['jobicy']);

    DiscoveryProviderAttempt::query()->delete();
    DiscoveryRun::query()->delete();

    $this->artisan('discovery:run', ['--due' => true, '--only' => ['himalayas']])
        ->expectsOutputToContain('No discovery providers are due.')
        ->assertSuccessful();
    expect(DiscoveryRun::query()->count())->toBe(0);
});

it('lets a manual run ignore the override entirely', function () {
    Http::fake(['*' => Http::response(null, 500)]);
    override('himalayas', ScheduleOverride::Paused);

    $this->artisan('discovery:run', ['--only' => ['himalayas']])->run();

    expect(ranProviders())->toBe(['himalayas']);
});

it('does not run an unconfigured provider just because it is enabled', function () {
    Http::fake(['*' => Http::response(null, 500)]);
    override('usajobs', ScheduleOverride::Enabled);

    $this->artisan('discovery:run', ['--due' => true, '--only' => ['usajobs']])
        ->expectsOutputToContain('No discovery providers are due.')
        ->assertSuccessful();

    expect(DiscoveryRun::query()->count())->toBe(0);
});

it('never calls a paused provider overdue, and keeps its failure history visible', function () {
    $run = DiscoveryRun::factory()->create(['status' => DiscoveryStatus::Failed, 'started_at' => now()->subDays(5), 'finished_at' => now()->subDays(5)]);
    $attempt = DiscoveryProviderAttempt::factory()->create([
        'discovery_run_id' => $run->id, 'provider' => JobDiscoverySource::Himalayas, 'status' => DiscoveryStatus::Failed,
        'failure_category' => 'provider_error', 'failure_message' => 'boom',
        'started_at' => now()->subDays(5), 'finished_at' => now()->subDays(5),
    ]);
    $before = $attempt->fresh()->toArray();
    $overdue = fn () => collect((new PresentSources)->providers())->firstWhere('provider', 'himalayas');

    // Only ever failed: not overdue (it has no success to be overdue from), though Overview's stale flag is set.
    expect($overdue()['overdue'])->toBeFalse()->and($overdue()['stale'])->toBeTrue();

    DiscoveryProviderAttempt::factory()->create([
        'discovery_run_id' => $run->id, 'provider' => JobDiscoverySource::Himalayas, 'status' => DiscoveryStatus::Succeeded,
        'started_at' => now()->subDays(4), 'finished_at' => now()->subDays(4),
    ]);
    DiscoveryProviderAttempt::factory()->create([
        'discovery_run_id' => $run->id, 'provider' => JobDiscoverySource::Himalayas, 'status' => DiscoveryStatus::Failed,
        'failure_message' => 'later boom', 'started_at' => now()->subDays(3), 'finished_at' => now()->subDays(3),
    ]);

    expect($overdue())->toMatchArray(['overdue' => true, 'stale' => true]);

    override('himalayas', ScheduleOverride::Paused);

    $paused = $overdue();
    expect($paused)->toMatchArray(['effective_schedule' => 'paused', 'overdue' => false, 'stale' => false, 'never_run' => false])
        // The failure is still the latest attempt and still needs attention: scheduling is not health.
        ->and($paused['last_attempt']['status'])->toBe('failed')
        ->and($paused['last_failure']['failure']['message'])->toBe('later boom')
        ->and($paused['needs_attention'])->toBeTrue()
        ->and($attempt->fresh()->toArray())->toBe($before);
});

it('keeps a never-run provider visible as needing its first run, and a paused one out of that', function () {
    expect(collect((new PresentSources)->providers())->firstWhere('provider', 'himalayas')['never_run'])->toBeTrue();

    override('himalayas', ScheduleOverride::Paused);

    expect(collect((new PresentSources)->providers())->firstWhere('provider', 'himalayas')['never_run'])->toBeFalse()
        ->and((new PresentSources)->summary()['paused'])->toBe(1);
});

it('gives Overview and Sources the same schedule and health for every provider', function () {
    usajobsConfigured();
    override('himalayas', ScheduleOverride::Paused);
    override('usajobs', ScheduleOverride::Enabled);
    foreach ([JobDiscoverySource::Himalayas, JobDiscoverySource::Usajobs, JobDiscoverySource::Jobicy] as $i => $source) {
        DiscoveryProviderAttempt::factory()->create([
            'discovery_run_id' => DiscoveryRun::factory()->create(['started_at' => now()->subDays(3)])->id, 'provider' => $source,
            'status' => DiscoveryStatus::Succeeded, 'started_at' => now()->subDays(3), 'finished_at' => now()->subDays(3),
        ]);
    }
    DiscoveryProviderAttempt::factory()->create([
        'discovery_run_id' => DiscoveryRun::factory()->create(['started_at' => now()->subDays(3)])->id, 'provider' => JobDiscoverySource::Adzuna,
        'status' => DiscoveryStatus::Failed, 'started_at' => now()->subDays(3), 'finished_at' => now()->subDays(3),
    ]);

    $sources = collect((new PresentSources)->providers())->keyBy('provider');
    $overview = collect((new PresentDiscoveryHealth)->present()['providers'])->keyBy('provider');

    expect($overview->keys()->all())->toContain('himalayas', 'jobicy', 'usajobs', 'adzuna');
    foreach ($overview as $provider => $health) {
        expect($health['schedule'])->toBe($sources[$provider]['effective_schedule'])
            ->and($health['stale'])->toBe($sources[$provider]['stale'])
            ->and($health['scheduled'])->toBe($sources[$provider]['effective_schedule'] === 'automatic');
    }

    // Paused and unavailable providers are never "overdue" for that reason; the scheduled ones with an old success are.
    expect($overview['himalayas']['stale'])->toBeFalse()
        ->and($overview['adzuna']['schedule'])->toBe('unavailable')
        ->and($overview['adzuna']['stale'])->toBeFalse()
        ->and($overview['jobicy']['stale'])->toBeTrue();
});
