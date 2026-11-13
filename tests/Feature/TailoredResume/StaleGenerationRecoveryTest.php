<?php

use App\Enums\DescriptionCompleteness;
use App\Enums\GenerationStatus;
use App\Enums\GenerationType;
use App\Jobs\GenerateJobAnalysisJob;
use App\Jobs\GenerateJobMatchJob;
use App\Models\CareerProfile;
use App\Models\GenerationAttempt;
use App\Models\JobAnalysis;
use App\Models\JobPosting;
use App\Support\GenerationAttempt\StaleGenerations;
use App\Support\TailoredResume\PresentTailoredResume;
use App\Support\TailoredResume\TailoredResumePipeline;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;

beforeEach(fn () => Queue::fake());

function recoveryPosting(): JobPosting
{
    $profile = CareerProfile::query()->oldest('id')->first() ?? CareerProfile::factory()->create();

    return JobPosting::factory()->create(['career_profile_id' => $profile->id, 'description_completeness' => DescriptionCompleteness::Complete]);
}

function status(JobPosting $posting): array
{
    return app(PresentTailoredResume::class)->present($posting);
}

/** Starts a run, then makes its first attempt look as it would after the worker died `$silentFor` ago. */
function runWentQuiet(JobPosting $posting, string $as = 'running', int $silentForMinutes = 180, int $ranForMinutes = 10): GenerationAttempt
{
    app(TailoredResumePipeline::class)->start($posting);
    $attempt = GenerationAttempt::query()->latest('id')->firstOrFail();
    $silent = now()->subMinutes($silentForMinutes);

    GenerationAttempt::query()->whereKey($attempt->id)->update([
        'status' => $as === 'running' ? GenerationStatus::Running : GenerationStatus::Queued,
        'queued_at' => $silent->copy()->subMinutes($ranForMinutes),
        'started_at' => $as === 'running' ? $silent->copy()->subMinutes($ranForMinutes) : null,
        'updated_at' => $silent,
    ]);

    return $attempt->fresh();
}

describe('what counts as abandoned', function () {
    it('leaves a generation that is legitimately running alone, however long it has been going, while it reports', function () {
        $posting = recoveryPosting();
        $attempt = runWentQuiet($posting, silentForMinutes: 2);
        GenerationAttempt::query()->whereKey($attempt->id)->update(['queued_at' => now()->subHours(1), 'started_at' => now()->subHours(1)]);

        $s = status($posting);

        expect($s['state'])->toBe('running')
            ->and($s['stages'][0]['status'])->toBe('running')
            ->and($s['elapsed_seconds'])->toBeGreaterThan(3500)
            ->and(app(StaleGenerations::class)->recover())->toBe(0)
            ->and($attempt->fresh()->status)->toBe(GenerationStatus::Running);
    });

    it('treats a slow but recent run, up to the lease, as still alive', function () {
        $posting = recoveryPosting();
        runWentQuiet($posting, silentForMinutes: 100);

        expect(status($posting)['state'])->toBe('running')->and(app(StaleGenerations::class)->recover())->toBe(0);
    });

    it('calls a run silent past the queue lease interrupted, not running', function () {
        $posting = recoveryPosting();
        $attempt = runWentQuiet($posting, silentForMinutes: 130);

        $s = status($posting);

        expect($s['state'])->toBe('interrupted')
            ->and($s['label'])->toBe('Interrupted')
            ->and($s['stages'][0]['status'])->toBe('interrupted')
            ->and($s['current_stage'])->toBeNull()
            ->and($s['failure'])->toBeNull()
            ->and($s['interruption']['stage_label'])->toBe('Analyzing requirements')
            // Reading the status records nothing.
            ->and($attempt->fresh()->status)->toBe(GenerationStatus::Running);
    });

    it('follows the configured queue lease', function () {
        config(['queue.connections.'.config('queue.default').'.retry_after' => 600]);
        $posting = recoveryPosting();
        runWentQuiet($posting, silentForMinutes: 11);

        expect(status($posting)['state'])->toBe('interrupted');
    });

    it('also catches an attempt that was queued and never claimed by any worker', function () {
        $posting = recoveryPosting();
        $attempt = runWentQuiet($posting, as: 'queued', silentForMinutes: 200);

        expect(status($posting)['state'])->toBe('interrupted')->and(app(StaleGenerations::class)->recover())->toBe(1)
            ->and($attempt->fresh()->status)->toBe(GenerationStatus::Failed);
    });

    it('stops the elapsed time where the work stopped instead of counting on forever', function () {
        $posting = recoveryPosting();
        runWentQuiet($posting, silentForMinutes: 3000, ranForMinutes: 12);

        $s = status($posting);

        expect($s['state'])->toBe('interrupted')->and($s['elapsed_seconds'])->toBe(12 * 60);
    });
});

