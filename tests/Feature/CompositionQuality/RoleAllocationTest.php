<?php

use App\Support\ProfileSynthesis\ReferenceSelection;
use Tests\Support\CompositionCases as Cases;

/**
 * How many bullets each role gets in a resume written for a job. The cases are invented; their shapes are the ones seen
 * in real first drafts: a long earlier role titled "Developer" whose work is marketing, and substantial earlier
 * engineering work that a software job does speak to.
 */
function allocated(string $case, array $overrides = []): array
{
    [$effective, $payload, $roles] = Cases::forJob($case, $overrides);
    $result = app(ReferenceSelection::class)->build($effective, $payload);

    return [$result, $roles, $effective];
}

it('does not give a developer-titled role bullets for marketing work the job does not ask for', function () {
    [$result, $roles] = allocated('infrastructure');
    $chosen = Cases::chosenByRole($result, $roles);

    // The earlier "Full Stack Developer" role is represented, not erased, but the conversion and testing results stay out.
    expect($chosen['founder'])->not->toBeEmpty()
        ->and(count($chosen['founder']))->toBeLessThan(3)
        ->and($chosen['founder'])->not->toContain('g-cro', 'g-testing', 'g-cms');
});

it('keeps a substantial earlier engineering role from becoming a token when the job speaks to its work', function () {
    [$result, $roles] = allocated('architect');
    $chosen = Cases::chosenByRole($result, $roles);

    expect(count($chosen['founder']))->toBeGreaterThanOrEqual(2)
        ->and($chosen['founder'])->toContain('g-what', 'g-commerce')->not->toContain('g-cro')
        ->and(count(Cases::groupsByRole($result, $roles)['lead']))->toBeGreaterThanOrEqual(3);
});

it('keeps one bullet for a role the job barely speaks to, because every role with eligible evidence appears', function () {
    $quiet = array_fill_keys(['g-what', 'g-cro', 'g-commerce', 'g-cms', 'g-testing'], ['job' => 0.05]);
    [$result, $roles] = allocated('architect', $quiet);

    expect(count(Cases::groupsByRole($result, $roles)['founder']))->toBe(1);
});

it('does not fill a slot with evidence the job does not ask for once a role has a bullet', function () {
    foreach (['infrastructure', 'architect'] as $case) {
        [$result, $roles, $effective] = allocated($case);
        $job = array_column($effective['accomplishments'], 'job_relevance', 'fact_key');
        $floor = (float) config('resume.composition.allocation.filler_relevance');

        foreach (Cases::groupsByRole($result, $roles) as $groups) {
            foreach (array_slice($groups, 1) as $group) {
                expect(max(array_map(fn ($k) => $job[$k], $group)))->toBeGreaterThanOrEqual($floor);
            }
        }
    }
});

it('caps an analyst role at two bullets and plans inside the senior two-page range without padding', function () {
    foreach (['infrastructure', 'architect'] as $case) {
        [$result, $roles] = allocated($case);
        $groups = Cases::groupsByRole($result, $roles);
        $total = array_sum(array_map('count', $groups));

        expect(count($groups['search']))->toBeLessThanOrEqual(2)
            ->and($total)->toBeLessThanOrEqual((int) config('resume.composition.bullets.target'))
            ->and(max(array_map('count', $groups)))->toBeLessThanOrEqual((int) config('resume.composition.bullets.per_role_max'));
    }
});

it('gives more bullets to the role whose evidence the job values and tenure supports', function () {
    [$result, $roles] = allocated('architect');
    $groups = Cases::groupsByRole($result, $roles);

    expect(count($groups['current']))->toBeGreaterThan(count($groups['search']))
        ->and(count($groups['lead']))->toBeGreaterThan(count($groups['search']));
});

it('keeps the user\'s exclusions in a job, and never selects a fact that was not offered', function () {
    [$result, $roles] = allocated('architect', ['n-design' => ['included' => false]]);
    $all = array_merge(...array_values(Cases::chosenByRole($result, $roles)));

    expect($all)->not->toContain('n-design')->and($all)->toContain('n-what');

    [$effective, $payload, $roleIds] = Cases::forJob('architect');
    $payload['career_facts'] = array_values(array_filter($payload['career_facts'], fn ($f) => $f['key'] !== 'c-obs'));
    $result = app(ReferenceSelection::class)->build($effective, $payload);

    expect(array_merge(...array_values(Cases::chosenByRole($result, $roleIds))))->not->toContain('c-obs')
        ->and(collect($result['trace']['omitted'])->firstWhere('fact_key', 'c-obs')['reason'])->toContain('Not offered');
});

it('prefers a complete story of what was built to a general claim of similar relevance', function () {
    $default = (float) config('resume.composition.allocation.story_preference');
    $hasStory = function (float $preference): bool {
        // A role short of slots, so the story has to win one against other relevant work.
        config(['resume.composition.allocation.story_preference' => $preference, 'resume.composition.bullets.per_role_max' => 3]);
        [$result, $roles] = allocated('infrastructure', ['b-what' => ['job' => 0.5]]);

        return collect(Cases::groupsByRole($result, $roles)['current'])->contains(fn ($g) => in_array('b-what', $g, true) && in_array('b-deploy', $g, true));
    };

    // On relevance alone the Beacon story misses the role's slots; the preference for a complete story earns it one.
    expect($hasStory(1.0))->toBeFalse()->and($hasStory($default))->toBeTrue();
});

it('bounds the general ownership and leadership claims a role carries, leaving its other slots to the work', function () {
    $general = ['b-own' => ['job' => 0.9], 'b-own2' => ['job' => 0.7], 'c-lead' => ['job' => 0.9], 'c-docs' => ['job' => 0.9, 'statement' => 'Owned the developer documentation program for payment-gateway integration and merchant onboarding.']];
    [$result, $roles] = allocated('architect', $general);
    $groups = Cases::groupsByRole($result, $roles)['current'];
    $claims = collect($groups)->filter(fn ($g) => array_intersect($g, ['b-own', 'c-lead']) !== []);

    expect($claims->count())->toBeLessThanOrEqual((int) config('resume.composition.allocation.generic_per_role'))
        ->and(collect($groups)->flatten()->all())->toContain('l-what', 'e-what');
});
