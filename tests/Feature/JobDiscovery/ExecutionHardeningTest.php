<?php

use App\Enums\DiscoveryStatus;
use App\Enums\JobDiscoverySource;
use App\Jobs\RunDiscoveryProviderJob;
use App\Models\CareerProfile;
use App\Models\DiscoveryProviderAttempt;
use App\Models\DiscoveryRun;
use App\Models\JobPosting;
use App\Support\JobDiscovery\DiscoveryExecutionRecovery;
use App\Support\JobDiscovery\DiscoveryScheduleIsDue;
use App\Support\JobDiscovery\ExecutionHeartbeat;
use App\Support\JobDiscovery\LeaseLostException;
use App\Support\JobDiscovery\ProviderExecutionLeases;
use App\Support\JobDiscovery\RequestProviderRun;
use App\Support\JobDiscovery\RunJobDiscovery;
use Illuminate\Support\Facades\Cache;
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
        'job_discovery.execution.lease_seconds' => 900,
        'job_discovery.execution.queued_wait_seconds' => 3600,
        'job_discovery.execution.queued_orphan_grace_seconds' => 300,
        'job_discovery.execution.max_queued_run_seconds' => 3000,
        'job_discovery.schedule.stale_after_hours' => 23, 'job_discovery.schedule.retry_after_hours' => 6,
        'job_discovery.search.keywords' => ['software engineer'],
        'job_discovery.search.excluded_keywords' => [],
        'job_discovery.search.remote_preference' => 'any',
        'job_discovery.search.allowed_locations' => [],
        'job_discovery.search.employment_type' => null,
    ]);
});

function hardJob(string $id): array
{
    return [
        'title' => 'Software Engineer', 'companyName' => 'Acme', 'description' => 'Build things.',
        'locationRestrictions' => ['United States'],
        'applicationLink' => "https://himalayas.app/jobs/{$id}", 'guid' => "https://himalayas.app/jobs/{$id}",
    ];
}

function lease(): ProviderExecutionLeases
{
    return app(ProviderExecutionLeases::class);
}

/** Another execution takes the provider's lease out from under the running one. */
function stealLease(JobDiscoverySource $source = JobDiscoverySource::Himalayas): void
{
    Cache::lock(lease()->key($source))->forceRelease();
    lease()->acquire($source, 'thief');
}

function himalayasAttempt(): DiscoveryProviderAttempt
{
    return DiscoveryProviderAttempt::query()->where('provider', JobDiscoverySource::Himalayas)->latest('id')->firstOrFail();
}

