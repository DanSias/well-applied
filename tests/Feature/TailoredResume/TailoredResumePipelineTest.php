<?php

use App\Enums\DescriptionCompleteness;
use App\Enums\GenerationStatus;
use App\Enums\GenerationType;
use App\Jobs\GenerateJobAnalysisJob;
use App\Jobs\GenerateJobMatchJob;
use App\Jobs\GenerateResumeVariantJob;
use App\Jobs\RenderResumePdfJob;
use App\Models\Application;
use App\Models\CareerProfile;
use App\Models\GenerationAttempt;
use App\Models\JobAnalysis;
use App\Models\JobMatch;
use App\Models\JobPosting;
use App\Models\ResumeSelectionCheckpoint;
use App\Models\ResumeVariant;
use App\Models\WorkflowRun;
use App\Support\ResumeDocument\GenerateResumeDocument;
use App\Support\ResumeDocument\ResumePdfStore;
use App\Support\TailoredResume\PresentTailoredResume;
use App\Support\TailoredResume\TailoredResumePipeline;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

/**
 * The one-click pipeline is a pure function of persisted state: these
 * tests build the persisted state each scenario needs and assert which
 * stage gets claimed, never running a provider.
 */
function pipelinePosting(array $attributes = []): JobPosting
{
    $profile = CareerProfile::query()->oldest('id')->first() ?? CareerProfile::factory()->create();

    return JobPosting::factory()->create(['career_profile_id' => $profile->id, 'description_completeness' => DescriptionCompleteness::Complete, ...$attributes]);
}

function validAnalysis(JobPosting $posting): JobAnalysis
{
    return JobAnalysis::factory()->create(['job_posting_id' => $posting->id, 'prompt_version' => 'job-analysis-v4']);
}

function validMatch(JobAnalysis $analysis): JobMatch
{
    return JobMatch::factory()->create([
        'job_analysis_id' => $analysis->id,
        'career_profile_id' => CareerProfile::query()->oldest('id')->first()->id,
        'prompt_version' => 'job-match-v3',
    ]);
}

function validVariant(JobMatch $match): ResumeVariant
{
    return ResumeVariant::factory()->create([
        'job_match_id' => $match->id,
        'career_profile_id' => $match->career_profile_id,
        'selection_prompt_version' => 'resume-selection-v6',
        'wording_prompt_version' => 'resume-wording-v3',
    ]);
}

function cachePdf(ResumeVariant $variant): void
{
    app(ResumePdfStore::class)->put(app(GenerateResumeDocument::class)->generate($variant), '%PDF-fake');
}

function claimedTypes(): array
{
    return GenerationAttempt::orderBy('id')->get()->map(fn ($a) => $a->generation_type->value)->all();
}

beforeEach(fn () => Queue::fake());

it('starts a fresh job at analysis and claims nothing else', function () {
    $posting = pipelinePosting();

    $runId = app(TailoredResumePipeline::class)->start($posting);

    $attempt = GenerationAttempt::sole();
    expect($attempt->generation_type)->toBe(GenerationType::JobAnalysis)
        ->and($attempt->pipeline_run_id)->toBe($runId)
        ->and($attempt->chain)->toBeTrue();
    Queue::assertPushed(GenerateJobAnalysisJob::class, 1);
    Queue::assertNotPushed(GenerateJobMatchJob::class);
});

it('is double-click safe: a second start joins the in-flight run and claims nothing new', function () {
    $posting = pipelinePosting();
    $pipeline = app(TailoredResumePipeline::class);

    $first = $pipeline->start($posting);
    $second = $pipeline->start($posting);

    expect($second)->toBe($first)
        ->and(GenerationAttempt::count())->toBe(1);
    Queue::assertPushed(GenerateJobAnalysisJob::class, 1);
});

it('reuses a valid analysis and goes straight to matching', function () {
    $posting = pipelinePosting();
    validAnalysis($posting);

    app(TailoredResumePipeline::class)->start($posting);

    expect(claimedTypes())->toBe(['job_match']);
});

it('reuses a valid analysis and match and goes straight to composing the resume', function () {
    $posting = pipelinePosting();
    validMatch(validAnalysis($posting));

    app(TailoredResumePipeline::class)->start($posting);

    expect(claimedTypes())->toBe(['resume_variant']);
    Queue::assertPushed(GenerateResumeVariantJob::class, 1);
});

