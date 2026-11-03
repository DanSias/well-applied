<?php

use App\Contracts\GeneratesResumeSelection;
use App\Contracts\GeneratesResumeWording;
use App\Enums\ClarificationSessionStatus;
use App\Enums\DescriptionCompleteness;
use App\Enums\GenerationStatus;
use App\Enums\GenerationType;
use App\Enums\Visibility;
use App\Jobs\GenerateResumeVariantJob;
use App\Models\CareerFact;
use App\Models\ClarificationQuestion;
use App\Models\ClarificationSession;
use App\Models\GenerationAttempt;
use App\Models\JobMatch;
use App\Models\PresentationPreference;
use App\Models\ResumeVariant;
use App\Support\Clarification\ClarificationGate;
use App\Support\Clarification\ReviewEvidenceProposal;
use App\Support\Clarification\SubmitClarificationAnswer;
use App\Support\ResumeVariant\GenerateResumeVariant;
use App\Support\ResumeVariant\ResumeSelectionProviderResponse;
use App\Support\ResumeVariant\ResumeWordingProviderResponse;
use App\Support\TailoredResume\TailoredResumePipeline;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeResumeSelectionProvider;
use Tests\Support\FakeResumeWordingProvider;
use Tests\Support\ResumeVariantFixtures;

/**
 * End to end with fake providers: the clarification stage sits in front of
 * the real resume job, so these run the real GenerateResumeVariantJob.
 *
 * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: JobMatch, 3: FakeResumeSelectionProvider, 4: FakeResumeWordingProvider}
 */
function clarificationGenerationSetup(): array
{
    $candidate = ResumeVariantFixtures::candidate();
    $job = ResumeVariantFixtures::job();
    $match = ResumeVariantFixtures::jobMatch(
        $candidate['profile'], $job['analysis'], $job['azureFinding'], $job['ownershipFinding'],
        $candidate['factAws'], $candidate['factIndependent'],
    );
    // Snapshots are immutable by model; the pipeline only reuses current-prompt ones.
    DB::table('job_matches')->where('id', $match->id)->update(['prompt_version' => 'job-match-v3']);
    DB::table('job_analyses')->where('id', $job['analysis']->id)->update(['prompt_version' => 'job-analysis-v4']);
    $match = $match->fresh();

    $selection = new FakeResumeSelectionProvider;
    $wording = new FakeResumeWordingProvider;
    app()->instance(GeneratesResumeSelection::class, $selection);
    app()->instance(GeneratesResumeWording::class, $wording);

    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'gpt-test', [
        'summary_evidence' => [$candidate['factIndependent']->key],
        'skills' => [],
        'experience' => [[
            'role_id' => $candidate['role']->id,
            'title_choice' => 'full',
            'bullet_groups' => [[
                'project_id' => -1, 'order' => 1,
                'career_fact_keys' => [$candidate['factIndependent']->key],
                'job_analysis_finding_ids' => [$job['ownershipFinding']->id],
            ]],
        ]],
        'selected_projects' => [],
        'target_term_usages' => [],
    ]));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'gpt-test', [
        'summary' => 'Senior engineer.',
        'experience' => [[
            'role_id' => $candidate['role']->id,
            'bullets' => [['bullet_group_index' => 0, 'text' => 'Independently implemented the technical solution from team-provided requirements.']],
        ]],
        'selected_projects' => [],
    ]));

    return [$candidate, $job, $match, $selection, $wording];
}

function holdRun(JobMatch $match): ClarificationSession
{
    $posting = $match->jobAnalysis->jobPosting;
    DB::table('job_postings')->where('id', $posting->id)->update([
        'description_completeness' => DescriptionCompleteness::Complete->value,
        'career_profile_id' => $match->career_profile_id,
    ]);

    return ClarificationSession::create([
        'job_posting_id' => $posting->id, 'job_analysis_id' => $match->job_analysis_id, 'job_match_id' => $match->id,
        'pipeline_run_id' => 'run-held', 'mode' => 'standard', 'status' => ClarificationSessionStatus::Pending,
    ]);
}

