<?php

use App\Models\CareerFact;
use App\Models\CareerLimitation;
use App\Models\JobAnalysis;
use App\Models\JobAnalysisFinding;
use App\Support\JobTailoring\JobRelevance;
use Tests\Support\CompositionScenario;

/**
 * JobRelevance ranks approved facts against a job analysis. It is deterministic, never calls a model, and authorizes
 * nothing: claims are still judged by the wording validators.
 */
function relevanceOf(array $s, $synthesis, ?JobAnalysis $analysis = null): array
{
    return app(JobRelevance::class)->analyze($analysis ?? $s['analysis'], $synthesis->content['inventory']['facts'], $s['career']);
}

it('ranks the fact that states what the job asks for above one that does not', function () {
    $s = CompositionScenario::create();
    $relevance = relevanceOf($s, CompositionScenario::approvedProfile($s));
    $score = fn (string $key) => $relevance['facts'][$key]['score'] ?? 0.0;

    expect($score('webhooks'))->toBeGreaterThan($score('trivia'))
        ->and($score('recurring'))->toBeGreaterThan($score('trivia'))
        ->and($score('b-what'))->toBeGreaterThan($score('trivia'));
});

it('is deterministic and bounded', function () {
    $s = CompositionScenario::create();
    $synthesis = CompositionScenario::approvedProfile($s);

    $first = relevanceOf($s, $synthesis);
    $second = relevanceOf($s, $synthesis);

    expect($second)->toEqual($first);

    foreach ($first['facts'] as $fact) {
        expect($fact['score'])->toBeGreaterThan(0.0)->toBeLessThanOrEqual(1.0);
    }
});

it('never ranks a Private fact or a first-person fact written only to support the summary', function () {
    $s = CompositionScenario::create();
    $relevance = relevanceOf($s, CompositionScenario::approvedProfile($s));

    expect($relevance['facts'])->not->toHaveKey('private')
        ->and($relevance['facts'])->not->toHaveKey('first-person');
});

it('weights a rare capability above a common one, so a fact that is the only proof of it is not drowned out', function () {
    $s = CompositionScenario::create();
    $synthesis = CompositionScenario::approvedProfile($s);
    $analysis = JobAnalysis::factory()->create(['job_posting_id' => $s['posting']->id, 'prompt_version' => 'job-analysis-v4']);

    foreach ([['required_qualification', 'webhook_integration', 'Experience with webhook integrations.'], ['required_qualification', 'react', 'Experience with React.']] as [$c, $l, $t]) {
        JobAnalysisFinding::factory()->create(['job_analysis_id' => $analysis->id, 'category' => $c, 'label' => $l, 'statement' => $t]);
    }

    $relevance = relevanceOf($s, $synthesis, $analysis->fresh());

    // React is stated by several facts, webhooks by one: the webhook fact leads.
    expect($relevance['facts']['webhooks']['score'])->toBeGreaterThan($relevance['facts']['b-what']['score']);
});

it('lifts a fact only a little for sharing a role with strong evidence, and never into the relevant range on that alone', function () {
    $s = CompositionScenario::create();
    $relevance = relevanceOf($s, CompositionScenario::approvedProfile($s));

    // "Used a small utility" says nothing the job asks for; its role's strong facts lend at most a tenth.
    expect($relevance['facts']['trivia']['score'] ?? 0.0)->toBeLessThanOrEqual(0.1)
        ->and($relevance['facts']['trivia']['score'] ?? 0.0)->toBeLessThan((float) config('resume.composition.allocation.relevant_at'));
});

it('weights a high-emphasis finding above a low-emphasis one', function () {
    $s = CompositionScenario::create();
    $synthesis = CompositionScenario::approvedProfile($s);
    $make = function (string $emphasis) use ($s) {
        $a = JobAnalysis::factory()->create(['job_posting_id' => $s['posting']->id, 'prompt_version' => 'job-analysis-v4']);
        JobAnalysisFinding::factory()->create(['job_analysis_id' => $a->id, 'category' => 'required_qualification', 'label' => 'webhook_integration', 'statement' => 'Experience building webhook integrations with retries.', 'emphasis' => $emphasis]);

        return $a->fresh();
    };

    expect(relevanceOf($s, $synthesis, $make('high'))['findings'])->not->toBeEmpty()
        ->and(collect(relevanceOf($s, $synthesis, $make('high'))['findings'])->first()['weight'])
        ->toBeGreaterThan(collect(relevanceOf($s, $synthesis, $make('low'))['findings'])->first()['weight']);
});

it('reports a confirmed limitation and an undocumented requirement as gaps, never as support', function () {
    $s = CompositionScenario::create();
    CareerLimitation::create(['career_profile_id' => $s['career']->id, 'topic_key' => 'ruby_on_rails', 'topic' => 'Ruby on Rails', 'terms' => ['Ruby on Rails', 'Rails'], 'status' => 'no_direct_experience', 'source' => 'user_confirmation', 'confirmed_at' => now()]);
    $relevance = relevanceOf($s, CompositionScenario::approvedProfile($s));
    $gaps = collect($relevance['gaps'])->keyBy('capability');

    expect($gaps)->toHaveKey('ruby_on_rails')->and($gaps['ruby_on_rails']['status'])->toBe('limitation')->and($gaps['ruby_on_rails']['required'])->toBeFalse()
        ->and($gaps)->not->toHaveKey('react');
});

it('reads the job\'s own findings, never another analysis', function () {
    $s = CompositionScenario::create();
    $synthesis = CompositionScenario::approvedProfile($s);
    $other = JobAnalysis::factory()->create(['job_posting_id' => $s['posting']->id, 'prompt_version' => 'job-analysis-v4']);

    expect(relevanceOf($s, $synthesis, $other->fresh())['facts'])->toBe([])
        ->and(CareerFact::query()->count())->toBeGreaterThan(5);
});
