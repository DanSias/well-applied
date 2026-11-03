<?php

use App\Enums\GenerationStatus;
use App\Enums\GenerationType;
use App\Jobs\GenerateJobAnalysisJob;
use App\Jobs\GenerateJobMatchJob;
use App\Jobs\GenerateResumeVariantJob;
use App\Jobs\RenderResumePdfJob;
use App\Models\GenerationAttempt;
use App\Models\JobAnalysis;
use App\Models\JobMatch;
use App\Models\JobPosting;
use App\Models\ResumeVariant;
use App\Support\GenerationAttempt\QueueGenerationAttempt;
use Illuminate\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcherContract;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Support\JobMatchFixtures;

/**
 * Direct coverage of App\Support\GenerationAttempt\QueueGenerationAttempt —
 * the shared atomic claim() both JobAnalysisController::store() and
 * JobMatchController::store() now route through: the non-blocking per
 * (type, subject) lock, the in-lock recheck for an active attempt, the
 * Queued row, the dispatch of the existing job, and the dispatch-failure
 * path. HTTP-level behavior around the claim (validation, 404s,
 * redirects) stays covered by
 * tests/Feature/JobAnalysis/JobAnalysisControllerAsyncTest.php and
 * tests/Feature/JobMatch/JobMatchControllerAsyncTest.php.
 */
it('claims a fresh JobAnalysis attempt for a posting and dispatches GenerateJobAnalysisJob referencing it', function () {
    Queue::fake();
    $jobPosting = JobPosting::factory()->create();

    $claimed = app(QueueGenerationAttempt::class)
        ->claim(GenerationType::JobAnalysis, $jobPosting);

    expect($claimed)->not->toBeNull();

    expect($claimed->generation_type)->toBe(GenerationType::JobAnalysis)
        ->and($claimed->status)->toBe(GenerationStatus::Queued)
        ->and($claimed->subject_type)->toBe((new JobPosting)->getMorphClass())
        ->and($claimed->subject_id)->toBe($jobPosting->id)
        ->and($claimed->queued_at)->not->toBeNull();

    expect(GenerationAttempt::count())->toBe(1);

    Queue::assertPushed(GenerateJobAnalysisJob::class, fn (GenerateJobAnalysisJob $job) => $job->attempt->is($claimed));
});

it('claims a fresh JobMatch attempt for an analysis and dispatches GenerateJobMatchJob referencing it', function () {
    Queue::fake();
    ['analysis' => $analysis] = JobMatchFixtures::job();

    $claimed = app(QueueGenerationAttempt::class)
        ->claim(GenerationType::JobMatch, $analysis);

    expect($claimed)->not->toBeNull();

    expect($claimed->generation_type)->toBe(GenerationType::JobMatch)
        ->and($claimed->status)->toBe(GenerationStatus::Queued)
        ->and($claimed->subject_type)->toBe((new JobAnalysis)->getMorphClass())
        ->and($claimed->subject_id)->toBe($analysis->id)
        ->and($claimed->queued_at)->not->toBeNull();

    expect(GenerationAttempt::count())->toBe(1);

    Queue::assertPushed(GenerateJobMatchJob::class, fn (GenerateJobMatchJob $job) => $job->attempt->is($claimed));
});

it('returns null — creating nothing and dispatching nothing — when the claim lock is already held by a concurrent claim', function () {
    Queue::fake();
    $jobPosting = JobPosting::factory()->create();

    // Hold the very lock the service would try to take, as a
    // concurrent in-flight claim would.
    $lockKey = QueueGenerationAttempt::claimLockKey(GenerationType::JobAnalysis, $jobPosting);
    $held = Cache::lock($lockKey, 10);
    expect($held->acquire())->toBeTrue();

    try {
        expect(app(QueueGenerationAttempt::class)->claim(GenerationType::JobAnalysis, $jobPosting))
            ->toBeNull();

        expect(GenerationAttempt::count())->toBe(0);
        Queue::assertNothingPushed();

        // The lock is still held by "ours", and a fresh acquisition —
        // as a third claim would attempt — is still rejected while it
        // is.
        expect(Cache::lock($lockKey, 10)->acquire())->toBeFalse();
    } finally {
        $held->release();
    }
});

it('returns null when an attempt is already active for the same posting and type', function () {
    Queue::fake();
    $jobPosting = JobPosting::factory()->create();

    // One block covering both active statuses: a queued row and a
    // running row for the same subject+type, the authoritative
    // duplicate rule the in-lock recheck must see.
    GenerationAttempt::factory()->create([
        'subject_type' => (new JobPosting)->getMorphClass(),
        'subject_id' => $jobPosting->id,
        'generation_type' => GenerationType::JobAnalysis,
        'status' => GenerationStatus::Queued,
    ]);
    GenerationAttempt::factory()->create([
        'subject_type' => (new JobPosting)->getMorphClass(),
        'subject_id' => $jobPosting->id,
        'generation_type' => GenerationType::JobAnalysis,
        'status' => GenerationStatus::Running,
    ]);

    expect(app(QueueGenerationAttempt::class)->claim(GenerationType::JobAnalysis, $jobPosting))
        ->toBeNull();

    expect(GenerationAttempt::count())->toBe(2);
    Queue::assertNothingPushed();
});

