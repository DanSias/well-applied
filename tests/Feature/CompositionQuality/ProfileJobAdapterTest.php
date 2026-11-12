<?php

use App\Support\JobTailoring\JobRelevance;
use App\Support\JobTailoring\ProfileJobAdapter;
use App\Support\ProfileSynthesis\EffectiveProfile;
use App\Support\ProfileSynthesis\ReviewProfileSynthesis;
use Tests\Support\CompositionScenario;

/**
 * ProfileJobAdapter turns the approved profile into a job's starting point: it re-ranks and re-weights, and it never
 * overrides a decision the user made (exclusions, pins) or changes a fact.
 */
function adapted(array $s, $synthesis, ?callable $edit = null): array
{
    if ($edit !== null) {
        $edit($synthesis);
        $synthesis = $synthesis->fresh();
    }

    $effective = EffectiveProfile::resolve($synthesis);
    $relevance = app(JobRelevance::class)->analyze($s['analysis'], $synthesis->content['inventory']['facts'], $s['career']);

    return [app(ProfileJobAdapter::class)->adapt($effective, $relevance), $effective, $relevance];
}

it('scores an accomplishment as half its profile score plus its relevance to the job, and ranks by it', function () {
    $s = CompositionScenario::create();
    [$adapted, $effective, $relevance] = adapted($s, CompositionScenario::approvedProfile($s));
    $before = collect($effective['accomplishments'])->keyBy('fact_key');

    foreach ($adapted['accomplishments'] as $i => $a) {
        $expected = 0.5 * (float) $before[$a['fact_key']]['score'] + (float) ($relevance['facts'][$a['fact_key']]['score'] ?? 0.0);

        expect($a['score'])->toEqualWithDelta($expected, 0.001)->and($a['rank'])->toBe($i + 1)
            ->and($a['profile_score'])->toBe($before[$a['fact_key']]['score']);
    }

    expect(array_column($adapted['accomplishments'], 'score'))->toBe(collect($adapted['accomplishments'])->sortByDesc('score')->pluck('score')->values()->all());
});

it('keeps the user\'s exclusions, and never puts an excluded fact in the summary', function () {
    $s = CompositionScenario::create();
    [$adapted] = adapted($s, CompositionScenario::draftProfile($s), fn ($d) => app(ReviewProfileSynthesis::class)->edit($d, 'accomplishment:webhooks', ['include' => false]));
    $byKey = collect($adapted['accomplishments'])->keyBy('fact_key');

    expect($byKey['webhooks']['included'])->toBeFalse()->and($adapted['presentation']['summary_fact_keys'])->not->toContain('webhooks');
});

it('promotes a technology mention the job asks for strongly to a bullet candidate, and leaves a weak one as skills text', function () {
    $s = CompositionScenario::create();
    [$adapted, $effective] = adapted($s, CompositionScenario::approvedProfile($s));
    $before = collect($effective['accomplishments'])->keyBy('fact_key');

    foreach ($adapted['accomplishments'] as $a) {
        if ($before[$a['fact_key']]['kind'] === 'platform_use') {
            expect($a['kind'])->toBe($a['job_relevance'] >= 0.5 ? 'implementation' : 'platform_use');
        } else {
            expect($a['kind'])->toBe($before[$a['fact_key']]['kind']);
        }
    }
});

it('leads the summary with the profile\'s own two facts, then the facts that speak most to the job', function () {
    $s = CompositionScenario::create();
    [$adapted, $effective, $relevance] = adapted($s, CompositionScenario::approvedProfile($s));
    $keys = $adapted['presentation']['summary_fact_keys'];

    expect(array_slice($keys, 0, 2))->toBe(array_slice($effective['presentation']['summary_fact_keys'], 0, 2))
        ->and(count($keys))->toBeLessThanOrEqual(2 + 5 + 2)
        ->and($keys)->toBe(array_values(array_unique($keys)));
});

it('names the job\'s strongest requirements that included evidence answers, at most five, requirements and duties only', function () {
    $s = CompositionScenario::create();
    [$adapted, , $relevance] = adapted($s, CompositionScenario::approvedProfile($s));
    $priority = $adapted['job_priority_findings'];

    expect(count($priority))->toBeLessThanOrEqual(5)->and($priority)->not->toBeEmpty();

    foreach ($priority as $id) {
        expect($relevance['findings'][$id]['category'])->toBeIn(['required_qualification', 'responsibility']);
    }

    // The preferred qualification the record cannot answer is never a priority.
    expect(array_map(fn ($id) => $relevance['findings'][$id]['label'], $priority))->not->toContain('rails');
});

it('does not change a fact or its visibility', function () {
    $s = CompositionScenario::create();
    [$adapted, $effective] = adapted($s, CompositionScenario::approvedProfile($s));

    expect($adapted['facts'])->toBe($effective['facts'])
        ->and(array_column($adapted['accomplishments'], 'fact_key'))->toHaveCount(count($effective['accomplishments']));
});
