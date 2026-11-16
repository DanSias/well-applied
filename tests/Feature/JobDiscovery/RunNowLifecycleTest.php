<?php

use App\Enums\DiscoveryStatus;
use App\Enums\JobDiscoverySource;
use App\Enums\ScheduleOverride;
use App\Jobs\RunDiscoveryProviderJob;
use App\Models\CareerProfile;
use App\Models\DiscoveryProviderAttempt;
use App\Models\DiscoveryProviderSetting;
use App\Models\DiscoveryRun;
use App\Support\JobDiscovery\ProviderExecutionLeases;
use App\Support\JobDiscovery\RequestProviderRun;
use App\Support\JobDiscovery\RunJobDiscovery;
use App\Support\JobDiscovery\SettleDiscoveryRun;
use Illuminate\Contracts\Queue\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Sleep::fake();
    Http::preventStrayRequests();
    CareerProfile::factory()->create();
    config([
        'services.adzuna.app_id' => 'id', 'services.adzuna.app_key' => 'key', 'services.adzuna.country' => 'us',
        'job_discovery.execution.lease_seconds' => 900, 'job_discovery.execution.queued_wait_seconds' => 3600,
        'job_discovery.search.keywords' => ['software engineer'],
    ]);
});

function requestRun(JobDiscoverySource $source): string
{
    return app(RequestProviderRun::class)->request($source);
}

function queuedAttempt(JobDiscoverySource $source = JobDiscoverySource::Himalayas): DiscoveryProviderAttempt
{
    return DiscoveryProviderAttempt::query()->where('provider', $source)->latest('id')->firstOrFail();
}

function runJob(DiscoveryProviderAttempt $attempt): void
{
    (new RunDiscoveryProviderJob($attempt->id))->handle(new RunJobDiscovery);
}

it('records a request as a queued run and attempt, dispatches one job, and makes no provider request', function () {
    Queue::fake();

    expect(requestRun(JobDiscoverySource::Himalayas))->toBe('queued');

    $attempt = queuedAttempt();
    expect($attempt->status)->toBe(DiscoveryStatus::Queued)
        ->and($attempt->finished_at)->toBeNull()
        ->and($attempt->discoveryRun->status)->toBe(DiscoveryStatus::Queued);
    Queue::assertPushed(RunDiscoveryProviderJob::class, fn ($job) => $job->attemptId === $attempt->id);
    Queue::assertPushed(RunDiscoveryProviderJob::class, 1);
    Http::assertNothingSent();
});