it('allows a fresh claim once a prior attempt for the same posting has failed', function () {
    Queue::fake();
    $jobPosting = JobPosting::factory()->create();
    GenerationAttempt::factory()->create([
        'subject_type' => (new JobPosting)->getMorphClass(),
        'subject_id' => $jobPosting->id,
        'generation_type' => GenerationType::JobAnalysis,
        'status' => GenerationStatus::Failed,
    ]);

    $claimed = app(QueueGenerationAttempt::class)
        ->claim(GenerationType::JobAnalysis, $jobPosting);

    expect($claimed)->not->toBeNull();
    expect($claimed->status)->toBe(GenerationStatus::Queued);
    expect(GenerationAttempt::count())->toBe(2);
    Queue::assertPushed(GenerateJobAnalysisJob::class, 1);
});

it('marks the new attempt Failed (dispatch_error), logs, and rethrows the original exception when dispatch() throws synchronously', function () {
    $jobPosting = JobPosting::factory()->create();

    // Make the real dispatch path throw synchronously inside claim():
    // the concrete bus dispatcher is bound under its contract with a
    // queue resolver that throws where it would hand the job to a
    // connection. Note: deliberately NOT Queue::fake() here — the
    // failure must come from the real dispatch seam, not the fake.
    //
    // Inside that resolver, while the dispatch is actually happening,
    // also prove the claim is still under the lock: claim() releases
    // the lock only in its finally, and the dispatch runs before it,
    // so dispatch occurs while the claim lock is held. We check with a
    // *separate* lock instance (distinct owner) attempting a
    // non-blocking acquire of the very key claim() holds — a failing
    // acquire() only reads the store (no write) and never disturbs the
    // lock the service owns.
    $lockKey = QueueGenerationAttempt::claimLockKey(
        GenerationType::JobAnalysis,
        $jobPosting,
    );
    app()->instance(
        BusDispatcherContract::class,
        new BusDispatcher(app(), function () use ($lockKey) {
            expect(Cache::lock($lockKey, 10)->acquire())->toBeFalse();

            throw new RuntimeException('Simulated dispatch failure.');
        }),
    );

    Log::spy();

    expect(fn () => app(QueueGenerationAttempt::class)->claim(GenerationType::JobAnalysis, $jobPosting))
        ->toThrow(RuntimeException::class, 'Simulated dispatch failure.');

    // The row was already created — it must now be durably failed,
    // not stranded in Queued.
    expect(GenerationAttempt::count())->toBe(1);

    $attempt = GenerationAttempt::first();
    expect($attempt)->not->toBeNull();

    expect($attempt->status)->toBe(GenerationStatus::Failed)
        ->and($attempt->generation_type)->toBe(GenerationType::JobAnalysis)
        ->and($attempt->failure_category)->toBe('dispatch_error')
        ->and($attempt->failure_message)->toContain('Simulated dispatch failure.')
        ->and($attempt->finished_at)->not->toBeNull();

    Log::shouldHaveReceived('error')
        ->once()
        ->withArgs(fn (string $message) => $message === 'Generation dispatch failed.');

    // The lock is released even on the rethrow — a later claim can
    // acquire it again.
    expect(Cache::lock(QueueGenerationAttempt::claimLockKey(GenerationType::JobAnalysis, $jobPosting), 10)->acquire())
        ->toBeTrue();
});

it('claims and dispatches a resume_variant attempt for a JobMatch, atomically and exactly once', function () {
    Queue::fake();
    $match = JobMatch::factory()->create();

    $first = app(QueueGenerationAttempt::class)->claim(GenerationType::ResumeVariant, $match);
    $second = app(QueueGenerationAttempt::class)->claim(GenerationType::ResumeVariant, $match);

    expect($first)->not->toBeNull()
        ->and($second)->toBeNull()
        ->and(GenerationAttempt::count())->toBe(1);
    Queue::assertPushed(GenerateResumeVariantJob::class, 1);
});

it('claims a resume_pdf attempt for a ResumeVariant and dispatches the render job', function () {
    Queue::fake();
    $variant = ResumeVariant::factory()->create();

    $attempt = app(QueueGenerationAttempt::class)->claim(GenerationType::ResumePdf, $variant, 'run-1', chain: true);

    expect($attempt->subject_type)->toBe('resume_variant')
        ->and($attempt->pipeline_run_id)->toBe('run-1')
        ->and($attempt->chain)->toBeTrue();
    Queue::assertPushed(RenderResumePdfJob::class, 1);
});

it('does not let an active job_match attempt on the posting block a job_analysis claim', function () {
    Queue::fake();
    $jobPosting = JobPosting::factory()->create();

    // Synthetic: a job_match-typed active row pointing at a JobPosting
    // subject (in production a job_match attempt targets a JobAnalysis
    // row) — this proves the in-lock recheck is scoped by generation
    // type as well as subject.
    GenerationAttempt::factory()->create([
        'subject_type' => (new JobPosting)->getMorphClass(),
        'subject_id' => $jobPosting->id,
        'generation_type' => GenerationType::JobMatch,
        'status' => GenerationStatus::Running,
    ]);

    $claimed = app(QueueGenerationAttempt::class)
        ->claim(GenerationType::JobAnalysis, $jobPosting);

    expect($claimed)->not->toBeNull();
    expect($claimed->generation_type)->toBe(GenerationType::JobAnalysis);
    expect(GenerationAttempt::count())->toBe(2);
    Queue::assertPushed(GenerateJobAnalysisJob::class, 1);
});