describe('lease ownership is an execution invariant', function () {
    it('stops before processing anything when the lease is taken during retrieval, and leaves the new owner\'s lease alone', function () {
        Http::fake(['himalayas.app/*' => function () {
            stealLease();

            return Http::response(['jobs' => [hardJob('1'), hardJob('2')]]);
        }]);

        (new RunJobDiscovery)->run([JobDiscoverySource::Himalayas]);

        $attempt = himalayasAttempt();
        expect($attempt->status)->toBe(DiscoveryStatus::Failed)
            ->and($attempt->failure_category)->toBe('lease_lost')
            ->and($attempt->failure_category)->not->toBe('provider_error')
            ->and($attempt->failure_exception_class)->toContain('LeaseLostException')
            ->and($attempt->finished_at)->not->toBeNull()
            ->and(JobPosting::query()->count())->toBe(0)
            ->and($attempt->discoveryRun->fresh()->status)->toBe(DiscoveryStatus::Failed)
            // The thief still owns it: the loser did not release it.
            ->and(lease()->isHeld(JobDiscoverySource::Himalayas))->toBeTrue()
            ->and(lease()->acquire(JobDiscoverySource::Himalayas))->toBeNull();
    });

    it('stops between candidates, keeping what was already committed and reporting it truthfully', function () {
        Http::fake(['himalayas.app/*' => Http::response(['jobs' => [hardJob('1'), hardJob('2'), hardJob('3')]])]);
        $created = 0;
        JobPosting::created(function () use (&$created) {
            if (++$created === 1) {
                stealLease();
            }
        });

        (new RunJobDiscovery)->run([JobDiscoverySource::Himalayas]);

        $attempt = himalayasAttempt();
        expect($attempt->status)->toBe(DiscoveryStatus::Failed)
            ->and($attempt->failure_category)->toBe('lease_lost')
            // The counters keep what was committed before the lease was lost.
            ->and($attempt->jobs_created)->toBe(1)
            ->and(JobPosting::query()->count())->toBe(1)
            ->and(lease()->isHeld(JobDiscoverySource::Himalayas))->toBeTrue();
    });

    it('does not report a success when the lease is lost after the last candidate', function () {
        Http::fake(['himalayas.app/*' => Http::response(['jobs' => [hardJob('1')]])]);
        JobPosting::created(fn () => stealLease());

        (new RunJobDiscovery)->run([JobDiscoverySource::Himalayas]);

        expect(himalayasAttempt())
            ->status->toBe(DiscoveryStatus::Failed)
            ->failure_category->toBe('lease_lost')
            ->and(JobPosting::query()->count())->toBe(1);
    });

    it('stops when the lease simply expires and cannot be renewed, with nobody else holding it', function () {
        Http::fake(['himalayas.app/*' => function () {
            $this->travel(901)->seconds();

            return Http::response(['jobs' => [hardJob('1')]]);
        }]);

        (new RunJobDiscovery)->run([JobDiscoverySource::Himalayas]);

        expect(himalayasAttempt())->failure_category->toBe('lease_lost')
            ->and(JobPosting::query()->count())->toBe(0);
    });

    it('treats a lost lease as sticky, so a swallowed exception cannot let a later checkpoint pass', function () {
        $heartbeat = app(ExecutionHeartbeat::class);
        $lease = lease()->acquire(JobDiscoverySource::Jobicy);
        $attempt = DiscoveryProviderAttempt::factory()->create(['provider' => JobDiscoverySource::Jobicy, 'status' => DiscoveryStatus::Running, 'finished_at' => null]);
        $heartbeat->start($lease, $attempt);

        stealLease(JobDiscoverySource::Jobicy);
        expect(fn () => $heartbeat->beat())->toThrow(LeaseLostException::class);

        // Even if the lease were free again, this execution has lost it for good.
        Cache::lock(lease()->key(JobDiscoverySource::Jobicy))->forceRelease();
        expect($heartbeat->lost())->toBeTrue()
            ->and(fn () => $heartbeat->beat())->toThrow(LeaseLostException::class);

        $heartbeat->stop();
        $heartbeat->start(lease()->acquire(JobDiscoverySource::Jobicy), $attempt);
        expect($heartbeat->lost())->toBeFalse();
        $heartbeat->stop();
    });

    it('records a provider failure as a provider failure, and still frees its own lease', function () {
        Http::fake(['himalayas.app/*' => Http::response(null, 500)]);

        (new RunJobDiscovery)->run([JobDiscoverySource::Himalayas]);

        expect(himalayasAttempt()->failure_category)->toBe('provider_error')
            ->and(lease()->isHeld(JobDiscoverySource::Himalayas))->toBeFalse();
    });

    it('does not overwrite an outcome recovery already recorded while this process was stalled', function () {
        Http::fake(['himalayas.app/*' => function () {
            $attempt = himalayasAttempt();
            DiscoveryProviderAttempt::query()->whereKey($attempt->id)->update([
                'status' => DiscoveryStatus::Failed, 'finished_at' => now(), 'failure_category' => 'interrupted', 'failure_message' => 'recovered',
            ]);

            return Http::response(['jobs' => []]);
        }]);

        (new RunJobDiscovery)->run([JobDiscoverySource::Himalayas]);

        expect(himalayasAttempt())->status->toBe(DiscoveryStatus::Failed)->failure_category->toBe('interrupted')->failure_message->toBe('recovered');
    });

    it('stops a queued run at its time budget with its own category, keeping what it added', function () {
        config(['job_discovery.execution.max_queued_run_seconds' => 120]);
        Queue::fake();
        app(RequestProviderRun::class)->request(JobDiscoverySource::Himalayas);
        $queued = himalayasAttempt();
        Http::fake(['himalayas.app/*' => function () {
            $this->travel(121)->seconds();

            // The lease is renewed on this checkpoint's way in, so only the budget stops it.
            return Http::response(['jobs' => [hardJob('1')]]);
        }]);

        (new RunJobDiscovery)->executeQueued($queued);

        expect($queued->fresh())->status->toBe(DiscoveryStatus::Failed)->failure_category->toBe('deadline_exceeded')
            ->and(JobPosting::query()->count())->toBe(0);
    });
});

