<?php

use App\Enums\ClarificationSessionStatus;
use App\Enums\GenerationType;
use App\Jobs\GenerateJobMatchJob;
use App\Jobs\GenerateResumeVariantJob;
use App\Models\CareerFact;
use App\Models\ClarificationQuestion;
use App\Models\ClarificationSession;
use App\Models\GenerationAttempt;
use App\Models\JobMatch;
use App\Models\ResumeVariant;
use App\Support\Clarification\EvidenceRevision;
use App\Support\Clarification\ReviewEvidenceProposal;
use App\Support\Clarification\SubmitClarificationAnswer;
use App\Support\ResumeDocument\GenerateResumeDocument;
use App\Support\ResumeDocument\ResumePdfStore;
use App\Support\TailoredResume\PresentTailoredResume;
use App\Support\TailoredResume\TailoredResumePipeline;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ClarificationFixtures;

beforeEach(fn () => Queue::fake());

function claimed(): array
{
    return GenerationAttempt::orderBy('id')->get()->map(fn ($a) => $a->generation_type->value)->all();
}

function approveWebhookEvidence(array $s, string $visibility = 'public'): void
{
    $question = ClarificationQuestion::where('topic_key', 'webhooks')->firstOrFail();
    $proposal = app(SubmitClarificationAnswer::class)->submit($question, 'experience', ['configured', 'advised'], 'At RocketGate, I worked with merchant integrations involving webhooks for retry schedules.');
    app(ReviewEvidenceProposal::class)->approve($proposal, ['visibility' => $visibility]);
}

it('holds the run for questions after a reused analysis and match, and claims nothing', function () {
    $s = ClarificationFixtures::scenario();

    $runId = app(TailoredResumePipeline::class)->start($s['posting']);

    $status = app(PresentTailoredResume::class)->present($s['posting']);
    expect(GenerationAttempt::count())->toBe(0)
        ->and($status['state'])->toBe('needs_clarification')
        ->and($status['run_id'])->toBe($runId)
        ->and(collect($status['stages'])->pluck('status')->all())->toBe(['reused', 'reused', 'pending', 'pending'])
        ->and(collect($status['clarification']['questions'])->pluck('topic_key')->all())->toBe(['webhooks', 'recurring_billing'])
        ->and($status['clarification']['can_deepen'])->toBeTrue();
    Queue::assertNothingPushed();
});

it('Generate Now continues the same run to the resume stage with no evidence added', function () {
    $s = ClarificationFixtures::scenario();
    $facts = CareerFact::count();
    $runId = app(TailoredResumePipeline::class)->start($s['posting']);
    $session = ClarificationSession::sole();

    app(TailoredResumePipeline::class)->continueAfterClarification($s['posting'], $session, ClarificationSessionStatus::Skipped);

    $attempt = GenerationAttempt::sole();
    expect($attempt->generation_type)->toBe(GenerationType::ResumeVariant)
        ->and($attempt->pipeline_run_id)->toBe($runId)
        ->and($session->fresh()->status)->toBe(ClarificationSessionStatus::Skipped)
        ->and(CareerFact::count())->toBe($facts);
    Queue::assertPushed(GenerateResumeVariantJob::class, 1);
    Queue::assertNotPushed(GenerateJobMatchJob::class);
});

it('is double-click safe while held: a second start joins the held run and changes nothing', function () {
    $s = ClarificationFixtures::scenario();
    $pipeline = app(TailoredResumePipeline::class);

    $first = $pipeline->start($s['posting']);
    $second = $pipeline->start($s['posting']);

    expect($second)->toBe($first)->and(ClarificationSession::count())->toBe(1)->and(GenerationAttempt::count())->toBe(0);
});

