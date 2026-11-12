<?php

use App\Contracts\GeneratesResumeWording;
use App\Support\ProfileSynthesis\StoryDimensions;
use App\Support\ResumeVariant\BulletGroupDraft;
use App\Support\ResumeVariant\CompositionChecks;
use App\Support\ResumeVariant\EditorialPlan;
use App\Support\ResumeVariant\GenerateResumeVariant;
use App\Support\ResumeVariant\Prompts\ProfileJobWordingPromptV3;
use App\Support\ResumeVariant\RoleSelectionDraft;
use Illuminate\Support\Collection;
use Tests\Support\CompositionScenario;

/**
 * The editorial plan the wording model receives: why each bullet matters, what its neighbours establish, and what must not
 * be said twice. It is a plan for writing, not evidence.
 */
function planFacts(): Collection
{
    $f = fn (string $statement, string $project) => ['statement' => $statement, 'attribution' => ['project' => $project]];

    return collect([
        'own' => $f('Independently owned Beacon from business requirements through architecture, implementation, testing and delivery.', 'Beacon'),
        'build' => $f('Built Beacon, a React platform that integrates the Jira and GitLab APIs.', 'Beacon'),
        'deploy' => $f('Beacon was delivered and deployed to the Product Management team.', 'Beacon'),
        'result' => $f('Nimbus reduced reporting time by 85%.', 'Nimbus'),
        'nimbus' => $f('Built Nimbus, a Node.js analytics platform unifying BigQuery and Salesforce data.', 'Nimbus'),
    ]);
}

function planRoles(): array
{
    $group = fn (array $keys) => new BulletGroupDraft(null, 1, $keys, []);

    return [new RoleSelectionDraft(1, 'Platform Engineer', [$group(['own']), $group(['build', 'deploy']), $group(['nimbus', 'result'])])];
}

it('names the dimensions a bullet demonstrates, from its own words and nothing else', function () {
    expect(StoryDimensions::of('Built Beacon, integrating the Jira API, and deployed it to the Product Management team, cutting reporting time by 85%.'))
        ->toBe(['implementation', 'integration', 'delivery', 'outcome'])
        ->and(StoryDimensions::of('Partnered with finance and engineering stakeholders on requirements.'))->toBe(['collaboration'])
        ->and(StoryDimensions::of('Worked on things.'))->toBe([]);
});

it('tells each bullet why it matters, what its neighbours establish, and what is already said elsewhere', function () {
    $relevance = ['findings' => [7 => ['label' => 'react_ts_apps']], 'facts' => ['build' => ['finding_ids' => [7]]]];
    $plan = (new EditorialPlan)->build(planRoles(), planFacts(), $relevance);
    $middle = $plan['groups']['1:1'];

    expect($middle['why_it_matters'])->toBe('answers what the job asks for: react ts apps')
        ->and($middle['demonstrates'])->toContain('implementation', 'integration', 'delivery')
        ->and($middle['adjacent_bullets_establish'])->toHaveCount(2)
        ->and($middle['adjacent_bullets_establish'][1])->toMatchArray(['position' => 'after', 'project' => 'Nimbus'])
        // The 85% belongs to the bullet that earns it; the others are told not to repeat it.
        ->and($middle['do_not_repeat'])->toContain('the result 85% (stated in another bullet)')
        ->and($plan['groups']['1:2']['do_not_repeat'])->not->toContain('the result 85% (stated in another bullet)')
        ->and($plan['career_narrative'])->toContain('Platform Engineer (Beacon, Nimbus)');
});

it('lets only one bullet state ownership', function () {
    $plan = (new EditorialPlan)->build(planRoles(), planFacts(), ['findings' => [], 'facts' => []]);

    expect($plan['ownership_in'])->toBe('1:0')
        ->and($plan['groups']['1:0']['do_not_repeat'])->not->toContain('a claim of ownership (made in another bullet)')
        ->and($plan['groups']['1:1']['do_not_repeat'])->toContain('a claim of ownership (made in another bullet)');
});

it('hands the plan to the wording model with the new prompt, in a real generation, and adds no evidence', function () {
    $s = CompositionScenario::create();
    $synthesis = CompositionScenario::approvedProfile($s);
    $seen = new stdClass;
    app()->instance(GeneratesResumeWording::class, CompositionScenario::wording($seen));

    $variant = app(GenerateResumeVariant::class)->generateForJob($s['analysis'], $synthesis);
    $groups = collect($seen->input['experience'])->flatMap(fn ($r) => $r['bullet_groups']);

    expect($variant->wording_prompt_version)->toBe('resume-wording-profile-job-v3')
        ->and($seen->input['editorial_plan'])->toHaveKeys(['career_narrative', 'ownership_in'])
        ->and($groups->every(fn ($g) => isset($g['editorial']['why_it_matters'], $g['editorial']['demonstrates'], $g['editorial']['do_not_repeat'])))->toBeTrue()
        ->and($seen->system)->toContain('Editorial plan')->toContain('ownership_in')
        // The plan carries no facts: the model's evidence is still exactly the facts it was given.
        ->and(json_encode($seen->input['editorial_plan']))->not->toContain('SECRET-PROJECT-ZEBRA')
        ->and((new ProfileJobWordingPromptV3)->correctionSchema()['required'])->toBe(['summary']);
});

it('reports a missing dimension only when approved evidence that shows it is left off the resume', function () {
    $facts = [
        'built' => ['statement' => 'Built Flow, a Laravel platform for work tracking.', 'attribution' => ['project_id' => 1]],
        'ops' => ['statement' => 'Flow was delivered and deployed to the Product team.', 'attribution' => ['project_id' => 1]],
        'result' => ['statement' => 'Flow cut reporting time by 85%.', 'attribution' => ['project_id' => 1]],
    ];
    $accomplishments = [['fact_key' => 'built', 'project_id' => 1, 'kind' => 'implementation'], ['fact_key' => 'ops', 'project_id' => null, 'kind' => 'implementation'], ['fact_key' => 'result', 'project_id' => 2, 'kind' => 'outcome']];
    $content = ['experience' => [['bullet_groups' => [['career_fact_keys' => ['built'], 'job_analysis_finding_ids' => []]]]], 'summary_evidence' => []];

    $findings = collect(app(CompositionChecks::class)->run($content, $facts, [], $accomplishments, ['engineering' => false, 'findings' => [], 'priority' => []]))->where('check', 'story_dimensions');
    $messages = $findings->pluck('message')->implode(' ');

    expect($messages)->toContain('delivery')->toContain('outcome')->not->toContain('implementation')
        ->and($findings->every(fn ($f) => $f['severity'] === 'informational' && $f['available'] !== []))->toBeTrue();

    // With everything already on the resume there is nothing to suggest.
    $all = ['experience' => [['bullet_groups' => [['career_fact_keys' => ['built', 'ops', 'result'], 'job_analysis_finding_ids' => []]]]], 'summary_evidence' => []];

    expect(collect(app(CompositionChecks::class)->run($all, $facts, [], $accomplishments, ['engineering' => false, 'findings' => [], 'priority' => []]))->where('check', 'story_dimensions'))->toBeEmpty();
});
