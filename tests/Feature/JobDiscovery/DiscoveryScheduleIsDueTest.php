<?php

use App\Enums\DiscoveryStatus;
use App\Enums\JobDiscoverySource;
use App\Models\DiscoveryProviderAttempt;
use App\Models\DiscoveryRun;
use App\Support\JobDiscovery\DiscoveryScheduleIsDue;
use App\Support\JobDiscovery\RunJobDiscovery;
use Carbon\CarbonImmutable;

beforeEach(function () {
    config([
        'job_discovery.schedule.stale_after_hours' => 23,
        'job_discovery.schedule.retry_after_hours' => 6,
        // Adzuna is scheduled only with credentials.
        'services.adzuna.app_id' => 'id',
        'services.adzuna.app_key' => 'key',
    ]);
});

afterEach(fn () => CarbonImmutable::setTestNow());

/**
 * Records the latest attempt for every scheduled provider, `$hoursAgo`
 * hours old, with the given per-provider outcomes (default Succeeded).
 *
 * @param  array<string, DiscoveryStatus>  $statuses  keyed by JobDiscoverySource value
 */
function recordAttempts(int $hoursAgo, array $statuses = [], ?DiscoveryStatus $runStatus = null): DiscoveryRun
{
    $at = now()->subHours($hoursAgo);
    $run = DiscoveryRun::factory()->create([
        'status' => $runStatus ?? DiscoveryStatus::Succeeded,
        'started_at' => $at,
        'finished_at' => $at,
    ]);

    foreach (DiscoveryScheduleIsDue::scheduledSources() as $source) {
        DiscoveryProviderAttempt::factory()->create([
            'discovery_run_id' => $run->id,
            'provider' => $source,
            'status' => $statuses[$source->value] ?? DiscoveryStatus::Succeeded,
            'started_at' => $at,
            'finished_at' => $at,
        ]);
    }

    return $run;
}

function dueValues(): array
{
    return array_map(fn (JobDiscoverySource $s) => $s->value, (new DiscoveryScheduleIsDue)->dueSources());
}

it('schedules exactly the providers RunJobDiscovery registers', function () {
    $registered = (new ReflectionProperty(RunJobDiscovery::class, 'providers'))
        ->getValue(new RunJobDiscovery);

    expect(array_map(fn (JobDiscoverySource $s) => $s->value, DiscoveryScheduleIsDue::scheduledSources()))
        ->toEqualCanonicalizing(array_keys($registered));
});

it('is due, for every provider, when nothing has ever run', function () {
    expect((new DiscoveryScheduleIsDue)())->toBeTrue()
        ->and(dueValues())->toEqualCanonicalizing(['himalayas', 'adzuna', 'jobicy']);
});

it('is not due when every provider succeeded recently', function () {
    recordAttempts(1);

    expect((new DiscoveryScheduleIsDue)())->toBeFalse()
        ->and(dueValues())->toBe([]);
});

it('is due again once successful attempts exceed the configured staleness threshold', function () {
    recordAttempts(24);

    expect((new DiscoveryScheduleIsDue)())->toBeTrue()
        ->and(dueValues())->toEqualCanonicalizing(['himalayas', 'adzuna', 'jobicy']);
});

it('is not due exactly at the configured threshold boundary', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-01 12:00:00'));
    recordAttempts(23);

    expect((new DiscoveryScheduleIsDue)())->toBeFalse();
});

it('respects a reconfigured staleness threshold', function () {
    config(['job_discovery.schedule.stale_after_hours' => 1]);
    recordAttempts(2);

    expect((new DiscoveryScheduleIsDue)())->toBeTrue();
});

it('does not rerun anything hourly after a recent partial run (the original defect)', function () {
    // One provider failed, so the run is Partial. Previously only
    // Succeeded runs counted, so this made discovery due on EVERY
    // hourly tick for ALL providers.
    recordAttempts(1, ['adzuna' => DiscoveryStatus::Failed], DiscoveryStatus::Partial);

    expect((new DiscoveryScheduleIsDue)())->toBeFalse();
});

it('does not rerun anything hourly after a recent completely failed run', function () {
    $failed = array_fill_keys(['himalayas', 'adzuna', 'jobicy'], DiscoveryStatus::Failed);
    recordAttempts(1, $failed, DiscoveryStatus::Failed);

    expect((new DiscoveryScheduleIsDue)())->toBeFalse();
});

it('retries only the failed provider once the retry window passes, leaving healthy providers alone', function () {
    recordAttempts(7, ['adzuna' => DiscoveryStatus::Failed], DiscoveryStatus::Partial);

    expect(dueValues())->toBe(['adzuna']);
});

it('retries every provider after a stale completely failed run', function () {
    $failed = array_fill_keys(['himalayas', 'adzuna', 'jobicy'], DiscoveryStatus::Failed);
    recordAttempts(7, $failed, DiscoveryStatus::Failed);

    expect(dueValues())->toEqualCanonicalizing(['himalayas', 'adzuna', 'jobicy']);
});

it('retries a persistently failing provider every retry window, never hourly', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-01 00:00:00'));
    recordAttempts(0, ['jobicy' => DiscoveryStatus::Failed], DiscoveryStatus::Partial);

    foreach ([1, 2, 5] as $hours) {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-01 00:00:00')->addHours($hours));
        expect(dueValues())->toBe([], "not due {$hours}h after a failure");
    }

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-01 07:00:00'));
    expect(dueValues())->toBe(['jobicy']);
});

it('recovers: a provider that fails and is then retried successfully returns to the normal cadence', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-01 00:00:00'));
    recordAttempts(0, ['adzuna' => DiscoveryStatus::Failed], DiscoveryStatus::Partial);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-01 07:00:00'));
    expect(dueValues())->toBe(['adzuna']);

    // The retry succeeds.
    $retry = DiscoveryRun::factory()->create(['status' => DiscoveryStatus::Succeeded, 'started_at' => now(), 'finished_at' => now()]);
    DiscoveryProviderAttempt::factory()->create([
        'discovery_run_id' => $retry->id,
        'provider' => JobDiscoverySource::Adzuna,
        'status' => DiscoveryStatus::Succeeded,
        'started_at' => now(),
        'finished_at' => now(),
    ]);

    expect(dueValues())->toBe([]);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-02 07:00:00'));
    expect(dueValues())->toContain('adzuna');
});

it('judges each provider by its latest attempt, not an older success', function () {
    recordAttempts(30);
    recordAttempts(1, ['himalayas' => DiscoveryStatus::Failed], DiscoveryStatus::Partial);

    // Himalayas: latest attempt failed 1h ago -> inside retry window.
    // Others: latest success 1h ago -> inside cadence.
    expect(dueValues())->toBe([]);
});

it('retries an attempt abandoned mid-run (never finished) after the retry window', function () {
    $run = DiscoveryRun::factory()->create(['status' => DiscoveryStatus::Running, 'started_at' => now()->subHours(7), 'finished_at' => null]);
    foreach (DiscoveryScheduleIsDue::scheduledSources() as $source) {
        DiscoveryProviderAttempt::factory()->create([
            'discovery_run_id' => $run->id,
            'provider' => $source,
            'status' => $source === JobDiscoverySource::Jobicy ? DiscoveryStatus::Running : DiscoveryStatus::Succeeded,
            'started_at' => now()->subHours(7),
            'finished_at' => $source === JobDiscoverySource::Jobicy ? null : now()->subHours(7),
        ]);
    }

    expect(dueValues())->toBe(['jobicy']);
});