it('shows the same questions and proposals after a reload', function () {
    $s = ClarificationFixtures::scenario();
    app(TailoredResumePipeline::class)->start($s['posting']);
    $question = ClarificationQuestion::where('topic_key', 'webhooks')->firstOrFail();
    app(SubmitClarificationAnswer::class)->submit($question, 'experience', ['configured'], 'Configured webhooks for retry schedules.');

    $before = app(PresentTailoredResume::class)->present($s['posting'])['clarification'];
    $page = $this->get(route('jobs.show', $s['posting']))->assertOk()->viewData('page')['props']['tailored_resume']['clarification'];

    expect($page['questions'][0]['proposal']['statement'])->toBe('Configured webhooks for retry schedules.')
        ->and(collect($page['questions'])->pluck('id')->all())->toBe(collect($before['questions'])->pluck('id')->all());
});

it('does not hold a regeneration for questions', function () {
    $s = ClarificationFixtures::scenario();

    app(TailoredResumePipeline::class)->start($s['posting'], regenerate: true);

    expect(claimed())->toBe(['resume_variant'])->and(ClarificationSession::count())->toBe(0);
});

it('does not hold a job with nothing worth asking', function () {
    $s = ClarificationFixtures::scenario();
    $s['match']->findings()->update(['coverage' => 'supported']);

    app(TailoredResumePipeline::class)->start($s['posting']);

    expect(claimed())->toBe(['resume_variant'])
        ->and(ClarificationSession::sole()->status)->toBe(ClarificationSessionStatus::NotNeeded);
});

it('treats the match as stale once evidence is approved, and re-matches before Selection', function () {
    $s = ClarificationFixtures::scenario();
    $pipeline = app(TailoredResumePipeline::class);
    $pipeline->start($s['posting']);
    approveWebhookEvidence($s);

    expect($pipeline->inspect($s['posting'])['match'])->toBeNull()
        ->and($pipeline->inspect($s['posting'])['evidence_stale'])->toBeTrue();

    $pipeline->continueAfterClarification($s['posting'], ClarificationSession::sole(), ClarificationSessionStatus::Completed);

    // The old match saw a corpus without the new fact: matching runs again, not Selection.
    expect(claimed())->toBe(['job_match']);
    Queue::assertPushed(GenerateJobMatchJob::class, 1);
    Queue::assertNotPushed(GenerateResumeVariantJob::class);
    expect(JobMatch::count())->toBe(1);
});

it('continues to Selection without asking again once the fresh match exists', function () {
    $s = ClarificationFixtures::scenario();
    $pipeline = app(TailoredResumePipeline::class);
    $runId = $pipeline->start($s['posting']);
    approveWebhookEvidence($s);
    $pipeline->continueAfterClarification($s['posting'], ClarificationSession::sole(), ClarificationSessionStatus::Completed);

    // The match job finishes: a new match stamped at the current revision.
    JobMatch::factory()->create([
        'job_analysis_id' => $s['analysis']->id, 'career_profile_id' => $s['profile']->id, 'prompt_version' => 'job-match-v3',
        'evidence_revision' => EvidenceRevision::current($s['profile']),
    ]);
    GenerationAttempt::query()->update(['status' => 'succeeded']);
    $pipeline->advance($s['posting'], $runId);

    expect(claimed())->toBe(['job_match', 'resume_variant'])->and(ClarificationSession::count())->toBe(1);
});

it('keeps a match reusable when nothing about the evidence changed (skip path unaffected)', function () {
    $s = ClarificationFixtures::scenario();
    $pipeline = app(TailoredResumePipeline::class);
    $pipeline->start($s['posting']);
    $pipeline->continueAfterClarification($s['posting'], ClarificationSession::sole(), ClarificationSessionStatus::Skipped);

    expect($pipeline->inspect($s['posting'])['match']?->id)->toBe($s['match']->id)->and(claimed())->toBe(['resume_variant']);
});

it('stamps new matches with the revision read before the corpus was built', function () {
    $s = ClarificationFixtures::scenario();
    EvidenceRevision::bump($s['profile']);

    expect(EvidenceRevision::current($s['profile']))->toBe(1)
        ->and(app(TailoredResumePipeline::class)->inspect($s['posting'])['match'])->toBeNull();
});

