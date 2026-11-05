<?php

use App\Support\ResumeVariant\CompositionChecks;

/**
 * Whole-resume composition checks. Facts here are deliberately small: each test states the relationship it relies on
 * (a fact's project, its claim group, the action its statement makes, the capability it states).
 */
function compositionFact(string $statement, ?int $project = null, bool $result = false): array
{
    return ['statement' => $statement, 'attribution' => ['project_id' => $project], 'metric' => $result ? ['value' => 1] : null];
}

function compositionRun(array $groups, array $facts, array $summary = [], array $sizes = [], array $accomplishments = [], array $job = []): array
{
    return app(CompositionChecks::class)->run(
        ['experience' => [['role_id' => 1, 'bullet_groups' => array_map(fn ($keys) => ['career_fact_keys' => $keys], $groups)]], 'summary_evidence' => $summary],
        $facts,
        $sizes,
        $accomplishments ?: array_map(fn ($k) => ['fact_key' => $k, 'project_id' => $facts[$k]['attribution']['project_id']], array_keys($facts)),
        ['engineering' => true, 'findings' => [], 'priority' => [], ...$job],
    );
}

function compositionChecks(array $findings, string $check): array
{
    return array_values(array_filter($findings, fn ($f) => $f['check'] === $check));
}

it('does not accept a technology statement, an ownership statement or a repeated claim as a concrete build', function () {
    $facts = [
        'ts' => compositionFact('Developed TypeScript-based applications from the ground up, implementing application logic.', 3),
        'own' => compositionFact('Independently owned Atlas from requirements through delivery.', 1),
        'role' => compositionFact('Built a searchable documentation site for integrations.', null),
        'real' => compositionFact('Built Atlas, a Laravel platform that unifies work-item data.', 1),
    ];
    $checks = app(CompositionChecks::class);
    $sizes = ['ts' => 4];

    expect($checks->isConcreteBuild('ts', $facts, $sizes))->toBeFalse()
        ->and($checks->isConcreteBuild('own', $facts, $sizes))->toBeFalse()
        ->and($checks->isConcreteBuild('role', $facts, $sizes))->toBeFalse()
        ->and($checks->isConcreteBuild('real', $facts, $sizes))->toBeTrue()
        ->and($checks->isConcreteBuild('missing', $facts, $sizes))->toBeFalse();
});

it('asks for a concrete build in the summary and in the bullets, naming the builds that are available', function () {
    $facts = [
        'ts' => compositionFact('Developed TypeScript-based applications from the ground up.', 3),
        'own' => compositionFact('Independently owned Atlas from requirements through delivery.', 1),
        'real' => compositionFact('Built Atlas, a Laravel platform that unifies work-item data.', 1),
        'other' => compositionFact('Integrated billing with the account service.', null),
    ];
    $none = compositionChecks(compositionRun([['own'], ['other']], $facts, ['ts', 'own'], ['ts' => 4]), 'engineering_evidence');

    expect($none)->toHaveCount(2)->and(array_column($none, 'severity'))->each->toBe('review')
        ->and($none[0]['available'])->toBe(['real'])
        ->and($none[1]['selected'])->toBe(['ts', 'own'])->and($none[1]['available'])->toBe(['real']);

    // A build in the bullets but not behind the summary is a narrower finding.
    $summaryOnly = compositionChecks(compositionRun([['real']], $facts, ['own'], ['ts' => 4]), 'engineering_evidence');
    expect($summaryOnly)->toHaveCount(1)->and($summaryOnly[0]['message'])->toContain('summary')->and($summaryOnly[0]['available'])->toBe(['real']);

    expect(compositionChecks(compositionRun([['real']], $facts, ['real', 'own'], ['ts' => 4]), 'engineering_evidence'))->toBe([]);

    // Only an engineering-targeted resume is held to this.
    expect(compositionChecks(compositionRun([['own']], $facts, ['own'], [], [], ['engineering' => false]), 'engineering_evidence'))->toBe([]);
});

