<?php

use App\Models\CareerProfile;
use App\Models\JobAnalysis;
use App\Models\JobAnalysisFinding;
use App\Models\JobPosting;
use App\Support\JobTailoring\JobRelevance;
use App\Support\ResumeVariant\ClaimFidelityChecker;

/**
 * The specificity adjustment on top of keyword and capability relevance: demonstrated work is credited, general prose
 * and incidental mentions are not. The facts are invented; the inventory is built by hand so no profile is needed.
 */
const SPEC_FACTS = [
    'build' => ['Built Beacon, a React and TypeScript platform that integrates the Jira and GitLab APIs to give teams delivery visibility.', 1, 1],
    'own' => ['Independently owned Beacon from business requirements through architecture, implementation, testing and delivery.', 1, 1],
    'lead' => ['Provided technical leadership by owning implementations and making architecture decisions for the engineering team.', null, 1],
    'cro' => ['Increased lead generation by 201% by developing a direct-response landing page and optimizing conversion performance.', null, 2],
    'seo' => ['Led data-driven SEO for 34 university programs, combining technical SEO, analytics and experimentation to improve organic growth.', null, 3],
    'aws' => ['Developed and deployed applications on AWS, configuring cloud services to support application hosting.', null, 1],
    'deploy' => ['Beacon was delivered and deployed to the Product Management team to track work items.', 1, 1],
    'old-build' => ['Built Beacon, a React and TypeScript platform that integrates the Jira and GitLab APIs to give teams delivery visibility.', 2, 2],
    'result' => ['Beacon reduced status-reporting time by 85%.', 1, 1],
    'hub' => ['Built the Marketing Budget Hub, a centralized multi-user forecasting platform with approval workflows that replaced shared-spreadsheet planning.', 2, 2],
];

function specRelevance(string $title = 'Full Stack Engineer', array $only = []): array
{
    $career = CareerProfile::factory()->create();
    $posting = JobPosting::factory()->create(['career_profile_id' => $career->id, 'title' => $title]);
    $analysis = JobAnalysis::factory()->create(['job_posting_id' => $posting->id, 'prompt_version' => 'job-analysis-v4']);

    foreach ([
        ['required_qualification', 'react_ts', 'Experience building React and TypeScript applications with API integrations.'],
        ['responsibility', 'ownership', 'Own application delivery, architecture decisions and technical leadership for the team.'],
        ['required_qualification', 'growth', 'Experience with lead generation, landing pages, SEO and conversion optimization.'],
    ] as [$category, $label, $statement]) {
        JobAnalysisFinding::factory()->create(['job_analysis_id' => $analysis->id, 'category' => $category, 'label' => $label, 'statement' => $statement]);
    }

    $facts = [];

    foreach ($only === [] ? SPEC_FACTS : array_intersect_key(SPEC_FACTS, array_flip($only)) as $key => [$statement, $project, $role]) {
        $facts[$key] = ['statement' => $statement, 'visibility' => 'public', 'capabilities' => [], 'role_id' => $role, 'project_id' => $project, 'support_only' => false];
    }

    return app(JobRelevance::class)->analyze($analysis->fresh(), $facts, $career);
}

it('ranks concrete implementation above general ownership and leadership when the job speaks to both', function () {
    $r = specRelevance();
    $score = fn (string $k) => $r['facts'][$k]['score'];

    expect($score('build'))->toBeGreaterThan($score('own'))->and($score('build'))->toBeGreaterThan($score('lead'))
        ->and($r['facts']['build']['adjustments'])->toContain('concrete_build')
        ->and($r['facts']['own']['adjustments'])->toBe(['generic_ownership'])
        ->and($r['facts']['lead']['adjustments'])->toBe(['generic_leadership']);
});

it('discounts marketing-only results for an engineering job, and not for a job that is about marketing', function () {
    $engineering = specRelevance('Senior Software Engineer');
    $marketing = specRelevance('Digital Marketing Manager');

    expect($engineering['engineering'])->toBeTrue()->and($marketing['engineering'])->toBeFalse()
        ->and($engineering['facts']['cro']['adjustments'])->toContain('marketing_only')
        ->and($engineering['facts']['seo']['adjustments'])->toContain('marketing_only')
        ->and($marketing['facts']['cro']['adjustments'])->not->toContain('marketing_only')
        ->and($marketing['facts']['cro']['score'])->toBeGreaterThan($engineering['facts']['cro']['score'])
        // Marketing results never outrank the engineering work they sit beside.
        ->and($engineering['facts']['build']['score'])->toBeGreaterThan($engineering['facts']['cro']['score'])
        ->and($engineering['facts']['build']['score'])->toBeGreaterThan($engineering['facts']['seo']['score']);
});

it('discounts a bare platform mention and credits an integration, a deployment and a project\'s measured result', function () {
    $r = specRelevance();

    expect($r['facts']['aws']['adjustments'])->toContain('platform_tag')
        ->and($r['facts']['deploy']['adjustments'])->toContain('deployed')
        ->and($r['facts']['result']['adjustments'])->toContain('project_outcome')
        ->and($r['facts']['build']['score'])->toBeGreaterThan($r['facts']['aws']['score']);
});

it('keeps every adjustment small and explainable, and records it', function () {
    $r = specRelevance();
    $cfg = config('resume.composition.relevance');

    foreach ($r['facts'] as $fact) {
        expect($fact['adjustments'])->toBeArray()->and($fact['score'])->toBeLessThanOrEqual(1.0);
    }

    expect($cfg['min'])->toBeGreaterThanOrEqual(0.5)->and($cfg['max'])->toBeLessThanOrEqual(1.2);
});

it('never counts how old the work is, so the same work scores the same in an earlier role', function () {
    // Each build alone in its own analysis, one in the current role and one in a role years earlier.
    $current = specRelevance('Full Stack Engineer', ['build'])['facts']['build'];
    $earlier = specRelevance('Full Stack Engineer', ['old-build'])['facts']['old-build'];

    expect($earlier['score'])->toBe($current['score'])->and($earlier['adjustments'])->toBe($current['adjustments']);
});

it('credits a deployment as delivery without implying how many people used it', function () {
    $checker = new ClaimFidelityChecker;
    $evidence = ['deploy' => SPEC_FACTS['deploy'][0]];

    // The fact says one team; a bullet that widens it to the company is a scope expansion the existing guard blocks.
    $faithful = $checker->findings('Built Beacon and deployed it to the Product Management team to track work items.', ['deploy'], $evidence);
    $inflated = $checker->findings('Built Beacon and deployed it across multiple teams and enterprise customers.', ['deploy'], $evidence);

    expect(array_column($faithful, 'severity'))->not->toContain('block')
        ->and(array_column($inflated, 'kind'))->toContain('scope_expansion');
});

it('does not mistake software built for marketing for a marketing result', function () {
    $r = specRelevance('Senior Software Engineer');

    // The Budget Hub is a platform with approval workflows: engineering work, though it has "marketing" in its name.
    expect($r['facts']['hub']['adjustments'])->not->toContain('marketing_only')->and($r['facts']['hub']['adjustments'])->toContain('concrete_build')
        ->and($r['facts']['cro']['adjustments'])->toContain('marketing_only');
});