function runClaimedResumeAttempt(): ResumeVariant
{
    $attempt = GenerationAttempt::query()->where('generation_type', GenerationType::ResumeVariant)->latest('id')->firstOrFail();
    (new GenerateResumeVariantJob($attempt))->handle(app(GenerateResumeVariant::class));

    expect($attempt->fresh()->status)->toBe(GenerationStatus::Succeeded);

    return ResumeVariant::query()->latest('id')->firstOrFail();
}

it('produces a resume when every question is skipped (Generate Now), using the unchanged evidence', function () {
    Queue::fake();
    [, , $match, $selection] = clarificationGenerationSetup();
    $session = holdRun($match);

    app(TailoredResumePipeline::class)->continueAfterClarification($match->jobAnalysis->jobPosting, $session, ClarificationSessionStatus::Skipped);
    $variant = runClaimedResumeAttempt();

    expect($variant->job_match_id)->toBe($match->id)
        ->and($variant->experienceBullets)->toHaveCount(1)
        ->and($selection->callCount)->toBe(1)
        ->and($session->fresh()->status)->toBe(ClarificationSessionStatus::Skipped);
});

it('hands Selection the newly approved evidence, so the next resume is built from the current corpus', function () {
    Queue::fake();
    [$candidate, $job, $match, $selection] = clarificationGenerationSetup();
    $session = holdRun($match);
    $gate = app(ClarificationGate::class);

    $question = ClarificationQuestion::create([
        'clarification_session_id' => $session->id, 'topic_key' => 'webhooks', 'topic_label' => 'Webhooks and event-driven integrations',
        'job_analysis_finding_id' => $job['ownershipFinding']->id, 'related_finding_ids' => [], 'question_text' => 'Q', 'score' => 0.7,
        'score_breakdown' => [], 'context' => [], 'suggested_attributable_type' => 'role', 'suggested_attributable_id' => $candidate['role']->id,
        'suggested_visibility' => 'restricted', 'status' => 'open',
    ]);
    $proposal = app(SubmitClarificationAnswer::class)->submit($question, 'experience', ['configured', 'advised'], 'Worked with merchant integrations involving webhooks for retry schedules.');
    $approved = app(ReviewEvidenceProposal::class)->approve($proposal, ['visibility' => 'public']);

    app(TailoredResumePipeline::class)->continueAfterClarification($match->jobAnalysis->jobPosting, $session, ClarificationSessionStatus::Completed);

    // Evidence changed, so the pipeline re-matches first and does not reuse the stale match.
    expect(GenerationAttempt::query()->latest('id')->first()->generation_type)->toBe(GenerationType::JobMatch);

    // Selection, when it runs against a fresh match, sees the approved fact.
    $attempt = GenerationAttempt::factory()->create([
        'subject_type' => 'job_match', 'subject_id' => $match->id, 'generation_type' => GenerationType::ResumeVariant, 'status' => GenerationStatus::Queued,
    ]);
    (new GenerateResumeVariantJob($attempt))->handle(app(GenerateResumeVariant::class));

    expect($selection->capturedUserPrompt)->toContain($approved->careerFact->key)
        ->and($selection->capturedUserPrompt)->toContain('Worked with merchant integrations involving webhooks for retry schedules.');
    expect($gate->sessionFor($match->jobAnalysis->jobPosting, $match->jobAnalysis)->status)->toBe(ClarificationSessionStatus::Completed);
});

it('changes the Selection input when evidence is approved, so an earlier checkpoint is not reused', function () {
    Queue::fake();
    [$candidate, , $match, $selection] = clarificationGenerationSetup();

    app(GenerateResumeVariant::class)->generateFull($match);
    $firstPrompt = $selection->capturedUserPrompt;

    CareerFact::factory()->create([
        'career_profile_id' => $candidate['profile']->id, 'key' => 'new-approved-fact', 'statement' => 'A newly approved statement.',
        'visibility' => Visibility::Public, 'attributable_type' => 'role', 'attributable_id' => $candidate['role']->id,
    ]);
    app(GenerateResumeVariant::class)->generateFull($match);

    // The checkpoint fingerprint hashes the exact prompt Selection saw
    // (see GenerateResumeVariant), so a different prompt can never match it.
    expect($selection->capturedUserPrompt)->not->toBe($firstPrompt)
        ->and($selection->capturedUserPrompt)->toContain('new-approved-fact')
        ->and($firstPrompt)->not->toContain('new-approved-fact');
});