describe('recording the interruption', function () {
    it('turns an abandoned attempt into an ordinary failed one with its own category', function () {
        $posting = recoveryPosting();
        $attempt = runWentQuiet($posting);

        expect(app(StaleGenerations::class)->recover())->toBe(1);

        $attempt->refresh();
        expect($attempt->status)->toBe(GenerationStatus::Failed)
            ->and($attempt->failure_category)->toBe('interrupted')
            ->and($attempt->failure_message)->toBe(StaleGenerations::MESSAGE)
            ->and($attempt->finished_at)->not->toBeNull()
            ->and(status($posting)['state'])->toBe('interrupted');
    });

    it('is idempotent', function () {
        runWentQuiet(recoveryPosting());

        expect(app(StaleGenerations::class)->recover())->toBe(1)->and(app(StaleGenerations::class)->recover())->toBe(0);
    });

    it('never overwrites an attempt that finished before the sweep reached it', function () {
        $attempt = runWentQuiet(recoveryPosting());
        $ids = GenerationAttempt::query()->active()->pluck('id');
        expect($ids->all())->toBe([$attempt->id]);

        // It finishes just before the recording step runs.
        GenerationAttempt::query()->whereKey($attempt->id)->update(['status' => GenerationStatus::Succeeded]);

        expect(app(StaleGenerations::class)->recover())->toBe(0)->and($attempt->fresh()->status)->toBe(GenerationStatus::Succeeded);
    });

    it('does not touch attempts that are fine, failed for real, or finished', function () {
        $live = GenerationAttempt::factory()->create(['status' => GenerationStatus::Running, 'updated_at' => now()->subMinutes(5)]);
        $failed = GenerationAttempt::factory()->create(['status' => GenerationStatus::Failed, 'failure_category' => 'provider_error', 'updated_at' => now()->subDays(3)]);
        $done = GenerationAttempt::factory()->create(['status' => GenerationStatus::Succeeded, 'updated_at' => now()->subDays(3)]);

        expect(app(StaleGenerations::class)->recover())->toBe(0)
            ->and([$live->fresh()->status, $failed->fresh()->failure_category, $done->fresh()->status])->toBe([GenerationStatus::Running, 'provider_error', GenerationStatus::Succeeded]);
    });

    it('runs from the scheduled command', function () {
        $abandoned = runWentQuiet(recoveryPosting());
        $live = GenerationAttempt::factory()->create(['status' => GenerationStatus::Running, 'updated_at' => now()]);

        Artisan::call('generations:recover-stale');

        expect(Artisan::output())->toContain('Recorded 1')
            ->and($abandoned->fresh()->failure_category)->toBe('interrupted')
            ->and($live->fresh()->status)->toBe(GenerationStatus::Running);
    });
});

describe('continuing after an interruption', function () {
    it('lets the user continue, claiming one new attempt, and never two', function () {
        $posting = recoveryPosting();
        $stuck = runWentQuiet($posting);
        $pipeline = app(TailoredResumePipeline::class);

        $pipeline->start($posting);
        $pipeline->start($posting);

        expect(GenerationAttempt::count())->toBe(2)
            ->and($stuck->fresh()->failure_category)->toBe('interrupted')
            ->and(GenerationAttempt::query()->active()->count())->toBe(1);
        Queue::assertPushed(GenerateJobAnalysisJob::class, 2);
        expect(status($posting)['state'])->toBe('running');
    });

    it('keeps every finished stage, resuming at the stage that was lost', function () {
        $posting = recoveryPosting();
        $analysis = JobAnalysis::factory()->create(['job_posting_id' => $posting->id, 'prompt_version' => 'job-analysis-v4']);
        $pipeline = app(TailoredResumePipeline::class);
        $pipeline->start($posting);
        GenerationAttempt::query()->delete();
        // The match stage was running when the worker died.
        $lost = GenerationAttempt::factory()->create([
            'subject_type' => 'job_analysis', 'subject_id' => $analysis->id, 'generation_type' => GenerationType::JobMatch,
            'status' => GenerationStatus::Running, 'pipeline_run_id' => 'run-1', 'chain' => true,
            'queued_at' => now()->subHours(5), 'started_at' => now()->subHours(5), 'updated_at' => now()->subHours(4),
        ]);

        $s = status($posting);
        expect($s['state'])->toBe('interrupted')->and($s['interruption']['stage_label'])->toBe('Matching career evidence');

        Queue::fake();

        $pipeline->start($posting);

        $next = GenerationAttempt::query()->latest('id')->first();
        expect($next->generation_type)->toBe(GenerationType::JobMatch)
            ->and($next->status)->toBe(GenerationStatus::Queued)
            ->and($lost->fresh()->failure_category)->toBe('interrupted')
            ->and(JobAnalysis::find($analysis->id))->not->toBeNull()
            ->and(JobAnalysis::where('job_posting_id', $posting->id)->count())->toBe(1);
        Queue::assertPushed(GenerateJobMatchJob::class, 1);
        Queue::assertNotPushed(GenerateJobAnalysisJob::class, 1);
    });

    it('does not let a returning worker run an attempt that was recorded as interrupted', function () {
        $posting = recoveryPosting();
        $stuck = runWentQuiet($posting);
        app(StaleGenerations::class)->recover();
        $before = GenerationAttempt::count();

        // The old job wakes up, long after: it finds the attempt no longer queued and does nothing.
        app()->call([new GenerateJobAnalysisJob($stuck->fresh()), 'handle']);

        expect($stuck->fresh()->status)->toBe(GenerationStatus::Failed)->and($stuck->fresh()->failure_category)->toBe('interrupted')
            ->and(GenerationAttempt::count())->toBe($before)->and(JobAnalysis::count())->toBe(0);
    });

    it('cannot be claimed twice by two workers even if both pop the job', function () {
        $posting = recoveryPosting();
        app(TailoredResumePipeline::class)->start($posting);
        $attempt = GenerationAttempt::sole();

        $claim = fn () => GenerationAttempt::query()->whereKey($attempt->id)->where('status', GenerationStatus::Queued)->update(['status' => GenerationStatus::Running]);

        expect($claim() + $claim())->toBe(1);
    });
});

