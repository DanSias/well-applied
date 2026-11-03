<?php

use App\Enums\ClarificationSessionStatus;
use App\Enums\JobAnalysisEmphasis;
use App\Enums\JobAnalysisFindingCategory;
use App\Enums\JobAnalysisRequirementStrength;
use App\Enums\JobMatchCoverage;
use App\Enums\MatchRelationship;
use App\Models\CareerLimitation;
use App\Models\ClarificationQuestion;
use App\Models\ClarificationSession;
use App\Models\PresentationPreference;
use App\Support\Clarification\ClarificationGate;
use App\Support\Clarification\DiscoverClarificationQuestions;
use Illuminate\Support\Collection;
use Tests\Support\ClarificationFixtures;

function discover(array $s, string $mode = 'standard'): Collection
{
    return app(DiscoverClarificationQuestions::class)->discover($s['match'], $mode);
}

it('ranks the required, strongly emphasized, partly evidenced requirement first and explains the score', function () {
    $s = ClarificationFixtures::scenario();

    $questions = discover($s);

    expect($questions->pluck('topicKey')->all())->toBe(['webhooks', 'recurring_billing'])
        ->and($questions[0]->score)->toBeGreaterThan($questions[1]->score)
        ->and($questions[0]->scoreBreakdown)->toMatchArray(['coverage' => 'partial', 'strength' => 1.0, 'emphasis' => 1.0])
        ->and($questions[0]->primaryFindingId)->toBe($s['webhook']->id);
});

it('is deterministic: the same match and evidence always give the same ordered questions', function () {
    $s = ClarificationFixtures::scenario();

    expect(discover($s)->map(fn ($c) => [$c->topicKey, $c->score])->all())
        ->toBe(discover($s)->map(fn ($c) => [$c->topicKey, $c->score])->all());
});

it('asks nothing about topics that are already supported or that evidence cannot improve', function () {
    $s = ClarificationFixtures::scenario();

    $keys = discover($s, 'deep')->pluck('topicKey')->all();

    // React is supported; the culture signal is not something career evidence can improve.
    expect($keys)->not->toContain('finding-frontend_proficiency')
        ->and($keys)->not->toContain('finding-mission_driven')
        ->and($keys)->toBe(['webhooks', 'recurring_billing', 'ruby_on_rails']);
});

it('never asks about a years-of-experience floor, which the dated timeline decides', function () {
    $s = ClarificationFixtures::scenario();
    $finding = ClarificationFixtures::finding($s['analysis'], $s['match'], JobAnalysisFindingCategory::RequiredQualification, 'experience_floor',
        '4+ years of professional software engineering experience.', JobAnalysisRequirementStrength::Required, JobAnalysisEmphasis::High, JobMatchCoverage::Partial, [[$s['publicFact'], MatchRelationship::Direct]], ['years_experience_min' => 4]);

    expect(discover($s, 'deep')->pluck('primaryFindingId')->all())->not->toContain($finding->id);
});

it('keeps low-value gaps out of the ordinary stage and offers them in deeper discovery', function () {
    $s = ClarificationFixtures::scenario();

    expect(discover($s)->pluck('topicKey')->all())->not->toContain('ruby_on_rails')
        ->and(discover($s, 'deep')->pluck('topicKey')->all())->toContain('ruby_on_rails');
});

it('never asks more than three questions in the ordinary stage', function () {
    $s = ClarificationFixtures::scenario();

    foreach (['go', 'kafka', 'redis', 'sql'] as $i => $label) {
        ClarificationFixtures::finding($s['analysis'], $s['match'], JobAnalysisFindingCategory::RequiredQualification, "{$label}_depth",
            "Strong {$label} depth required.", JobAnalysisRequirementStrength::Required, JobAnalysisEmphasis::High, JobMatchCoverage::NoEvidence, []);
    }
    config(['clarification.standard.min_score' => 0.0]);

    expect(discover($s))->toHaveCount(3)->and(discover($s, 'deep'))->toHaveCount(7);
});

it('does not use Private facts in question context or in the counted adjacency, and starts visibility conservatively', function () {
    $s = ClarificationFixtures::scenario();

    $webhook = discover($s)->first();
    $keys = collect($webhook->context['related_evidence'])->pluck('key')->all();

    expect($keys)->toContain($s['publicFact']->key)->toContain($s['restrictedFact']->key)
        ->and($keys)->not->toContain($s['privateFact']->key)
        // Most restrictive eligible related fact in the suggested role: Restricted, never Public.
        ->and($webhook->suggestedVisibility)->toBe('restricted')
        ->and($webhook->suggestedAttributableType)->toBe('role')
        ->and($webhook->suggestedAttributableId)->toBe($s['role']->id);
});

it('defaults to Restricted when there is no related evidence to anchor visibility on', function () {
    $s = ClarificationFixtures::scenario();

    $billing = discover($s)->firstWhere('topicKey', 'recurring_billing');

    expect($billing->suggestedVisibility)->toBe('public')->and(discover($s, 'deep')->firstWhere('topicKey', 'ruby_on_rails')->suggestedVisibility)->toBe('restricted');
});

