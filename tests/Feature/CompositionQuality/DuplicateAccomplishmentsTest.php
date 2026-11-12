<?php

use App\Contracts\GeneratesResumeWording;
use App\Support\ResumeVariant\CompositionChecks;
use App\Support\ResumeVariant\GenerateResumeVariant;
use Tests\Support\CompositionScenario;

/**
 * Duplicate accomplishments among WRITTEN bullets. Text overlap alone is never proof: two bullets about one platform
 * share its name and stack and are fine when each says something the other does not.
 */
function dupFacts(): array
{
    $nimbus = ['project_id' => 4, 'project' => 'Nimbus'];
    $beacon = ['project_id' => 1, 'project' => 'Beacon'];

    return [
        'n-design' => ['attribution' => $nimbus], 'n-result' => ['attribution' => $nimbus], 'n-what' => ['attribution' => $nimbus], 'n-ops' => ['attribution' => $nimbus],
        'b-own' => ['attribution' => $beacon], 'b-own2' => ['attribution' => $beacon], 'b-what' => ['attribution' => $beacon],
        'x-1' => ['attribution' => ['project_id' => null]], 'x-2' => ['attribution' => ['project_id' => null]],
    ];
}

function bullet(int $role, int $index, string $text, array $keys): array
{
    return ['role_id' => $role, 'index' => $index, 'text' => $text, 'fact_keys' => $keys];
}

const DUP_DESIGN = 'Designed and evolved Nimbus from the ground up, defining component boundaries and integrating third-party APIs, which reduced reporting time by 85% and saved teams approximately 20 hours per week.';

it('flags a later bullet that restates an earlier one about the same project, and says what to do', function () {
    $findings = app(CompositionChecks::class)->duplicates([
        bullet(2, 0, DUP_DESIGN, ['n-design', 'n-result']),
        bullet(2, 3, 'Led the Nimbus architecture, defining component boundaries and integrating third-party APIs, cutting reporting time by 85% for the analytics teams.', ['n-design', 'n-result']),
    ], dupFacts(), ['b-what', 'x-1']);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]['check'])->toBe('duplicate_accomplishment')->and($findings[0]['confidence'])->toBe('high')
        ->and($findings[0]['drop'])->toBe(['role_id' => 2, 'index' => 3])
        ->and($findings[0]['available'])->toBe(['b-what', 'x-1'])
        ->and($findings[0]['message'])->toContain('bullets 1 and 4')->toContain('85%');
});

it('does not flag two bullets about one project that add different dimensions, despite sharing its name and stack', function () {
    $findings = app(CompositionChecks::class)->duplicates([
        bullet(2, 0, DUP_DESIGN, ['n-design', 'n-result']),
        bullet(2, 1, 'Built Nimbus as a full-stack React and Node.js platform unifying BigQuery and Salesforce data behind a single API layer, replacing manual exports with live dashboards.', ['n-what']),
        bullet(2, 2, 'Instrumented Nimbus with scheduled data-quality checks and alerting so failed pipeline loads were caught before stakeholders saw stale numbers.', ['n-ops']),
    ], dupFacts());

    expect($findings)->toBe([]);
});

it('flags the same measured result stated twice, and leaves a result shared by different projects to a person', function () {
    $same = app(CompositionChecks::class)->duplicates([
        bullet(2, 0, 'Built Nimbus, a platform that unified reporting data behind one API and reduced reporting time by 85%.', ['n-what', 'n-result']),
        bullet(2, 1, 'Moved the finance exports onto Nimbus dashboards, cutting reporting time by 85% for the budgeting group.', ['n-ops']),
    ], dupFacts());
    $across = app(CompositionChecks::class)->duplicates([
        bullet(2, 0, 'Built Nimbus, a platform that unified reporting data behind one API and reduced reporting time by 85%.', ['n-what', 'n-result']),
        bullet(1, 0, 'Built Beacon, a delivery dashboard for product teams, and reduced status-meeting time by 85%.', ['b-what']),
    ], dupFacts());

    expect($same[0]['reason'])->toBe('repeated_result')->and($same[0]['confidence'])->toBe('high')->and($same[0]['drop'])->not->toBeNull()
        ->and($across[0]['reason'])->toBe('repeated_result')->and($across[0]['confidence'])->toBe('medium')->and($across[0]['drop'])->toBeNull();
});

it('flags a repeated ownership claim for one project and a pattern of "Independently" openings, without dropping anything', function () {
    $checks = app(CompositionChecks::class);
    $project = $checks->duplicates([
        bullet(1, 0, 'Independently owned Beacon from business requirements through architecture, implementation, testing and delivery.', ['b-own']),
        bullet(1, 1, 'Owned Beacon end to end, delivering its frontend, backend and integrations as the sole engineer on the platform.', ['b-own2']),
    ], dupFacts());
    $pattern = $checks->duplicates([
        bullet(1, 0, 'Independently owned the analytics platform from requirements through delivery.', ['x-1']),
        bullet(1, 1, 'Independently designed and implemented the technical solution for each of five initiatives.', ['x-2']),
        bullet(1, 2, 'Integrated the Jira and GitLab APIs behind one backend client feeding every dashboard.', ['b-what']),
    ], dupFacts());

    expect($project[0]['reason'])->toBe('repeated_ownership')->and($project[0]['confidence'])->toBe('medium')->and($project[0]['drop'])->toBeNull()
        ->and(collect($pattern)->pluck('reason')->all())->toContain('repeated_ownership')
        ->and(collect($pattern)->every(fn ($f) => $f['drop'] === null))->toBeTrue()
        ->and($pattern[0]['message'])->toContain('Independently');
});