describe('a run that stalled between stages', function () {
    it('is running during the hand-over, then interrupted once it outlasts the grace period', function () {
        $posting = recoveryPosting();
        JobAnalysis::factory()->create(['job_posting_id' => $posting->id, 'prompt_version' => 'job-analysis-v4']);
        app(TailoredResumePipeline::class)->start($posting);
        GenerationAttempt::query()->delete();
        $finished = GenerationAttempt::factory()->create([
            'subject_type' => 'job_posting', 'subject_id' => $posting->id, 'generation_type' => GenerationType::JobAnalysis,
            'status' => GenerationStatus::Succeeded, 'pipeline_run_id' => 'run-2', 'chain' => true,
            'queued_at' => now()->subMinutes(10), 'finished_at' => now()->subSeconds(20), 'updated_at' => now()->subSeconds(20),
        ]);

        // Just finished, the next stage not queued yet: still the normal hand-over.
        $during = status($posting);
        expect($during['state'])->toBe('running')->and($during['label'])->toBe('Preparing job');

        GenerationAttempt::query()->whereKey($finished->id)->update(['queued_at' => now()->subMinutes(20), 'finished_at' => now()->subMinutes(10), 'updated_at' => now()->subMinutes(10)]);

        $after = status($posting);
        expect($after['state'])->toBe('interrupted')->and($after['label'])->toBe('Stopped before the next step')
            ->and($after['current_stage'])->toBeNull()
            ->and($after['elapsed_seconds'])->toBe(600);
    });

    it('is continued by starting again, which claims the stage that never started', function () {
        $posting = recoveryPosting();
        JobAnalysis::factory()->create(['job_posting_id' => $posting->id, 'prompt_version' => 'job-analysis-v4']);
        GenerationAttempt::factory()->create([
            'subject_type' => 'job_posting', 'subject_id' => $posting->id, 'generation_type' => GenerationType::JobAnalysis,
            'status' => GenerationStatus::Succeeded, 'pipeline_run_id' => 'run-3', 'chain' => true,
            'queued_at' => now()->subHour(), 'finished_at' => now()->subMinutes(50), 'updated_at' => now()->subMinutes(50),
        ]);

        expect(status($posting)['state'])->toBe('interrupted');

        app(TailoredResumePipeline::class)->start($posting);

        expect(GenerationAttempt::query()->active()->count())->toBe(1)->and(GenerationAttempt::query()->active()->first()->generation_type)->toBe(GenerationType::JobMatch);
    });
});

describe('failures and interruptions stay distinct', function () {
    it('reports a real failure as failed, with its message', function () {
        $posting = recoveryPosting();
        app(TailoredResumePipeline::class)->start($posting);
        GenerationAttempt::sole()->update(['status' => GenerationStatus::Failed, 'failure_category' => 'provider_error', 'failure_message' => 'Ollama request failed.', 'finished_at' => now()]);

        $s = status($posting);

        expect($s['state'])->toBe('failed')->and($s['failure']['message'])->toBe('Ollama request failed.')->and($s['interruption'])->toBeNull();
    });

    it('reports a recorded interruption as interrupted, not failed', function () {
        $posting = recoveryPosting();
        runWentQuiet($posting);
        app(StaleGenerations::class)->recover();

        $s = status($posting);

        expect($s['state'])->toBe('interrupted')->and($s['failure'])->toBeNull()->and($s['stages'][0]['status'])->toBe('interrupted');
    });

    it('serves the same status over the polling endpoint', function () {
        $posting = recoveryPosting();
        runWentQuiet($posting);

        $this->getJson(route('jobs.tailored-resume.status', $posting))->assertOk()->assertJsonPath('state', 'interrupted');
    });
});