it('reports a finished run as needing a fresh generation once evidence changed, instead of a stuck spinner', function () {
    $s = ClarificationFixtures::scenario();
    GenerationAttempt::factory()->create([
        'subject_type' => 'job_match', 'subject_id' => $s['match']->id, 'generation_type' => GenerationType::JobMatch,
        'status' => 'succeeded', 'pipeline_run_id' => 'old-run', 'chain' => true,
    ]);
    EvidenceRevision::bump($s['profile']);

    $status = app(PresentTailoredResume::class)->present($s['posting']);

    expect($status['state'])->toBe('idle')->and($status['evidence_changed'])->toBeTrue();
});

it('deeper discovery adds the lower-value questions without duplicating answered ones', function () {
    $s = ClarificationFixtures::scenario();
    app(TailoredResumePipeline::class)->start($s['posting']);
    $session = ClarificationSession::sole();
    $answered = $session->questions->firstWhere('topic_key', 'webhooks');
    app(SubmitClarificationAnswer::class)->submit($answered, 'experience', ['configured'], 'Configured webhooks.');

    $this->post(route('jobs.clarification.deepen', $s['posting']))->assertRedirect();

    $topics = $session->fresh()->questions->pluck('topic_key')->all();
    expect($session->fresh()->mode)->toBe('deep')
        ->and($topics)->toBe(['webhooks', 'recurring_billing', 'ruby_on_rails'])
        ->and($answered->fresh()->status)->toBe('answered');
});

function finishedResume(array $s): ResumeVariant
{
    $variant = ResumeVariant::factory()->create([
        'job_match_id' => $s['match']->id,
        'career_profile_id' => $s['profile']->id,
        'selection_prompt_version' => 'resume-selection-v6',
        'wording_prompt_version' => 'resume-wording-v3',
    ]);
    app(ResumePdfStore::class)->put(app(GenerateResumeDocument::class)->generate($variant), '%PDF-fake');

    return $variant;
}

it('lets the user ask for questions on a finished resume, and keeps the resume if nothing is approved', function () {
    $s = ClarificationFixtures::scenario();
    $variant = finishedResume($s);

    expect(app(PresentTailoredResume::class)->present($s['posting']))->toMatchArray(['state' => 'idle']);

    $this->post(route('jobs.clarification.start', $s['posting']))->assertRedirect();

    $status = app(PresentTailoredResume::class)->present($s['posting']);
    expect($status['state'])->toBe('needs_clarification')
        ->and($status['clarification']['resume_ready'])->toBeTrue()
        ->and($status['clarification']['questions'])->toHaveCount(2);

    $this->post(route('jobs.clarification.continue', $s['posting']))->assertRedirect();

    // Nothing was approved, so nothing is regenerated and the resume stays.
    expect(GenerationAttempt::count())->toBe(0)
        ->and(app(PresentTailoredResume::class)->present($s['posting'])['state'])->toBe('idle')
        ->and(app(TailoredResumePipeline::class)->inspect($s['posting'])['variant']?->id)->toBe($variant->id);
});

it('regenerates from the fresh evidence when questions asked on a finished resume were approved', function () {
    $s = ClarificationFixtures::scenario();
    finishedResume($s);
    $this->post(route('jobs.clarification.start', $s['posting']))->assertRedirect();
    approveWebhookEvidence($s);

    $this->post(route('jobs.clarification.continue', $s['posting']))->assertRedirect();

    expect(claimed())->toBe(['job_match']);
});

it('says so instead of opening an empty stage when nothing is worth asking', function () {
    $s = ClarificationFixtures::scenario();
    $s['match']->findings()->update(['coverage' => 'supported']);

    $this->post(route('jobs.clarification.start', $s['posting']))->assertSessionHasErrors('clarification');
    expect(ClarificationSession::count())->toBe(0);
});

it('can open deeper discovery directly', function () {
    $s = ClarificationFixtures::scenario();

    $this->post(route('jobs.clarification.start', $s['posting']), ['mode' => 'deep'])->assertRedirect();

    expect(ClarificationSession::sole()->questions->pluck('topic_key')->all())->toBe(['webhooks', 'recurring_billing', 'ruby_on_rails']);
});