it('flags a result selected without the work that produced it only where the record links them', function () {
    $facts = [
        'build' => compositionFact('Built Atlas, a Laravel platform.', 1),
        'saved' => compositionFact('Atlas saved 20 hours per week.', 1, true),
        'roleResult' => compositionFact('Reduced onboarding from 10 hours to 3.', null, true),
        'roleWork' => compositionFact('Built onboarding documentation.', null),
    ];

    // The project's work is in another bullet: say so, and name it.
    $apart = compositionChecks(compositionRun([['saved'], ['build']], $facts), 'result_context');
    expect($apart)->toHaveCount(1)->and($apart[0]['severity'])->toBe('review')->and($apart[0]['selected'])->toBe(['saved'])->and($apart[0]['available'])->toBe(['build']);

    // The project's work is nowhere in the resume.
    $absent = compositionChecks(compositionRun([['saved']], $facts), 'result_context');
    expect($absent)->toHaveCount(1)->and($absent[0]['available'])->toBe(['build'])->and($absent[0]['message'])->toContain('without');

    // A result that rides with its project's work is complete.
    expect(compositionChecks(compositionRun([['build', 'saved']], $facts), 'result_context'))->toBe([]);

    // A role-level result has no recorded producer: no conclusion, only a note. Nothing is invented as its cause.
    $note = compositionChecks(compositionRun([['roleResult'], ['roleWork']], $facts), 'result_context');
    expect($note)->toHaveCount(1)->and($note[0]['severity'])->toBe('informational')->and($note[0]['available'])->toBe([]);
});

it('does not let supporting or troubleshooting evidence stand behind a build or ownership requirement', function () {
    $job = ['findings' => [
        ['id' => 1, 'label' => 'webhook_ownership', 'capabilities' => ['webhooks']],
        ['id' => 2, 'label' => 'no_capability', 'capabilities' => []],
    ], 'priority' => [1, 2]];
    $facts = [
        'support' => compositionFact('Troubleshot webhook flows for merchants.', null),
        'built' => compositionFact('Built the webhook handlers for order events.', 1),
        'other' => compositionFact('Built dashboards in React.', 1),
    ];

    $flagged = compositionChecks(compositionRun([['support']], $facts, [], [], [], $job), 'narrative_consistency');
    expect($flagged)->toHaveCount(1)->and($flagged[0]['severity'])->toBe('review')->and($flagged[0]['selected'])->toBe(['support'])->and($flagged[0]['message'])->toContain('webhook ownership');

    // Building it as well makes the claim defensible.
    expect(compositionChecks(compositionRun([['support'], ['built']], $facts, [], [], [], $job), 'narrative_consistency'))->toBe([]);
    // Nothing selected states the capability: that is a gap, reported elsewhere, not a wording problem.
    expect(compositionChecks(compositionRun([['other']], $facts, [], [], [], $job), 'narrative_consistency'))->toBe([]);
    // A requirement that names no capability cannot be judged this way.
    expect(compositionChecks(compositionRun([['support']], $facts, [], [], [], ['findings' => [['id' => 2, 'label' => 'x', 'capabilities' => []]], 'priority' => [2]]), 'narrative_consistency'))->toBe([]);
});

it('never reaches the blocking severity and never reorders or changes the selection', function () {
    $facts = ['a' => compositionFact('Troubleshot webhooks.', 1, true)];
    $content = ['experience' => [['role_id' => 1, 'bullet_groups' => [['career_fact_keys' => ['a']]]]], 'summary_evidence' => ['a']];
    $before = $content;

    $findings = app(CompositionChecks::class)->run($content, $facts, [], [['fact_key' => 'a', 'project_id' => 1]], ['engineering' => true, 'findings' => [['id' => 1, 'label' => 'w', 'capabilities' => ['webhooks']]], 'priority' => [1]]);

    expect($findings)->not->toBeEmpty()->and(array_unique(array_column($findings, 'severity')))->not->toContain('blocking')
        ->and($content)->toBe($before);
});