describe('queued requests take precedence over scheduled runs', function () {
    function pendingRequest(JobDiscoverySource $source = JobDiscoverySource::Himalayas): DiscoveryProviderAttempt
    {
        Queue::fake();
        expect(app(RequestProviderRun::class)->request($source))->toBe('queued');

        return DiscoveryProviderAttempt::query()->where('provider', $source)->latest('id')->firstOrFail();
    }

    it('defers a scheduled run of a provider with a pending request, and the other providers still run', function () {
        pendingRequest();
        Http::fake(['*' => Http::response(['jobs' => [], 'results' => []])]);

        $execution = (new RunJobDiscovery)->execute(scheduled: true);

        expect($execution->deferred)->toBe([JobDiscoverySource::Himalayas])
            ->and(DiscoveryProviderAttempt::query()->where('provider', 'himalayas')->count())->toBe(1) // only the queued one
            ->and(DiscoveryProviderAttempt::query()->whereIn('provider', ['adzuna', 'jobicy'])->count())->toBe(2)
            ->and(himalayasAttempt()->status)->toBe(DiscoveryStatus::Queued)
            ->and(lease()->isHeld(JobDiscoverySource::Himalayas))->toBeFalse();
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'himalayas.app'));
    });

    it('records nothing when everything a scheduled tick would run is deferred', function () {
        pendingRequest();

        $execution = (new RunJobDiscovery)->execute([JobDiscoverySource::Himalayas], scheduled: true);

        expect($execution->recorded())->toBeFalse()->and(DiscoveryRun::query()->count())->toBe(1); // the request's own run
    });

    it('does not defer an explicit manual run', function () {
        pendingRequest();
        Http::fake(['himalayas.app/*' => Http::response(['jobs' => []])]);

        $execution = (new RunJobDiscovery)->execute([JobDiscoverySource::Himalayas]);

        expect($execution->deferred)->toBe([])->and($execution->recorded())->toBeTrue();
    });

    it('leaves the provider out of the due list while its request is valid, and puts it back when it is not', function () {
        pendingRequest();
        $due = fn () => array_map(fn ($s) => $s->value, (new DiscoveryScheduleIsDue)->dueSources());

        expect($due())->not->toContain('himalayas')->and($due())->toContain('jobicy');

        DiscoveryProviderAttempt::query()->where('provider', 'himalayas')->update(['status' => DiscoveryStatus::Failed, 'failure_category' => 'not_started', 'finished_at' => now()]);
        expect($due())->toContain('himalayas');
    });

    it('does not let a request that never started push the provider out to the retry cadence', function () {
        $success = DiscoveryProviderAttempt::factory()->create(['provider' => JobDiscoverySource::Himalayas, 'status' => DiscoveryStatus::Succeeded, 'started_at' => now()->subHours(30), 'finished_at' => now()->subHours(30)]);
        DiscoveryProviderAttempt::factory()->create(['provider' => JobDiscoverySource::Himalayas, 'status' => DiscoveryStatus::Failed, 'failure_category' => 'not_started', 'started_at' => now()->subMinutes(5), 'finished_at' => now()->subMinutes(5)]);

        expect(array_map(fn ($s) => $s->value, (new DiscoveryScheduleIsDue)->dueSources()))->toContain('himalayas');
        $success->delete();
    });

    it('stops deferring once the request is stale, and recovers it', function () {
        $attempt = pendingRequest();
        DiscoveryProviderAttempt::query()->whereKey($attempt->id)->update(['updated_at' => now()->subHours(2)]);
        Http::fake(['himalayas.app/*' => Http::response(['jobs' => []])]);

        expect((new DiscoveryExecutionRecovery)->hasPendingRequest(JobDiscoverySource::Himalayas))->toBeFalse();
        $execution = (new RunJobDiscovery)->execute([JobDiscoverySource::Himalayas], scheduled: true);
        expect($execution->deferred)->toBe([])->and($execution->recorded())->toBeTrue();

        (new DiscoveryExecutionRecovery)->recover();
        expect($attempt->fresh())->status->toBe(DiscoveryStatus::Failed)->failure_category->toBe('not_started');
    });

    it('refuses a request while a scheduled run holds the provider, and a scheduled tick defers to a request recorded first', function () {
        // Scheduled run wins the lease: the request is refused for the whole duration.
        $result = null;
        Http::fake(['himalayas.app/*' => function () use (&$result) {
            $result = app(RequestProviderRun::class)->request(JobDiscoverySource::Himalayas);

            return Http::response(['jobs' => []]);
        }]);
        Queue::fake();
        (new RunJobDiscovery)->execute([JobDiscoverySource::Himalayas], scheduled: true);
        expect($result)->toBe('already_active');

        // Request recorded first: the later tick defers.
        expect(app(RequestProviderRun::class)->request(JobDiscoverySource::Himalayas))->toBe('queued')
            ->and((new RunJobDiscovery)->execute([JobDiscoverySource::Himalayas], scheduled: true)->deferred)->toBe([JobDiscoverySource::Himalayas]);
    });

    it('records exactly one request when two arrive while the lease is the mutex', function () {
        Queue::fake();
        $other = lease()->acquire(JobDiscoverySource::Himalayas, 'competing-request');

        expect(app(RequestProviderRun::class)->request(JobDiscoverySource::Himalayas))->toBe('already_active')
            ->and(DiscoveryProviderAttempt::query()->count())->toBe(0);

        $other->release();
        expect(app(RequestProviderRun::class)->request(JobDiscoverySource::Himalayas))->toBe('queued')
            ->and(app(RequestProviderRun::class)->request(JobDiscoverySource::Himalayas))->toBe('already_active')
            ->and(DiscoveryProviderAttempt::query()->count())->toBe(1)
            ->and(lease()->isHeld(JobDiscoverySource::Himalayas))->toBeFalse(); // the mutex is not left held
    });

    it('keeps providers independent', function () {
        pendingRequest(JobDiscoverySource::Himalayas);

        expect(app(RequestProviderRun::class)->request(JobDiscoverySource::Jobicy))->toBe('queued')
            ->and((new DiscoveryExecutionRecovery)->hasPendingRequest(JobDiscoverySource::Adzuna))->toBeFalse();
    });
});

