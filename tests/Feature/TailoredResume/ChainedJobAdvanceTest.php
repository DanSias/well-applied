<?php

use App\Contracts\GeneratesJobAnalysis;
use App\Enums\GenerationStatus;
use App\Enums\GenerationType;
use App\Jobs\GenerateJobAnalysisJob;
use App\Jobs\GenerateJobMatchJob;
use App\Models\GenerationAttempt;
use App\Models\JobPosting;
use App\Support\JobAnalysis\GenerateJobAnalysis;
use App\Support\JobAnalysis\JobAnalysisProviderResponse;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeJobAnalysisProvider;
use Tests\Support\JobAnalysisFixtures;

function chainedAnalysisAttempt(JobPosting $posting, bool $chain): GenerationAttempt
{
    return GenerationAttempt::factory()->create([
        'subject_type' => 'job_posting',
        'subject_id' => $posting->id,
        'generation_type' => GenerationType::JobAnalysis,
        'status' => GenerationStatus::Queued,
        'queued_at' => now()->subSeconds(7),
        'pipeline_run_id' => $chain ? 'run-x' : null,
        'chain' => $chain,
    ]);
}

function bindAnalysisWithUsage(): void
{
    $fake = new FakeJobAnalysisProvider;
    $fake->willReturn(new JobAnalysisProviderResponse(
        provider: 'ollama',
        model: 'qwen3.8:27b',
        structuredContent: JobAnalysisFixtures::validPayload(),
        usage: ['input_tokens' => 1200, 'output_tokens' => 300, 'reasoning_tokens' => 50],
    ));
    app()->instance(GeneratesJobAnalysis::class, $fake);
}

it('persists provider-neutral timing and token usage on a succeeded attempt', function () {
    Queue::fake();
    $posting = JobPosting::factory()->create(['description' => JobAnalysisFixtures::DESCRIPTION]);
    $attempt = chainedAnalysisAttempt($posting, chain: false);
    bindAnalysisWithUsage();

    (new GenerateJobAnalysisJob($attempt))->handle(app(GenerateJobAnalysis::class));

    $fresh = $attempt->fresh();
    expect($fresh->status)->toBe(GenerationStatus::Succeeded)
        ->and($fresh->prompt_tokens)->toBe(1200)
        ->and($fresh->completion_tokens)->toBe(300)
        ->and($fresh->reasoning_tokens)->toBe(50)
        ->and($fresh->total_tokens)->toBe(1500)
        ->and($fresh->provider_calls)->toBe(1)
        ->and($fresh->duration_ms)->toBeInt()
        ->and($fresh->queue_wait_ms)->toBeGreaterThanOrEqual(6000)
        ->and($fresh->provider)->toBe('ollama')
        ->and($fresh->model)->toBe('qwen3.8:27b');
});

it('leaves token fields null rather than zero when the provider reports no usage', function () {
    Queue::fake();
    $posting = JobPosting::factory()->create(['description' => JobAnalysisFixtures::DESCRIPTION]);
    $attempt = chainedAnalysisAttempt($posting, chain: false);
    $fake = new FakeJobAnalysisProvider;
    $fake->willReturn(new JobAnalysisProviderResponse('ollama', 'qwen3.8:27b', JobAnalysisFixtures::validPayload()));
    app()->instance(GeneratesJobAnalysis::class, $fake);

    (new GenerateJobAnalysisJob($attempt))->handle(app(GenerateJobAnalysis::class));

    expect($attempt->fresh()->prompt_tokens)->toBeNull()
        ->and($attempt->fresh()->reasoning_tokens)->toBeNull();
});

it('advances a chained run to the next stage when its job succeeds', function () {
    Queue::fake();
    $posting = JobPosting::factory()->create(['description' => JobAnalysisFixtures::DESCRIPTION]);
    $attempt = chainedAnalysisAttempt($posting, chain: true);
    bindAnalysisWithUsage();

    (new GenerateJobAnalysisJob($attempt))->handle(app(GenerateJobAnalysis::class));

    $next = GenerationAttempt::where('generation_type', GenerationType::JobMatch)->sole();
    expect($next->pipeline_run_id)->toBe('run-x')->and($next->chain)->toBeTrue();
    Queue::assertPushed(GenerateJobMatchJob::class, 1);
});

it('does not advance when the attempt was started manually', function () {
    Queue::fake();
    $posting = JobPosting::factory()->create(['description' => JobAnalysisFixtures::DESCRIPTION]);
    $attempt = chainedAnalysisAttempt($posting, chain: false);
    bindAnalysisWithUsage();

    (new GenerateJobAnalysisJob($attempt))->handle(app(GenerateJobAnalysis::class));

    Queue::assertNotPushed(GenerateJobMatchJob::class);
});
