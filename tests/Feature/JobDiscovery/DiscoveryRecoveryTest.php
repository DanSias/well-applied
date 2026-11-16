<?php

use App\Enums\DiscoveryStatus;
use App\Enums\JobDiscoverySource;
use App\Models\CareerProfile;
use App\Models\DiscoveryProviderAttempt;
use App\Models\DiscoveryRun;
use App\Models\JobPosting;
use App\Support\JobDiscovery\DiscoveryExecutionRecovery;
use App\Support\JobDiscovery\ProviderExecutionLeases;
use Illuminate\Console\Scheduling\Schedule;

beforeEach(function () {
    CareerProfile::factory()->create();
    config(['job_discovery.execution.lease_seconds' => 900, 'job_discovery.execution.queued_wait_seconds' => 3600]);
});

/** @return array{0: DiscoveryRun, 1: DiscoveryProviderAttempt} */
function openAttempt(DiscoveryStatus $status, int $silentSeconds, JobDiscoverySource $source = JobDiscoverySource::Himalayas, array $attributes = []): array
{
    $run = DiscoveryRun::factory()->create(['status' => $status, 'started_at' => now()->subSeconds($silentSeconds), 'finished_at' => null]);
    $attempt = DiscoveryProviderAttempt::factory()->create([
        'discovery_run_id' => $run->id, 'provider' => $source, 'status' => $status,
        'started_at' => now()->subSeconds($silentSeconds), 'finished_at' => null,
    ] + $attributes);
    $stamp = now()->subSeconds($silentSeconds);
    DiscoveryProviderAttempt::query()->whereKey($attempt->id)->update(['updated_at' => $stamp]);
    DiscoveryRun::query()->whereKey($run->id)->update(['updated_at' => $stamp]);

    return [$run->fresh(), $attempt->fresh()];
}

it('records an abandoned running attempt as interrupted, settles its run, and is idempotent', function () {
    [$run, $attempt] = openAttempt(DiscoveryStatus::Running, 1000, attributes: ['jobs_created' => 4, 'candidates_accepted' => 5]);

    expect((new DiscoveryExecutionRecovery)->recover())->toBe(1);

    expect($attempt->fresh())->status->toBe(DiscoveryStatus::Failed)
        ->failure_category->toBe('interrupted')
        ->finished_at->not->toBeNull()
        // What it had already added is kept in its counters.
        ->jobs_created->toBe(4)
        ->and($run->fresh()->status)->toBe(DiscoveryStatus::Failed)
        ->and($run->fresh()->finished_at)->not->toBeNull();

    $before = $attempt->fresh()->toArray();
    expect((new DiscoveryExecutionRecovery)->recover())->toBe(0)
        ->and($attempt->fresh()->toArray())->toBe($before);
});

it('leaves an attempt that is silent for less than a lease alone', function () {
    [$run, $attempt] = openAttempt(DiscoveryStatus::Running, 600);

    expect((new DiscoveryExecutionRecovery)->recover())->toBe(0)
        ->and($attempt->fresh()->status)->toBe(DiscoveryStatus::Running)
        ->and($run->fresh()->status)->toBe(DiscoveryStatus::Running);
});

it('never touches a running attempt whose lease is still held', function () {
    [, $attempt] = openAttempt(DiscoveryStatus::Running, 5000);
    $lease = app(ProviderExecutionLeases::class)->acquire(JobDiscoverySource::Himalayas);

    expect((new DiscoveryExecutionRecovery)->recover())->toBe(0)
        ->and($attempt->fresh()->status)->toBe(DiscoveryStatus::Running);

    $lease->release();
    expect((new DiscoveryExecutionRecovery)->recover())->toBe(1);
});

it('records a queued request nobody picked up as never started, but not a recent one', function () {
    [$oldRun, $old] = openAttempt(DiscoveryStatus::Queued, 4000);
    [, $recent] = openAttempt(DiscoveryStatus::Queued, 1200, JobDiscoverySource::Jobicy);

    expect((new DiscoveryExecutionRecovery)->recover())->toBe(1)
        ->and($old->fresh()->status)->toBe(DiscoveryStatus::Failed)
        ->and($old->fresh()->failure_category)->toBe('not_started')
        ->and($oldRun->fresh()->status)->toBe(DiscoveryStatus::Failed)
        ->and($recent->fresh()->status)->toBe(DiscoveryStatus::Queued);
});

it('never overwrites a real outcome', function () {
    foreach ([DiscoveryStatus::Succeeded, DiscoveryStatus::Failed, DiscoveryStatus::Partial] as $i => $status) {
        $attempt = DiscoveryProviderAttempt::factory()->create(['status' => $status, 'failure_category' => $status === DiscoveryStatus::Failed ? 'provider_error' : null]);
        DiscoveryProviderAttempt::query()->whereKey($attempt->id)->update(['updated_at' => now()->subDays(3)]);
    }
    $before = DiscoveryProviderAttempt::query()->orderBy('id')->get()->toArray();

    expect((new DiscoveryExecutionRecovery)->recover())->toBe(0)
        ->and(DiscoveryProviderAttempt::query()->orderBy('id')->get()->toArray())->toBe($before);
});