it('re-analyzes when the stored analysis came from an older prompt version', function () {
    $posting = pipelinePosting();
    JobAnalysis::factory()->create(['job_posting_id' => $posting->id, 'prompt_version' => 'job-analysis-v1']);

    app(TailoredResumePipeline::class)->start($posting);

    expect(claimedTypes())->toBe(['job_analysis']);
});

it('advances to the next missing stage once a chained stage has produced its result', function () {
    $posting = pipelinePosting();
    $pipeline = app(TailoredResumePipeline::class);
    $runId = $pipeline->start($posting);

    GenerationAttempt::sole()->update(['status' => GenerationStatus::Succeeded]);
    validAnalysis($posting);
    $pipeline->advance($posting, $runId);

    expect(claimedTypes())->toBe(['job_analysis', 'job_match'])
        ->and(GenerationAttempt::where('generation_type', 'job_match')->sole()->pipeline_run_id)->toBe($runId);
});

it('does not advance while another attempt for the posting is still in flight', function () {
    $posting = pipelinePosting();
    $pipeline = app(TailoredResumePipeline::class);
    $runId = $pipeline->start($posting);
    validAnalysis($posting); // result exists, but the attempt row is still Queued/active

    $pipeline->advance($posting, $runId);

    expect(GenerationAttempt::count())->toBe(1);
});

it('renders the PDF when a valid variant exists but no PDF has been cached', function () {
    $posting = pipelinePosting();
    validVariant(validMatch(validAnalysis($posting)));

    app(TailoredResumePipeline::class)->start($posting);

    expect(claimedTypes())->toBe(['resume_pdf']);
    Queue::assertPushed(RenderResumePdfJob::class, 1);
});

it('claims nothing and reports ready when analysis, match, variant and PDF are all valid', function () {
    $posting = pipelinePosting();
    $variant = validVariant(validMatch(validAnalysis($posting)));
    cachePdf($variant);

    $pipeline = app(TailoredResumePipeline::class);
    $pipeline->start($posting);

    expect(GenerationAttempt::count())->toBe(0);
});

it('regenerates only the resume and PDF, reusing the valid analysis and match', function () {
    $posting = pipelinePosting();
    $old = validVariant($match = validMatch(validAnalysis($posting)));
    cachePdf($old);
    $pipeline = app(TailoredResumePipeline::class);

    $runId = $pipeline->start($posting, regenerate: true);

    expect(claimedTypes())->toBe(['resume_variant'])
        ->and(GenerationAttempt::sole()->regenerate)->toBeTrue();

    // The old variant must not satisfy the regenerating run; its own output must.
    $attempt = GenerationAttempt::sole();
    $new = validVariant($match);
    $attempt->update(['status' => GenerationStatus::Succeeded, 'result_id' => $new->id]);
    $pipeline->advance($posting, $runId);

    expect(claimedTypes())->toBe(['resume_variant', 'resume_pdf'])
        ->and(GenerationAttempt::where('generation_type', 'resume_pdf')->sole()->subject_id)->toBe($new->id);
});

it('resumes from the failed stage after a retry without redoing completed stages', function () {
    $posting = pipelinePosting();
    $pipeline = app(TailoredResumePipeline::class);

    $runId = $pipeline->start($posting); // claims analysis; pretend it ran, then match failed
    $analysis = validAnalysis($posting);
    GenerationAttempt::sole()->update(['status' => GenerationStatus::Succeeded, 'result_id' => $analysis->id]);
    $pipeline->advance($posting, $runId);
    GenerationAttempt::where('generation_type', 'job_match')->sole()->update([
        'status' => GenerationStatus::Failed, 'failure_category' => 'provider_error', 'failure_message' => 'Ollama request failed.',
    ]);

    $status = app(PresentTailoredResume::class)->present($posting);
    expect($status['state'])->toBe('failed')
        ->and($status['failure']['stage'])->toBe('match')
        ->and($status['failure']['message'])->toBe('Ollama request failed.');

    $retryRun = $pipeline->start($posting);

    expect(claimedTypes())->toBe(['job_analysis', 'job_match', 'job_match'])
        ->and(GenerationAttempt::where('generation_type', 'job_analysis')->count())->toBe(1);
    Queue::assertPushed(GenerateJobAnalysisJob::class, 1);
    Queue::assertPushed(GenerateJobMatchJob::class, 2);
    expect(app(PresentTailoredResume::class)->present($posting)['state'])->toBe('running');
});