describe('queued expiry uses evidence from the queue', function () {
    it('finds a real queued job by attempt id, and does not confuse similar ids', function () {
        config(['queue.default' => 'database']);
        $attempt = DiscoveryProviderAttempt::factory()->create(['status' => DiscoveryStatus::Queued, 'finished_at' => null]);
        RunDiscoveryProviderJob::dispatch($attempt->id);
        RunDiscoveryProviderJob::dispatch($attempt->id + 100);
        $recovery = new DiscoveryExecutionRecovery;

        expect($recovery->jobIsQueued($attempt->id))->toBeTrue()
            ->and($recovery->jobIsQueued($attempt->id + 100))->toBeTrue()
            ->and($recovery->jobIsQueued($attempt->id + 1))->toBeFalse()
            ->and($recovery->jobIsQueued(($attempt->id * 10) + 5))->toBeFalse();
    });

    it('is unknown for a queue it cannot inspect, so only time applies', function () {
        config(['queue.default' => 'sync']);

        expect((new DiscoveryExecutionRecovery)->jobIsQueued(1))->toBeNull();
    });

    it('records a request whose job vanished from the queue as never started after the grace, but not before', function () {
        config(['queue.default' => 'database']);
        $young = DiscoveryProviderAttempt::factory()->create(['provider' => JobDiscoverySource::Himalayas, 'status' => DiscoveryStatus::Queued, 'finished_at' => null]);
        $orphan = DiscoveryProviderAttempt::factory()->create(['provider' => JobDiscoverySource::Jobicy, 'status' => DiscoveryStatus::Queued, 'finished_at' => null]);
        $waiting = DiscoveryProviderAttempt::factory()->create(['provider' => JobDiscoverySource::Adzuna, 'status' => DiscoveryStatus::Queued, 'finished_at' => null]);
        RunDiscoveryProviderJob::dispatch($waiting->id);
        DiscoveryProviderAttempt::query()->whereIn('id', [$orphan->id, $waiting->id])->update(['updated_at' => now()->subMinutes(10)]);

        expect((new DiscoveryExecutionRecovery)->recover())->toBe(1)
            ->and($orphan->fresh())->status->toBe(DiscoveryStatus::Failed)->failure_category->toBe('not_started')
            ->and($young->fresh()->status)->toBe(DiscoveryStatus::Queued)
            ->and($waiting->fresh()->status)->toBe(DiscoveryStatus::Queued);
    });
});