it('suppresses topics answered by an explicit limitation, a presentation preference or an approved fact', function () {
    $s = ClarificationFixtures::scenario();

    CareerLimitation::create(['career_profile_id' => $s['profile']->id, 'topic_key' => 'webhooks', 'topic' => 'Webhooks', 'terms' => ['webhook'], 'status' => 'no_direct_experience', 'source' => 'user_confirmation', 'confirmed_at' => now()]);
    PresentationPreference::create(['career_profile_id' => $s['profile']->id, 'topic_key' => 'recurring_billing', 'topic' => 'Billing', 'guidance' => 'Emphasize.', 'confirmed_at' => now()]);

    expect(discover($s, 'deep')->pluck('topicKey')->all())->toBe(['ruby_on_rails']);
});

it('stops suppressing a topic once its limitation is revoked', function () {
    $s = ClarificationFixtures::scenario();
    $limitation = CareerLimitation::create(['career_profile_id' => $s['profile']->id, 'topic_key' => 'webhooks', 'topic' => 'Webhooks', 'terms' => ['webhook'], 'status' => 'no_direct_experience', 'source' => 'user_confirmation', 'confirmed_at' => now()]);

    expect(discover($s)->pluck('topicKey')->all())->not->toContain('webhooks');

    $limitation->update(['revoked_at' => now()]);

    expect(discover($s)->pluck('topicKey')->all())->toContain('webhooks');
});

it('honours an explicit skip for the cooldown window but not forever', function () {
    $s = ClarificationFixtures::scenario();
    $session = ClarificationSession::create(['job_posting_id' => $s['posting']->id, 'job_analysis_id' => $s['analysis']->id, 'status' => ClarificationSessionStatus::Completed, 'mode' => 'standard']);
    $question = ClarificationQuestion::create([
        'clarification_session_id' => $session->id, 'topic_key' => 'webhooks', 'topic_label' => 'Webhooks', 'related_finding_ids' => [], 'question_text' => 'Q',
        'score' => 0.5, 'score_breakdown' => [], 'context' => [], 'suggested_visibility' => 'restricted', 'status' => 'skipped',
    ]);

    expect(discover($s)->pluck('topicKey')->all())->not->toContain('webhooks');

    $question->forceFill(['updated_at' => now()->subDays(31)])->saveQuietly();

    expect(discover($s)->pluck('topicKey')->all())->toContain('webhooks');
});

it('recognises the same topic under a differently worded job, so a later similar job is not asked again', function () {
    $s = ClarificationFixtures::scenario();
    CareerLimitation::create(['career_profile_id' => $s['profile']->id, 'topic_key' => 'webhooks', 'topic' => 'Webhooks', 'terms' => ['webhook'], 'status' => 'no_direct_experience', 'source' => 'user_confirmation', 'confirmed_at' => now()]);

    $second = ClarificationFixtures::scenario();
    $other = ClarificationFixtures::finding($second['analysis'], $second['match'], JobAnalysisFindingCategory::RequiredQualification, 'event_driven_integrations',
        'Experience handling inbound webhooks from payment providers.', JobAnalysisRequirementStrength::Required, JobAnalysisEmphasis::High, JobMatchCoverage::NoEvidence, []);

    expect(discover($second)->pluck('primaryFindingId')->all())->not->toContain($other->id)
        ->and(discover($second)->pluck('topicKey')->all())->not->toContain('webhooks');
});

it('persists ranked questions once and pauses; a job with nothing worth asking never pauses', function () {
    $s = ClarificationFixtures::scenario();
    $gate = app(ClarificationGate::class);

    expect($gate->pauseIfNeeded($s['posting'], $s['analysis'], $s['match'], 'run-1'))->toBeTrue();

    $session = ClarificationSession::sole();
    expect($session->status)->toBe(ClarificationSessionStatus::Pending)
        ->and($session->questions->pluck('topic_key')->all())->toBe(['webhooks', 'recurring_billing'])
        ->and($session->questions->first()->score_breakdown)->toHaveKey('answerability');

    // A second call neither re-ranks nor duplicates.
    expect($gate->pauseIfNeeded($s['posting'], $s['analysis'], $s['match'], 'run-1'))->toBeTrue()
        ->and(ClarificationSession::count())->toBe(1)
        ->and(ClarificationQuestion::count())->toBe(2);

    $quiet = ClarificationFixtures::scenario();
    $quiet['match']->findings()->update(['coverage' => JobMatchCoverage::Supported]);

    expect($gate->pauseIfNeeded($quiet['posting'], $quiet['analysis'], $quiet['match'], 'run-2'))->toBeFalse()
        ->and(ClarificationSession::query()->latest('id')->first()->status)->toBe(ClarificationSessionStatus::NotNeeded);
});
