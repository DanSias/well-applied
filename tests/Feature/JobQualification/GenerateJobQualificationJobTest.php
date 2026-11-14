<?php

use App\Enums\DescriptionCompleteness;
use App\Enums\GenerationStatus;
use App\Enums\GenerationType;
use App\Enums\JobDisposition;
use App\Enums\QualificationEligibility;
use App\Jobs\GenerateJobQualificationJob;
use App\Models\GenerationAttempt;
use App\Models\JobPosting;
use App\Models\JobQualification;
use App\Support\JobQualification\GenerateJobQualification;
use Tests\Support\QualificationFixtures as Q;

/**
 * The attempt lifecycle around the qualification service, run synchronously the way a queue worker would. A failure ends as
 * a failed attempt with a safe category, writes no qualification, and never touches the job's disposition.
 */
function qualificationAttempt(JobPosting $job, bool $regenerate = false): GenerationAttempt
{
    return GenerationAttempt::factory()->create([
        'subject_type' => (new JobPosting)->getMorphClass(), 'subject_id' => $job->id,
        'generation_type' => GenerationType::JobQualification, 'status' => GenerationStatus::Queued, 'regenerate' => $regenerate,
    ]);
}

function runQualification(GenerationAttempt $attempt): GenerationAttempt
{
    (new GenerateJobQualificationJob($attempt))->handle(app(GenerateJobQualification::class));

    return $attempt->fresh();
}

it('runs queued to succeeded and links the result', function () {
    $job = Q::job(Q::candidate(), Q::laravel());
    Q::fake()->willReturn(Q::answer());

    $attempt = runQualification(qualificationAttempt($job));

    $q = JobQualification::query()->sole();
    expect($attempt->status)->toBe(GenerationStatus::Succeeded)->and($attempt->result_id)->toBe($q->id)->and($attempt->result()?->is($q))->toBeTrue()
        ->and($attempt->started_at)->not->toBeNull()->and($attempt->finished_at)->not->toBeNull()
        ->and($attempt->provider)->toBe('ollama')->and($attempt->model)->toBe('qwen-test')->and($attempt->prompt_version)->toBe('job-qualification-v1')
        ->and($attempt->failure_message)->toBeNull();
});

it('records a deterministic result (no model) as a succeeded attempt too', function () {
    $job = Q::job(Q::candidate(), Q::laravel(), ['description_completeness' => DescriptionCompleteness::Preview]);
    $fake = Q::fake()->willReturn(Q::answer());

    $attempt = runQualification(qualificationAttempt($job));

    expect($attempt->status)->toBe(GenerationStatus::Succeeded)->and($attempt->provider)->toBeNull()->and($fake->callCount)->toBe(0)
        ->and(JobQualification::query()->sole()->eligibility)->toBe(QualificationEligibility::InsufficientInformation);
});

it('ends a provider failure as a failed attempt with no result and an untouched job', function () {
    $job = Q::job(Q::candidate(), Q::laravel());
    Q::fake()->willFail('Ollama request timed out.');

    $attempt = runQualification(qualificationAttempt($job));

    expect($attempt->status)->toBe(GenerationStatus::Failed)->and($attempt->failure_category)->toBe('provider_error')
        ->and($attempt->failure_message)->toContain('timed out')->and($attempt->result_id)->toBeNull()
        ->and(JobQualification::query()->count())->toBe(0)->and($job->fresh()->disposition)->toBe(JobDisposition::New);
});

it('ends an off-contract response as a failed validation, not a crash', function () {
    $job = Q::job(Q::candidate(), Q::laravel());
    Q::fake()->willReturn(Q::answer(['verdict' => 'amazing']));

    $attempt = runQualification(qualificationAttempt($job));

    expect($attempt->status)->toBe(GenerationStatus::Failed)->and($attempt->failure_category)->toBe('validation_error')
        ->and(JobQualification::query()->count())->toBe(0)->and($job->fresh()->disposition)->toBe(JobDisposition::New);
});

it('fails the attempt rather than leaving it running when something unexpected is thrown', function () {
    $job = Q::job(Q::candidate(), Q::laravel());
    $attempt = qualificationAttempt($job);

    (new GenerateJobQualificationJob($attempt))->failed(new RuntimeException('boom'));

    expect($attempt->fresh()->status)->toBe(GenerationStatus::Failed)->and($attempt->fresh()->failure_category)->toBe('unexpected_error');
});

it('is claimed once: a second worker for the same attempt does nothing', function () {
    $job = Q::job(Q::candidate(), Q::laravel());
    $fake = Q::fake()->willReturn(Q::answer());
    $attempt = qualificationAttempt($job);

    runQualification($attempt);
    runQualification($attempt);

    expect($fake->callCount)->toBe(1)->and(JobQualification::query()->count())->toBe(1);
});

it('honours an explicit refresh recorded on the attempt', function () {
    $job = Q::job(Q::candidate(), Q::laravel());
    $fake = Q::fake()->willReturn(Q::answer());

    runQualification(qualificationAttempt($job));
    runQualification(qualificationAttempt($job));
    expect($fake->callCount)->toBe(1);

    runQualification(qualificationAttempt($job, regenerate: true));
    expect($fake->callCount)->toBe(2)->and(JobQualification::query()->count())->toBe(2);
});