it('settles a multi-provider run from all its attempts, and keeps the finished ones', function () {
    $run = DiscoveryRun::factory()->create(['status' => DiscoveryStatus::Running, 'finished_at' => null]);
    $done = DiscoveryProviderAttempt::factory()->create(['discovery_run_id' => $run->id, 'provider' => JobDiscoverySource::Jobicy, 'status' => DiscoveryStatus::Succeeded]);
    $dead = DiscoveryProviderAttempt::factory()->create(['discovery_run_id' => $run->id, 'provider' => JobDiscoverySource::Himalayas, 'status' => DiscoveryStatus::Running, 'finished_at' => null]);
    DiscoveryProviderAttempt::query()->whereKey($dead->id)->update(['updated_at' => now()->subHours(2)]);

    (new DiscoveryExecutionRecovery)->recover();

    expect($done->fresh()->status)->toBe(DiscoveryStatus::Succeeded)
        ->and($run->fresh()->status)->toBe(DiscoveryStatus::Partial);
});

it('does not settle a run that still has a live attempt', function () {
    $run = DiscoveryRun::factory()->create(['status' => DiscoveryStatus::Running, 'finished_at' => null]);
    $dead = DiscoveryProviderAttempt::factory()->create(['discovery_run_id' => $run->id, 'provider' => JobDiscoverySource::Himalayas, 'status' => DiscoveryStatus::Running, 'finished_at' => null]);
    $live = DiscoveryProviderAttempt::factory()->create(['discovery_run_id' => $run->id, 'provider' => JobDiscoverySource::Jobicy, 'status' => DiscoveryStatus::Running, 'finished_at' => null]);
    DiscoveryProviderAttempt::query()->whereKey($dead->id)->update(['updated_at' => now()->subHours(2)]);

    (new DiscoveryExecutionRecovery)->recover();

    expect($dead->fresh()->status)->toBe(DiscoveryStatus::Failed)
        ->and($live->fresh()->status)->toBe(DiscoveryStatus::Running)
        ->and($run->fresh()->status)->toBe(DiscoveryStatus::Running);
});

it('fails an old open run that never got an attempt, and leaves a fresh one', function () {
    $dead = DiscoveryRun::factory()->create(['status' => DiscoveryStatus::Running, 'finished_at' => null]);
    $fresh = DiscoveryRun::factory()->create(['status' => DiscoveryStatus::Running, 'finished_at' => null]);
    DiscoveryRun::query()->whereKey($dead->id)->update(['updated_at' => now()->subHours(2)]);

    (new DiscoveryExecutionRecovery)->recover();

    expect($dead->fresh()->status)->toBe(DiscoveryStatus::Failed)
        ->and($fresh->fresh()->status)->toBe(DiscoveryStatus::Running);
});

it('leaves every ingested posting untouched', function () {
    $job = JobPosting::factory()->create();
    openAttempt(DiscoveryStatus::Running, 5000, attributes: ['jobs_created' => 1]);
    $before = $job->fresh()->toArray();

    (new DiscoveryExecutionRecovery)->recover();

    expect(JobPosting::query()->count())->toBe(1)->and($job->fresh()->toArray())->toBe($before);
});

it('lets a recovered provider be requested again and shows it as no longer active', function () {
    [, $attempt] = openAttempt(DiscoveryStatus::Running, 5000);
    $recovery = new DiscoveryExecutionRecovery;
    expect($recovery->activeAttempt(JobDiscoverySource::Himalayas))->toBeNull();

    $recovery->recover();
    expect($recovery->activeAttempt(JobDiscoverySource::Himalayas))->toBeNull();

    openAttempt(DiscoveryStatus::Running, 10, JobDiscoverySource::Jobicy);
    expect($recovery->activeAttempt(JobDiscoverySource::Jobicy))->not->toBeNull();
});

it('runs from the command and is on the scheduler', function () {
    openAttempt(DiscoveryStatus::Running, 5000);

    $this->artisan('discovery:recover-stale')->expectsOutputToContain('Recorded 1 abandoned discovery attempt(s)')->assertSuccessful();
    $this->artisan('discovery:recover-stale')->expectsOutputToContain('No abandoned discovery runs.')->assertSuccessful();

    $scheduled = collect(app(Schedule::class)->events())->map(fn ($event) => $event->command)->filter(fn ($c) => str_contains((string) $c, 'discovery:recover-stale'));
    expect($scheduled)->toHaveCount(1);
});