it('does not treat shared words alone as duplication', function () {
    $findings = app(CompositionChecks::class)->duplicates([
        bullet(1, 0, 'Built webhook handlers for payment events with retry schedules and idempotent processing of duplicate deliveries.', ['x-1']),
        bullet(1, 1, 'Built payment reconciliation reports for merchants that match settlement files against gateway transactions each night.', ['x-2']),
    ], dupFacts());

    expect($findings)->toBe([]);
});

it('removes a certain duplicate with the reason on the review item, and only flags an uncertain one', function () {
    $bullets = fn (array $rows) => ['content' => ['summary' => 'x', 'experience' => [['role_id' => 2, 'bullets' => $rows]], 'selected_projects' => []], 'items' => array_map(fn (array $b) => [
        'location' => 'experience', 'field' => 'f', 'role_id' => 2, 'bullet_group_index' => $b['bullet_group_index'], 'fact_keys' => [], 'status' => 'passed', 'findings' => [], 'action' => null, 'text' => $b['text'],
    ], $rows)];
    $keysAt = ['2:0' => ['n-design', 'n-result'], '2:1' => ['n-what'], '2:2' => ['n-design', 'n-result']];
    $restated = 'Led the Nimbus architecture, defining component boundaries and integrating third-party APIs, cutting reporting time by 85% for the analytics teams.';
    $recovered = $bullets([
        ['bullet_group_index' => 0, 'text' => DUP_DESIGN],
        ['bullet_group_index' => 1, 'text' => 'Built Nimbus as a full-stack React and Node.js platform unifying BigQuery and Salesforce data behind a single API layer, replacing manual exports with live dashboards.'],
        ['bullet_group_index' => 2, 'text' => $restated],
    ]);

    $result = app(CompositionChecks::class)->resolveDuplicates($recovered, $keysAt, dupFacts(), ['b-what']);
    $dropped = collect($result['items'])->firstWhere('bullet_group_index', 2);

    expect(array_column($result['content']['experience'][0]['bullets'], 'bullet_group_index'))->toBe([0, 1])
        ->and($dropped['status'])->toBe('omitted')->and($dropped['text'])->toBe($restated)->and($dropped['action'])->toContain('repeats an earlier bullet')
        ->and(collect($result['items'])->firstWhere('bullet_group_index', 0)['status'])->toBe('passed')
        ->and($result['findings'][0]['resolved'])->toBeTrue()->and($result['findings'][0]['available'])->toBe(['b-what'])->and($result['findings'][0])->not->toHaveKey('drop');

    // An uncertain duplicate stays on the resume, marked for review; and a role never loses its only bullet.
    $uncertain = $bullets([
        ['bullet_group_index' => 0, 'text' => 'Independently owned Beacon from business requirements through architecture, implementation, testing and delivery.'],
        ['bullet_group_index' => 1, 'text' => 'Owned Beacon end to end, delivering its frontend, backend and integrations as the sole engineer on the platform.'],
    ]);
    $kept = app(CompositionChecks::class)->resolveDuplicates($uncertain, ['2:0' => ['b-own'], '2:1' => ['b-own2']], dupFacts());

    expect(array_column($kept['content']['experience'][0]['bullets'], 'bullet_group_index'))->toBe([0, 1])
        ->and(collect($kept['items'])->firstWhere('bullet_group_index', 1)['status'])->toBe('review')
        ->and(collect($kept['items'])->firstWhere('bullet_group_index', 1)['findings'][0]['kind'])->toBe('duplicate_accomplishment');

    $only = $bullets([['bullet_group_index' => 0, 'text' => $restated]]);

    expect(array_column(app(CompositionChecks::class)->resolveDuplicates($only, ['2:0' => ['n-design', 'n-result']], dupFacts())['content']['experience'][0]['bullets'], 'bullet_group_index'))->toBe([0]);
});

it('records duplicates in a generated resume only as advice on the bullet when the facts are not certain duplicates', function () {
    $s = CompositionScenario::create();
    $synthesis = CompositionScenario::approvedProfile($s);
    $seen = new stdClass;
    app()->instance(GeneratesResumeWording::class, CompositionScenario::wording($seen));

    $variant = app(GenerateResumeVariant::class)->generateForJob($s['analysis'], $synthesis);

    // The wiring runs on every job-tailored generation; distinct bullets produce no duplicate findings and nothing is removed.
    expect(collect($variant->selection_input_snapshot['tailoring']['composition'])->where('check', 'duplicate_accomplishment'))->toBeEmpty()
        ->and(collect($variant->review_report['items'])->filter(fn ($i) => collect($i['findings'])->contains('kind', 'duplicate_accomplishment')))->toBeEmpty();
});
