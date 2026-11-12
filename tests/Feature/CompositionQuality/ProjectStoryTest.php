<?php

use App\Support\ProfileSynthesis\ReferenceSelection;
use App\Support\ProfileSynthesis\StoryRole;
use Tests\Support\CompositionCases as Cases;

function storyGroups(string $case = 'architect', array $overrides = []): array
{
    [$effective, $payload, $roles] = Cases::forJob($case, $overrides);
    $result = app(ReferenceSelection::class)->build($effective, $payload);
    $project = collect($payload['career_facts'])->mapWithKeys(fn ($f) => [$f['key'] => $f['attribution']['project_id']])->all();

    return [collect(Cases::groupsByRole($result, $roles))->flatten(1)->values()->all(), $project];
}

it('tells a project\'s story in one accomplishment: what was built, how, and where it was used', function () {
    [$groups] = storyGroups();
    $beacon = collect($groups)->first(fn ($g) => in_array('b-what', $g, true));
    $lookup = collect($groups)->first(fn ($g) => in_array('l-what', $g, true));

    expect($beacon)->toContain('b-what', 'b-deploy', 'b-unify')
        ->and($lookup)->toContain('l-what', 'l-deploy', 'l-reuse');
});

it('keeps a measured result with the work that produced it, with the design decision, in one bullet', function () {
    [$groups] = storyGroups();
    $nimbus = collect($groups)->filter(fn ($g) => array_intersect($g, ['n-what', 'n-design', 'n-result-1', 'n-result-2']) !== []);

    // The first drafts told this in two bullets that restated each other.
    expect($nimbus)->toHaveCount(1)
        ->and($nimbus->first())->toContain('n-what', 'n-design', 'n-result-1', 'n-result-2');
});

it('does not assume every story has every part', function () {
    [$groups] = storyGroups();
    $forecast = collect($groups)->first(fn ($g) => in_array('f-what', $g, true));
    $exporter = collect($groups)->first(fn ($g) => in_array('e-what', $g, true));

    expect($forecast)->toBe(['f-what', 'f-result'])->and($exporter)->toBe(['e-what']);
});

it('never combines facts of different projects, and never packs more facts than the story allows', function () {
    foreach (['infrastructure', 'architect'] as $case) {
        [$groups, $project] = storyGroups($case);

        foreach ($groups as $group) {
            $projects = array_unique(array_map(fn ($k) => $project[$k], $group));
            $limit = (int) config('resume.composition.story.lead_max_facts');

            expect(count($group))->toBeLessThanOrEqual($limit)
                ->and(count(array_filter($projects, fn ($p) => $p !== null)) <= 1)->toBeTrue();
        }
    }
});

it('does not add a deployment to a project whose facts do not state one, and keeps a lone deployment as evidence', function () {
    [$effective, $payload, $roles] = Cases::forJob('architect', ['b-deploy' => ['included' => false]]);
    $result = app(ReferenceSelection::class)->build($effective, $payload);
    $beacon = collect(Cases::groupsByRole($result, $roles))->flatten(1)->first(fn ($g) => in_array('b-what', $g, true));

    expect($beacon)->not->toContain('b-deploy')->and($beacon)->toContain('b-what');
});

it('classifies a fact\'s part in a story from its own statement, conservatively', function () {
    $deployed = [
        'Beacon was delivered and deployed to the Product Management team to track work items.',
        'Lookup was delivered and deployed to the merchant support team for first-pass lookup.',
        'Delivered the tool to the Analytics team for on-demand reports.',
    ];
    $notDeployed = [
        'Developed and deployed applications on AWS, configuring cloud services for hosting.',
        'Built a deployment pipeline that deploys to staging after every merge.',
        'Reduced deploy time by 40%.',
    ];

    foreach ($deployed as $s) {
        expect(StoryRole::isDeployment($s))->toBeTrue($s);
    }

    foreach ($notDeployed as $s) {
        expect(StoryRole::isDeployment($s))->toBeFalse($s);
    }

    expect(StoryRole::isOutcome('Nimbus reduced reporting time by 85%.'))->toBeTrue()
        ->and(StoryRole::isOutcome('Built Nimbus on Node.js 20.'))->toBeFalse()
        ->and(StoryRole::of('Built Beacon, a platform.', true))->toBe('built')
        ->and(StoryRole::of('Integrated Jira behind one client.', false))->toBe('implementation')
        ->and(StoryRole::of('Beacon was deployed to the Product team.', false))->toBe('deployment');
});