it('refuses a provider that is not registered and configured, recording nothing', function () {
    Queue::fake();
    config(['services.adzuna.app_id' => '']);

    expect(requestRun(JobDiscoverySource::Adzuna))->toBe('unavailable')
        ->and(requestRun(JobDiscoverySource::Usajobs))->toBe('unavailable');

    expect(DiscoveryRun::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('runs a paused provider and a manual-only provider on request', function () {
    Queue::fake();
    config(['services.usajobs.api_key' => 'k', 'services.usajobs.user_agent' => 'me@example.test', 'job_discovery.usajobs.scheduled' => false]);
    DiscoveryProviderSetting::query()->create(['provider' => 'himalayas', 'schedule_override' => ScheduleOverride::Paused]);

    expect(requestRun(JobDiscoverySource::Himalayas))->toBe('queued')
        ->and(requestRun(JobDiscoverySource::Usajobs))->toBe('queued');
});

it('rejects a second request while one is queued, and lets other providers queue independently', function () {
    Queue::fake();

    expect(requestRun(JobDiscoverySource::Himalayas))->toBe('queued')
        ->and(requestRun(JobDiscoverySource::Himalayas))->toBe('already_active')
        ->and(requestRun(JobDiscoverySource::Jobicy))->toBe('queued');

    expect(DiscoveryProviderAttempt::query()->count())->toBe(2);
    Queue::assertPushed(RunDiscoveryProviderJob::class, 2);
});

it('rejects a request while a run of that provider is executing or holds its lease', function () {
    Queue::fake();
    DiscoveryProviderAttempt::factory()->create(['provider' => JobDiscoverySource::Himalayas, 'status' => DiscoveryStatus::Running, 'finished_at' => null]);
    expect(requestRun(JobDiscoverySource::Himalayas))->toBe('already_active');

    app(ProviderExecutionLeases::class)->acquire(JobDiscoverySource::Jobicy);
    expect(requestRun(JobDiscoverySource::Jobicy))->toBe('already_active');

    Queue::assertNothingPushed();
});

it('clears a long-abandoned claim before judging whether the provider is busy', function () {
    Queue::fake();
    $stale = DiscoveryProviderAttempt::factory()->create([
        'provider' => JobDiscoverySource::Himalayas, 'status' => DiscoveryStatus::Running, 'finished_at' => null,
        'discovery_run_id' => DiscoveryRun::factory()->create(['status' => DiscoveryStatus::Running])->id,
    ]);
    DiscoveryProviderAttempt::query()->whereKey($stale->id)->update(['updated_at' => now()->subHour()]);

    expect(requestRun(JobDiscoverySource::Himalayas))->toBe('queued')
        ->and($stale->fresh()->status)->toBe(DiscoveryStatus::Failed)
        ->and($stale->fresh()->failure_category)->toBe('interrupted');
});

it('drops the request when the queue refuses it, so nothing stays queued', function () {
    $queue = Mockery::mock(Illuminate\Contracts\Queue\Queue::class);
    $queue->shouldReceive('push', 'later', 'pushOn', 'laterOn')->andThrow(new RuntimeException('queue down'));
    $queue->shouldReceive('getConnectionName')->andReturn('database');
    $this->mock(Factory::class, fn ($mock) => $mock->shouldReceive('connection')->andReturn($queue));

    expect(requestRun(JobDiscoverySource::Himalayas))->toBe('not_queued')
        ->and(DiscoveryRun::query()->count())->toBe(0)
        ->and(DiscoveryProviderAttempt::query()->count())->toBe(0);
});

it('takes a queued request through running to succeeded using the same attempt', function () {
    Queue::fake();
    requestRun(JobDiscoverySource::Himalayas);
    $attempt = queuedAttempt();
    $states = [];

    Http::fake([
        'himalayas.app/*' => function () use ($attempt, &$states) {
            $states = [$attempt->fresh()->status, $attempt->discoveryRun->fresh()->status, app(ProviderExecutionLeases::class)->isHeld(JobDiscoverySource::Himalayas)];

            return Http::response(['jobs' => []]);
        },
    ]);

    runJob($attempt);

    expect($states)->toBe([DiscoveryStatus::Running, DiscoveryStatus::Running, true])
        ->and($attempt->fresh()->status)->toBe(DiscoveryStatus::Succeeded)
        ->and($attempt->fresh()->finished_at)->not->toBeNull()
        ->and($attempt->fresh()->candidates_retrieved)->toBe(0)
        ->and($attempt->discoveryRun->fresh()->status)->toBe(DiscoveryStatus::Succeeded)
        ->and(DiscoveryProviderAttempt::query()->count())->toBe(1)
        ->and(DiscoveryRun::query()->count())->toBe(1)
        ->and(app(ProviderExecutionLeases::class)->isHeld(JobDiscoverySource::Himalayas))->toBeFalse();
});

it('takes a queued request through running to failed when the provider fails', function () {
    Queue::fake();
    requestRun(JobDiscoverySource::Himalayas);
    $attempt = queuedAttempt();
    Http::fake(['*' => Http::response(null, 500)]);

    runJob($attempt);

    expect($attempt->fresh()->status)->toBe(DiscoveryStatus::Failed)
        ->and($attempt->fresh()->failure_category)->toBe('provider_error')
        ->and($attempt->discoveryRun->fresh()->status)->toBe(DiscoveryStatus::Failed)
        ->and(app(ProviderExecutionLeases::class)->isHeld(JobDiscoverySource::Himalayas))->toBeFalse();
});

it('settles a run containing a partial attempt as partial', function () {
    $run = DiscoveryRun::factory()->create(['status' => DiscoveryStatus::Running]);
    DiscoveryProviderAttempt::factory()->create(['discovery_run_id' => $run->id, 'status' => DiscoveryStatus::Partial]);

    expect(SettleDiscoveryRun::settle($run)->status)->toBe(DiscoveryStatus::Partial);
});

it('does nothing when the same job is delivered twice', function () {
    Queue::fake();
    requestRun(JobDiscoverySource::Himalayas);
    $attempt = queuedAttempt();
    Http::fake(['himalayas.app/*' => Http::response(['jobs' => []])]);

    runJob($attempt);
    runJob($attempt);

    expect(DiscoveryProviderAttempt::query()->count())->toBe(1)
        ->and(DiscoveryRun::query()->count())->toBe(1);
    Http::assertSentCount(1);
});

it('does not start a request whose attempt was already claimed, even for a job holding a stale copy', function () {
    Queue::fake();
    requestRun(JobDiscoverySource::Himalayas);
    $attempt = queuedAttempt();
    DiscoveryProviderAttempt::query()->whereKey($attempt->id)->update(['status' => DiscoveryStatus::Running]);

    expect((new RunJobDiscovery)->executeQueued($attempt))->toBe('not_queued');
    Http::assertNothingSent();
    expect(app(ProviderExecutionLeases::class)->isHeld(JobDiscoverySource::Himalayas))->toBeFalse();
});

it('leaves a queued request queued, with no provider request, while another execution holds the provider', function () {
    Queue::fake();
    requestRun(JobDiscoverySource::Himalayas);
    $attempt = queuedAttempt();
    $other = app(ProviderExecutionLeases::class)->acquire(JobDiscoverySource::Himalayas);

    runJob($attempt);

    expect($attempt->fresh()->status)->toBe(DiscoveryStatus::Queued);
    Http::assertNothingSent();

    $other->release();
});

it('refuses a scheduled or CLI run of a provider while its queued run is executing', function () {
    Queue::fake();
    requestRun(JobDiscoverySource::Himalayas);
    $attempt = queuedAttempt();
    $inner = null;
    Http::fake([
        'himalayas.app/*' => function () use (&$inner) {
            $inner = (new RunJobDiscovery)->execute([JobDiscoverySource::Himalayas]);

            return Http::response(['jobs' => []]);
        },
    ]);

    runJob($attempt);

    expect($inner->skipped)->toBe([JobDiscoverySource::Himalayas])
        ->and($inner->recorded())->toBeFalse()
        ->and(DiscoveryProviderAttempt::query()->count())->toBe(1);
});

it('records a queued request as never started when its job gives up waiting for a busy provider', function () {
    Queue::fake();
    requestRun(JobDiscoverySource::Himalayas);
    $attempt = queuedAttempt();

    (new RunDiscoveryProviderJob($attempt->id))->failed(new RuntimeException('too many attempts'));

    expect($attempt->fresh()->status)->toBe(DiscoveryStatus::Failed)
        ->and($attempt->fresh()->failure_category)->toBe('not_started')
        ->and($attempt->discoveryRun->fresh()->status)->toBe(DiscoveryStatus::Failed);
});

it('leaves a queued request alone when one failed delivery has another still waiting in the queue', function () {
    config(['queue.default' => 'database']);
    $attempt = DiscoveryProviderAttempt::factory()->create(['provider' => JobDiscoverySource::Himalayas, 'status' => DiscoveryStatus::Queued, 'finished_at' => null]);
    RunDiscoveryProviderJob::dispatch($attempt->id);

    (new RunDiscoveryProviderJob($attempt->id))->failed(new RuntimeException('database is locked'));

    expect($attempt->fresh()->status)->toBe(DiscoveryStatus::Queued);

    DB::table('jobs')->delete();
    (new RunDiscoveryProviderJob($attempt->id))->failed(new RuntimeException('database is locked'));

    expect($attempt->fresh()->status)->toBe(DiscoveryStatus::Failed);
});

it('records a killed worker\'s attempt as interrupted and frees only its own lease', function () {
    Queue::fake();
    requestRun(JobDiscoverySource::Himalayas);
    $attempt = queuedAttempt();
    DiscoveryProviderAttempt::query()->whereKey($attempt->id)->update(['status' => DiscoveryStatus::Running]);
    app(ProviderExecutionLeases::class)->acquire(JobDiscoverySource::Himalayas, RunJobDiscovery::queuedOwner($attempt));

    (new RunDiscoveryProviderJob($attempt->id))->failed(new RuntimeException('timed out'));

    expect($attempt->fresh()->failure_category)->toBe('interrupted')
        ->and(app(ProviderExecutionLeases::class)->isHeld(JobDiscoverySource::Himalayas))->toBeFalse();

    // Someone else's lease is not freed by a different request's failure handler.
    app(ProviderExecutionLeases::class)->acquire(JobDiscoverySource::Himalayas, 'scheduled-run');
    (new RunDiscoveryProviderJob($attempt->id))->failed(new RuntimeException('again'));
    expect(app(ProviderExecutionLeases::class)->isHeld(JobDiscoverySource::Himalayas))->toBeTrue();
});

it('fails a queued request whose provider is no longer registered, rather than leaving it queued', function () {
    Queue::fake();
    requestRun(JobDiscoverySource::Himalayas);
    $attempt = queuedAttempt();
    DiscoveryProviderAttempt::query()->whereKey($attempt->id)->update(['provider' => JobDiscoverySource::Usajobs]);

    runJob($attempt);

    expect($attempt->fresh()->status)->toBe(DiscoveryStatus::Failed)
        ->and($attempt->fresh()->failure_category)->toBe('provider_unavailable')
        ->and($attempt->discoveryRun->fresh()->status)->toBe(DiscoveryStatus::Failed);
});