it('requires explicit confirmation for a posting whose description completeness is not known to be complete', function () {
    $posting = pipelinePosting(['description_completeness' => DescriptionCompleteness::Unknown]);
    $pipeline = app(TailoredResumePipeline::class);

    expect(fn () => $pipeline->start($posting))->toThrow(ValidationException::class)
        ->and(GenerationAttempt::count())->toBe(0);

    $pipeline->start($posting, allowIncompleteDescription: true);

    expect(claimedTypes())->toBe(['job_analysis']);
});

it('never creates an Application or an inspection workflow', function () {
    $posting = pipelinePosting(['source_url' => 'https://dummy.invalid/job']);

    app(TailoredResumePipeline::class)->start($posting);

    expect(Application::count())->toBe(0)->and(WorkflowRun::count())->toBe(0);
});

it('leaves the manual stage actions untouched: a manual attempt has no run and does not chain', function () {
    $posting = pipelinePosting();

    $this->post(route('jobs.analyses.store', $posting));

    $attempt = GenerationAttempt::sole();
    expect($attempt->chain)->toBeFalse()->and($attempt->pipeline_run_id)->toBeNull();
});

// --- status ----------------------------------------------------------------

it('reports idle before anything has run', function () {
    $status = app(PresentTailoredResume::class)->present(pipelinePosting());

    expect($status['state'])->toBe('idle')->and($status['stages'])->toBe([]);
});

it('reports the current stage by its user-facing label, with elapsed time and no percentage', function () {
    $posting = pipelinePosting();
    validAnalysis($posting);
    app(TailoredResumePipeline::class)->start($posting);
    GenerationAttempt::sole()->update(['status' => GenerationStatus::Running, 'started_at' => now(), 'phase' => 'wording']);

    $status = app(PresentTailoredResume::class)->present($posting);

    expect($status['state'])->toBe('running')
        ->and($status['label'])->toBe('Matching career evidence')
        ->and($status['current_stage'])->toBe('match')
        ->and($status['elapsed_seconds'])->toBeInt()
        ->and(array_column($status['stages'], 'status'))->toBe(['reused', 'running', 'pending', 'pending'])
        ->and($status)->not->toHaveKey('percent')
        ->and($status)->not->toHaveKey('progress');
});

it('uses the matching labels for resume composition and PDF rendering', function () {
    $posting = pipelinePosting();
    $variant = validVariant(validMatch(validAnalysis($posting)));
    app(TailoredResumePipeline::class)->start($posting);
    expect(app(PresentTailoredResume::class)->present($posting)['label'])->toBe('Rendering and validating PDF');

    $other = pipelinePosting();
    validMatch(validAnalysis($other));
    app(TailoredResumePipeline::class)->start($other);
    expect(app(PresentTailoredResume::class)->present($other)['label'])->toBe('Composing resume');
});

it('points ready status at the newly generated variant, never a previously generated one', function () {
    $posting = pipelinePosting();
    $match = validMatch(validAnalysis($posting));
    $older = validVariant($match);
    cachePdf($older);
    $pipeline = app(TailoredResumePipeline::class);

    $runId = $pipeline->start($posting, regenerate: true);
    $new = validVariant($match);
    GenerationAttempt::sole()->update(['status' => GenerationStatus::Succeeded, 'result_id' => $new->id]);
    $pipeline->advance($posting, $runId);
    GenerationAttempt::where('generation_type', 'resume_pdf')->sole()->update(['status' => GenerationStatus::Succeeded, 'result_id' => $new->id]);
    cachePdf($new);

    $status = app(PresentTailoredResume::class)->present($posting);

    expect($status['state'])->toBe('ready')
        ->and($status['label'])->toBe('Ready for review')
        ->and($status['resume_variant_id'])->toBe($new->id)
        ->and($status['preview_url'])->toBe(route('resume-variants.preview', $new))
        ->and($status['preview_url'])->not->toBe(route('resume-variants.preview', $older));
});

it('exposes the unified status as JSON and starts the pipeline from the POST route', function () {
    $posting = pipelinePosting();

    $this->post(route('jobs.tailored-resume.store', $posting))->assertRedirect(route('jobs.show', $posting));
    $this->post(route('jobs.tailored-resume.store', $posting))->assertRedirect(route('jobs.show', $posting));

    expect(GenerationAttempt::count())->toBe(1);

    $this->getJson(route('jobs.tailored-resume.status', $posting))
        ->assertOk()
        ->assertJsonPath('state', 'running')
        ->assertJsonPath('label', 'Analyzing requirements')
        ->assertJsonPath('needs_confirmation', false);
});