describe('the queue, worker and lease numbers form one contract', function () {
    it('keeps the run budget under the job timeout, the job timeout under retry_after, and the lease shorter than both', function () {
        $job = new RunDiscoveryProviderJob(1);
        $retryAfter = (int) config('queue.connections.database.retry_after');
        $lease = (int) config('job_discovery.execution.lease_seconds');
        $budget = (int) config('job_discovery.execution.max_queued_run_seconds');

        expect($lease)->toBeLessThan($budget)
            ->and($budget)->toBeLessThan($job->timeout)
            ->and($job->timeout)->toBeLessThan($retryAfter)
            // Waiting for a busy provider is bounded by tries x 30s and must be shorter than the queued expiry.
            ->and($job->tries * 30)->toBeLessThan((int) config('job_discovery.execution.queued_wait_seconds'));
    });

    it('lets a queued request wait behind two of the longest legitimate jobs, and the scheduler yield for less than that', function () {
        // The documented worst job: a resume generation, 2 x (3 attempts x the longest model timeout + 2s) = 5404s by default.
        $worstJob = 2 * (3 * max((int) config('services.ollama.resume_selection_timeout'), (int) config('services.ollama.resume_wording_timeout')) + 2);
        // The shipped defaults, not this file's per-test overrides.
        $shipped = (require config_path('job_discovery.php'))['execution'];
        $wait = $shipped['queued_wait_seconds'];
        $defer = $shipped['queued_defer_seconds'];

        expect($worstJob)->toBe(5404)
            ->and($wait)->toBeGreaterThan(2 * $worstJob)
            ->and($defer)->toBeLessThan($wait)
            ->and($defer)->toBeGreaterThan(0)
            // The budget and the job timeout are about executing, not waiting, and stay below retry_after.
            ->and($shipped['max_queued_run_seconds'])->toBeLessThan((new RunDiscoveryProviderJob(1))->timeout);
    });

    it('puts a busy provider\'s job back on the queue instead of running it, through a real worker', function () {
        config(['queue.default' => 'database']);
        $attempt = DiscoveryProviderAttempt::factory()->create(['provider' => JobDiscoverySource::Himalayas, 'status' => DiscoveryStatus::Queued, 'finished_at' => null]);
        RunDiscoveryProviderJob::dispatch($attempt->id);
        $holder = lease()->acquire(JobDiscoverySource::Himalayas, 'scheduled');

        $this->artisan('queue:work', ['--once' => true, '--tries' => 1])->assertSuccessful();

        $row = DB::table('jobs')->first();
        expect($row)->not->toBeNull()
            ->and((int) $row->attempts)->toBe(1)
            ->and($row->reserved_at)->toBeNull()
            ->and((int) $row->available_at)->toBeGreaterThan(now()->getTimestamp() + 20)
            ->and($attempt->fresh()->status)->toBe(DiscoveryStatus::Queued);
        Http::assertNothingSent();

        $holder->release();
    });

    it('records the request as never started when the job runs out of tries, through a real worker', function () {
        config(['queue.default' => 'database']);
        $attempt = DiscoveryProviderAttempt::factory()->create(['provider' => JobDiscoverySource::Himalayas, 'status' => DiscoveryStatus::Queued, 'finished_at' => null,
            'discovery_run_id' => DiscoveryRun::factory()->create(['status' => DiscoveryStatus::Queued])->id]);
        RunDiscoveryProviderJob::dispatch($attempt->id);
        DB::table('jobs')->update(['attempts' => 20]);
        $holder = lease()->acquire(JobDiscoverySource::Himalayas, 'scheduled');

        $this->artisan('queue:work', ['--once' => true, '--tries' => 1])->assertSuccessful();

        expect($attempt->fresh())->status->toBe(DiscoveryStatus::Failed)->failure_category->toBe('not_started')
            ->and($attempt->discoveryRun->fresh()->status)->toBe(DiscoveryStatus::Failed)
            ->and(lease()->isHeld(JobDiscoverySource::Himalayas))->toBeTrue(); // the scheduled holder is untouched

        $holder->release();
    });
});