const MARKETING_PREFERENCE = 'Marketing and conversion experience may be emphasized where relevant.';

function addMarketingPreference(int $profileId): PresentationPreference
{
    return PresentationPreference::create([
        'career_profile_id' => $profileId, 'topic_key' => 'conversion_funnels', 'topic' => 'Conversion funnels',
        'guidance' => MARKETING_PREFERENCE, 'confirmed_at' => now(),
    ]);
}

it('hands an approved preference to Selection as emphasis guidance with its limits stated', function () {
    Queue::fake();
    [$candidate, , $match, $selection] = clarificationGenerationSetup();
    addMarketingPreference($candidate['profile']->id);

    app(GenerateResumeVariant::class)->generateFull($match);

    expect($selection->capturedUserPrompt)->toContain('Presentation preferences (emphasis guidance only)')
        ->and($selection->capturedUserPrompt)->toContain(MARKETING_PREFERENCE)
        ->and($selection->capturedUserPrompt)->toContain('conversion_funnels')
        ->and($selection->capturedUserPrompt)->toContain('They are not facts and add no evidence')
        ->and($selection->capturedUserPrompt)->toContain('Job relevance always comes first')
        ->and($selection->capturedUserPrompt)->toContain('Never cite a preference as evidence');
});

it('leaves the Selection prompt untouched when there is no active preference', function () {
    Queue::fake();
    [$candidate, , $match, $selection] = clarificationGenerationSetup();

    app(GenerateResumeVariant::class)->generateFull($match);
    $without = $selection->capturedUserPrompt;

    expect($without)->not->toContain('Presentation preferences');

    $preference = addMarketingPreference($candidate['profile']->id);
    app(GenerateResumeVariant::class)->generateFull($match);
    $with = $selection->capturedUserPrompt;

    // A revoked preference is gone again, and the prompt is byte-identical to the original.
    $preference->update(['revoked_at' => now()]);
    app(GenerateResumeVariant::class)->generateFull($match);

    expect($with)->not->toBe($without)->and($selection->capturedUserPrompt)->toBe($without);
});

it('changes the Selection fingerprint when a preference is added, so a stale checkpoint cannot be reused', function () {
    Queue::fake();
    [$candidate, , $match, $selection] = clarificationGenerationSetup();

    app(GenerateResumeVariant::class)->generateFull($match);
    expect($selection->callCount)->toBe(1);

    addMarketingPreference($candidate['profile']->id);
    app(GenerateResumeVariant::class)->generateFull($match);

    // A reused checkpoint would not call the provider again.
    expect($selection->callCount)->toBe(2);
});

it('never turns a preference into a fact: it stays out of Wording, the candidate corpus and CareerFacts', function () {
    Queue::fake();
    [$candidate, , $match, $selection, $wording] = clarificationGenerationSetup();
    addMarketingPreference($candidate['profile']->id);
    $facts = CareerFact::count();

    $variant = app(GenerateResumeVariant::class)->generateFull($match);

    expect($wording->capturedUserPrompt)->not->toContain(MARKETING_PREFERENCE)
        ->and(CareerFact::count())->toBe($facts)
        ->and(json_encode($variant->fresh()->selection_input_snapshot['candidate']))->not->toContain(MARKETING_PREFERENCE)
        // It is recorded as an input for review, separately from the evidence corpus.
        ->and($variant->fresh()->selection_input_snapshot['presentation_preferences'][0]['guidance'])->toBe(MARKETING_PREFERENCE);
});