it('rejects the POST for an unconfirmed incomplete description and accepts it once confirmed', function () {
    $posting = pipelinePosting(['description_completeness' => DescriptionCompleteness::Unknown]);

    $this->post(route('jobs.tailored-resume.store', $posting))->assertSessionHasErrors('allow_incomplete_description');
    expect(GenerationAttempt::count())->toBe(0);

    $this->post(route('jobs.tailored-resume.store', $posting), ['allow_incomplete_description' => true]);
    expect(GenerationAttempt::count())->toBe(1);
});

it('reports a failed resume stage with its phase and saved Selection, and a retry claims only a new resume attempt', function () {
    $posting = pipelinePosting();
    $match = validMatch(validAnalysis($posting));
    $pipeline = app(TailoredResumePipeline::class);
    $pipeline->start($posting);

    GenerationAttempt::where('generation_type', 'resume_variant')->sole()->update([
        'status' => GenerationStatus::Failed,
        'failure_category' => 'empty_model_output',
        'failure_message' => 'Resume Wording provider returned no final content after 3 attempts.',
        'phase' => 'wording',
        'provider_calls' => 4,
    ]);
    ResumeSelectionCheckpoint::create([
        'job_match_id' => $match->id, 'fingerprint' => str_repeat('a', 64), 'prompt_version' => 'resume-selection-v6',
        'schema_version' => '1', 'provider' => 'ollama', 'model' => 'qwen3.8:27b', 'structured_content' => ['x' => 1],
    ]);

    $status = app(PresentTailoredResume::class)->present($posting);

    expect($status['state'])->toBe('failed')
        ->and($status['failure'])->toMatchArray([
            'stage' => 'resume', 'category' => 'empty_model_output', 'phase' => 'wording', 'selection_saved' => true,
        ])
        ->and(collect($status['stages'])->firstWhere('key', 'resume'))->toMatchArray(['status' => 'failed', 'phase' => 'wording'])
        ->and(collect($status['stages'])->firstWhere('key', 'analysis')['status'])->toBe('reused')
        ->and(collect($status['stages'])->firstWhere('key', 'match')['status'])->toBe('reused');

    $pipeline->start($posting);

    expect(claimedTypes())->toBe(['resume_variant', 'resume_variant'])
        ->and(GenerationAttempt::where('generation_type', 'job_analysis')->count())->toBe(0)
        ->and(GenerationAttempt::where('generation_type', 'job_match')->count())->toBe(0)
        ->and(ResumeVariant::count())->toBe(0);
});

it('does not start a second resume attempt while one is in flight', function () {
    $posting = pipelinePosting();
    validMatch(validAnalysis($posting));
    $pipeline = app(TailoredResumePipeline::class);

    $pipeline->start($posting);
    $pipeline->start($posting);
    $pipeline->start($posting);

    expect(claimedTypes())->toBe(['resume_variant']);
});

it('is ready (not blocked) with a review report, listing only the claims that need a human look', function () {
    $posting = pipelinePosting();
    $match = validMatch(validAnalysis($posting));
    $pipeline = app(TailoredResumePipeline::class);

    $runId = $pipeline->start($posting, regenerate: true);
    $variant = ResumeVariant::factory()->create([
        'job_match_id' => $match->id,
        'career_profile_id' => $match->career_profile_id,
        'selection_prompt_version' => 'resume-selection-v6',
        'wording_prompt_version' => 'resume-wording-v3',
        'review_report' => [
            'version' => 1,
            'counts' => ['passed' => 5, 'review' => 1, 'generalized' => 1, 'omitted' => 1],
            'items' => [
                ['label' => 'A, bullet 1', 'status' => 'passed', 'findings' => [], 'evidence' => []],
                ['label' => 'A, bullet 2', 'status' => 'generalized', 'findings' => [], 'evidence' => [], 'action' => 'Removed the restricted figure [32000].'],
                ['label' => 'A, bullet 3', 'status' => 'omitted', 'findings' => [], 'evidence' => [], 'action' => 'Removed.'],
            ],
        ],
    ]);
    GenerationAttempt::sole()->update(['status' => GenerationStatus::Succeeded, 'result_id' => $variant->id]);
    $pipeline->advance($posting, $runId);
    GenerationAttempt::where('generation_type', 'resume_pdf')->sole()->update(['status' => GenerationStatus::Succeeded, 'result_id' => $variant->id]);
    cachePdf($variant);

    $status = app(PresentTailoredResume::class)->present($posting);

    expect($status['state'])->toBe('ready')
        ->and($status['review']['counts']['omitted'])->toBe(1)
        ->and(collect($status['review']['items'])->pluck('status')->all())->toBe(['generalized', 'omitted']);
});
