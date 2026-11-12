<?php

use App\Support\ProfileSynthesis\ReferenceSelection;
use Tests\Support\CompositionCases as Cases;

/**
 * The whole-document portfolio. The live draft spent slots on a general claim of technical leadership (swapped in because the
 * job lists leadership as a requirement), on a story carried by a bare Azure mention, and on a general ownership pair, while a
 * concrete point-of-contact fact and a documentation fact with two measured results were left out. These cases are invented and
 * use no names; each rule is general.
 */
function portfolio(string $case, array $overrides = [], array $config = []): array
{
    config($config);
    [$effective, $payload, $roles] = Cases::forJob($case, $overrides);
    $result = app(ReferenceSelection::class)->build($effective, $payload);

    return [Cases::groupsByRole($result, $roles), $result];
}

it('does not let a general claim take a slot by answering a requirement, when concrete work in the role is left out', function () {
    $requirement = ['c-lead' => ['job' => 0.5, 'general' => true, 'findings' => [7]], '_priority' => [7]];
    // Slots are tight, so the swap that guarantees a bullet to each requirement is what decides.
    $tight = ['resume.composition.bullets.per_role_max' => 4, 'resume.composition.allocation.generic_per_role' => 99];

    [$general] = portfolio('infrastructure', $requirement, $tight);
    [$concrete] = portfolio('infrastructure', ['c-lead' => ['job' => 0.5, 'general' => false, 'findings' => [7]], '_priority' => [7]], $tight);

    // The same claim, if it were concrete evidence, would earn the swap; as a general claim it must earn its slot by score.
    expect(array_merge(...$concrete['current']))->toContain('c-lead')
        ->and(array_merge(...$general['current']))->not->toContain('c-lead')->and(array_merge(...$general['current']))->toContain('c-contact');
});

it('allows only one general claim of ownership or leadership in a role, leaving its slots to the work', function () {
    [$groups] = portfolio('architect', ['b-own' => ['job' => 0.9], 'c-lead' => ['job' => 0.9]]);
    $claims = collect($groups['current'])->filter(fn ($g) => array_intersect($g, ['b-own', 'c-lead']) !== []);

    expect($claims)->toHaveCount(1)->and(array_merge(...$groups['current']))->toContain('c-contact', 'c-obs');
});

it('does not let a bare platform mention make a story look more relevant than the work it rides with', function () {
    $tight = ['resume.composition.bullets.per_role_max' => 3];
    // The Beacon story's integration fact is a bare mention with a very high keyword score; the build itself is only marginal.
    $onStory = fn (bool $tag) => collect(portfolio('infrastructure', ['b-what' => ['job' => 0.32], 'b-unify' => ['job' => 0.95, 'bare_tag' => $tag]], $tight)[0]['current'])->contains(fn ($g) => in_array('b-what', $g, true));

    expect($onStory(false))->toBeTrue()->and($onStory(true))->toBeFalse();
});

it('brings a role-level result in with the work it describes, as one accomplishment', function () {
    [$groups] = portfolio('architect');
    $docs = collect($groups['current'])->first(fn ($g) => in_array('c-docs', $g, true));

    // "Reduced new-merchant onboarding effort ..." shares what it is about with the documentation fact for merchant onboarding.
    expect($docs)->toContain('c-docs-result')
        ->and(collect($groups['current'])->filter(fn ($g) => $g === ['c-docs-result'])->count())->toBe(0);
});

it('leaves a result with no work it describes as its own accomplishment, and a description that is not a result alone', function () {
    // Nothing in the founder's role-level work shares two significant words with the lead-generation result.
    [$groups] = portfolio('architect', ['g-cro' => ['job' => 0.8]], ['resume.composition.allocation.generic_per_role' => 99]);
    $founder = $groups['founder'];

    expect(collect($founder)->contains(fn ($g) => $g === ['g-cro']))->toBeTrue()
        // "Led SEO ..." is kind outcome in the profile but not a result; it is never pulled into another fact.
        ->and(collect($groups['search'])->flatten()->all())->not->toContain('c-docs-result');
});

it('keeps an earlier role represented, and never gives a slot to evidence the job does not ask for', function () {
    foreach (['infrastructure', 'architect'] as $case) {
        [$groups] = portfolio($case);

        expect(count($groups['founder']))->toBeGreaterThanOrEqual(1)->and(count($groups['lead']))->toBeGreaterThanOrEqual(2);
    }
});

it('does not append a bare platform mention to a project\'s bullet', function () {
    [$groups] = portfolio('architect', ['b-unify' => ['bare_tag' => true]]);
    $beacon = collect($groups['current'])->first(fn ($g) => in_array('b-what', $g, true));

    // The mention is not part of the story; the build and where it was deployed still are.
    expect($beacon)->toContain('b-what', 'b-deploy')->not->toContain('b-unify');
});