describe('waiting is not expiring', function () {
    function waitingRequest(int $ageSeconds, JobDiscoverySource $source = JobDiscoverySource::Himalayas): DiscoveryProviderAttempt
    {
        Queue::fake();
        app(RequestProviderRun::class)->request($source);
        $attempt = DiscoveryProviderAttempt::query()->where('provider', $source)->latest('id')->firstOrFail();
        DiscoveryProviderAttempt::query()->whereKey($attempt->id)->update(['updated_at' => now()->subSeconds($ageSeconds)]);
        DiscoveryRun::query()->whereKey($attempt->discovery_run_id)->update(['updated_at' => now()->subSeconds($ageSeconds)]);

        return $attempt;
    }

    beforeEach(function () {
        config(['job_discovery.execution.queued_wait_seconds' => 14400, 'job_discovery.execution.queued_defer_seconds' => 3600]);
    });

    it('keeps a request that waited behind a legitimate long job queued, and keeps Run Now from queueing it twice', function () {
        $attempt = waitingRequest(5500); // longer than the worst single job, shorter than the wait limit

        expect((new DiscoveryExecutionRecovery)->recover())->toBe(0)
            ->and($attempt->fresh()->status)->toBe(DiscoveryStatus::Queued)
            ->and((new DiscoveryExecutionRecovery)->activeAttempt(JobDiscoverySource::Himalayas)?->id)->toBe($attempt->id)
            ->and(app(RequestProviderRun::class)->request(JobDiscoverySource::Himalayas))->toBe('already_active');
    });

    it('lets the scheduler stop yielding after the deferral window while the request stays queued', function () {
        $attempt = waitingRequest(5500);
        Http::fake(['himalayas.app/*' => Http::response(['jobs' => []])]);

        expect((new DiscoveryExecutionRecovery)->hasPendingRequest(JobDiscoverySource::Himalayas))->toBeFalse();
        $execution = (new RunJobDiscovery)->execute([JobDiscoverySource::Himalayas], scheduled: true);

        expect($execution->deferred)->toBe([])->and($execution->recorded())->toBeTrue()
            ->and($attempt->fresh()->status)->toBe(DiscoveryStatus::Queued);
    });

    it('still defers to a request younger than the deferral window', function () {
        waitingRequest(3000);

        expect((new DiscoveryExecutionRecovery)->hasPendingRequest(JobDiscoverySource::Himalayas))->toBeTrue();
    });

    it('records a request as never started only after the wait limit, and then it blocks nothing', function () {
        $attempt = waitingRequest(15000);

        expect((new DiscoveryExecutionRecovery)->recover())->toBe(1)
            ->and($attempt->fresh())->status->toBe(DiscoveryStatus::Failed)->failure_category->toBe('not_started')
            ->and((new DiscoveryExecutionRecovery)->activeAttempt(JobDiscoverySource::Himalayas))->toBeNull()
            ->and(app(RequestProviderRun::class)->request(JobDiscoverySource::Himalayas))->toBe('queued');
    });

    it('never lets the deferral window exceed the wait limit', function () {
        config(['job_discovery.execution.queued_wait_seconds' => 1800, 'job_discovery.execution.queued_defer_seconds' => 99999]);

        expect((new DiscoveryExecutionRecovery)->deferCutoff()->getTimestamp())->toBeGreaterThanOrEqual((new DiscoveryExecutionRecovery)->queuedCutoff()->getTimestamp() - 1);
    });
});
