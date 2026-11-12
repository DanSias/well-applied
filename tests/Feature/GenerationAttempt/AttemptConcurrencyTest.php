<?php

use App\Enums\GenerationStatus;
use App\Enums\GenerationType;
use App\Jobs\GenerateResumeVariantJob;
use App\Jobs\RenderResumePdfJob;
use App\Models\GenerationAttempt;
use App\Models\ResumeVariant;
use App\Support\ResumeDocument\GenerateResumeDocument;
use App\Support\ResumeDocument\ResumePdfStore;
use App\Support\ResumeDocument\ResumePdfValidator;
use App\Support\TailoredResume\TailoredResumePipeline;
use Illuminate\Database\QueryException;
use Tests\Support\CompositionScenario;
use Tests\Support\PearlyResumeVariantFixture;
use Tests\Support\ScriptedPdfRenderer;

/**
 * A transient `database is locked` must never turn into two concurrent generations of the same job. The live failure: two
 * queue workers raced for one job row, the loser's lock error reached the job's failed() callback, the attempt was marked
 * failed while the winner was still running it, and a new start() then saw no work in flight.
 */
function lockError(): QueryException
{
    return new QueryException('sqlite', 'update "jobs" set "reserved_at" = 1 where "id" = 46', [], new PDOException('SQLSTATE[HY000]: General error: 5 database is locked'));
}

function pdfAttempt(GenerationStatus $status = GenerationStatus::Queued, array $extra = []): GenerationAttempt
{
    // One resume per test (the fixture creates a user); the database is reset between tests.
    $variant = ResumeVariant::query()->first() ?? PearlyResumeVariantFixture::generate();

    return GenerationAttempt::factory()->create([
        'subject_type' => 'resume_variant', 'subject_id' => $variant->id, 'generation_type' => GenerationType::ResumePdf,
        'status' => $status, 'queued_at' => now(), ...$extra,
    ]);
}

function runPdfJob(GenerationAttempt $attempt, ScriptedPdfRenderer $renderer): void
{
    (new RenderResumePdfJob($attempt))->handle($renderer, app(GenerateResumeDocument::class), app(ResumePdfStore::class), app(ResumePdfValidator::class));
}

function pdfRenderer(): ScriptedPdfRenderer
{
    return new ScriptedPdfRenderer(app(GenerateResumeDocument::class), [['pages' => 2, 'fill' => 0.7]]);
}

it('lets only one worker run an attempt, however many pop the same job', function () {
    $attempt = pdfAttempt();
    $renderer = pdfRenderer();

    runPdfJob($attempt, $renderer);
    // A second worker holding a copy of the same job finds it already finished and does nothing.
    runPdfJob(GenerationAttempt::find($attempt->id), $renderer);

    expect($renderer->calls)->toBe(1)->and($attempt->fresh()->status)->toBe(GenerationStatus::Succeeded);

    $running = pdfAttempt(GenerationStatus::Running, ['started_at' => now()]);
    $renderer2 = pdfRenderer();
    runPdfJob($running, $renderer2);

    expect($renderer2->calls)->toBe(0)->and($running->fresh()->status)->toBe(GenerationStatus::Running);
});

it('does not mark an attempt failed because the queue hit a lock while another worker runs it', function () {
    $attempt = pdfAttempt(GenerationStatus::Running, ['started_at' => now()]);

    (new RenderResumePdfJob($attempt))->failed(lockError());

    expect($attempt->fresh()->status)->toBe(GenerationStatus::Running)->and($attempt->fresh()->failure_category)->toBeNull();
});

it('still records a real failure, a lock on an attempt nobody is running, and never undoes a success', function () {
    $queued = pdfAttempt();
    (new RenderResumePdfJob($queued))->failed(lockError());
    expect($queued->fresh()->status)->toBe(GenerationStatus::Failed);

    $running = pdfAttempt(GenerationStatus::Running, ['started_at' => now()]);
    (new RenderResumePdfJob($running))->failed(new RuntimeException('boom'));
    expect($running->fresh()->status)->toBe(GenerationStatus::Failed);

    $done = pdfAttempt(GenerationStatus::Succeeded, ['finished_at' => now()]);
    (new RenderResumePdfJob($done))->failed(new RuntimeException('boom'));
    expect($done->fresh()->status)->toBe(GenerationStatus::Succeeded)->and($done->fresh()->failure_category)->toBeNull();
});

it('clears a failure recorded for a run that went on to succeed', function () {
    $attempt = pdfAttempt(extra: ['failure_category' => 'unexpected_error', 'failure_message' => 'stale']);

    runPdfJob($attempt, pdfRenderer());

    expect($attempt->fresh()->status)->toBe(GenerationStatus::Succeeded)->and($attempt->fresh()->failure_category)->toBeNull()->and($attempt->fresh()->failure_message)->toBeNull();
});

it('joins the generation in flight instead of starting a second one after a transient lock', function () {
    $s = CompositionScenario::create();
    $synthesis = CompositionScenario::approvedProfile($s);
    $pipeline = app(TailoredResumePipeline::class);

    $runId = $pipeline->start($s['posting'], true, false, $synthesis);
    $attempt = $pipeline->attemptsFor($s['posting'])->where('pipeline_run_id', $runId)->first();
    $attempt->update(['status' => GenerationStatus::Running, 'started_at' => now()]);

    // The queue loses a lock race for this job; the callback fires while the attempt is running elsewhere.
    (new GenerateResumeVariantJob($attempt))->failed(lockError());
    $again = $pipeline->start($s['posting'], true, false, $synthesis);

    expect($again)->toBe($runId)->and($pipeline->attemptsFor($s['posting'])->where('generation_type', GenerationType::ResumeVariant)->count())->toBe(1)
        ->and($attempt->fresh()->status)->toBe(GenerationStatus::Running);
});

it('makes a competing database writer wait instead of failing at once', function () {
    expect(config('database.connections.sqlite.busy_timeout'))->toBe(5000);
});

it('keeps the category and message of a real failure', function () {
    $running = pdfAttempt(GenerationStatus::Running, ['started_at' => now()]);
    $job = new GenerateResumeVariantJob($running);
    $job->failed(new RuntimeException('boom'));

    expect($running->fresh()->status)->toBe(GenerationStatus::Failed)->and($running->fresh()->failure_category)->toBe('unexpected_error')->and($running->fresh()->failure_message)->not->toBeNull();
});
