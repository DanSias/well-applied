<?php

use App\Support\ProfileSynthesis\ReferenceSelection;
use App\Support\ProfileSynthesis\StoryDimensions;
use App\Support\ResumeDocument\ResumeContact;
use App\Support\ResumeDocument\ResumeDocument;
use App\Support\ResumeDocument\ResumeExperienceRole;
use App\Support\ResumeVariant\ClaimFidelityChecker;
use App\Support\ResumeVariant\ClaimScopeChecker;
use App\Support\ResumeVariant\CompositionChecks;
use Illuminate\Support\Collection;
use Tests\Support\CompositionCases as Cases;

/**
 * A long earlier role whose approved evidence states the work (what was built, owning projects across their lifecycle, working
 * with clients, the founder's business and delivery side) must not be reduced to one bullet because a posting shares few words
 * with it. Shapes follow the real case (an eight-year founder and developer role); every statement is a plain description of
 * the responsibility, with no client, figure or team. Runs the real ReferenceSelection; no model is called.
 */
const COVERAGE_OWN = 'Managed multiple client projects from requirements gathering and solution design through development, launch, and ongoing support.';
const COVERAGE_COLLAB = 'Worked directly with clients and stakeholders to translate business objectives into technical requirements, coordinate priorities, and deliver practical solutions.';
const COVERAGE_FOUNDER = 'Balanced hands-on development with project planning, client relationships, and delivery responsibilities as an independent founder and developer across a diverse portfolio of client engagements.';
const COVERAGE_BUILD = 'Built CMS integrations, CRM integrations, and marketing-automation tooling as part of client web-application and funnel work.';

/**
 * The responsibility facts of the long role, with the job relevance each has (the same under both cases of the fixture).
 *
 * @return array<int, array<int, mixed>>
 */
function responsibilityFacts(float $build = 0.05, float $own = 0.05, float $collab = 0.05, float $founder = 0.05): array
{
    $row = fn (string $key, string $statement, float $job) => [$key, $statement, 'founder', null, 'implementation', 0.1, ['infrastructure' => $job, 'architect' => $job]];

    return [
        $row('x-build', COVERAGE_BUILD, $build),
        $row('x-own', COVERAGE_OWN, $own),
        $row('x-collab', COVERAGE_COLLAB, $collab),
        $row('x-founder', COVERAGE_FOUNDER, $founder),
    ];
}

/** The fixture founder's own facts, for a posting that speaks to none of them. */
function quietFounder(): array
{
    return array_fill_keys(['g-what', 'g-cro', 'g-commerce', 'g-cms', 'g-testing'], ['job' => 0.05]);
}

/**
 * @return array{0: array<string, mixed>, 1: array<string, int>, 2: Collection<int, array<string, mixed>>}
 */
function coverageSelection(string $case = 'infrastructure', array $overrides = []): array
{
    [$effective, $payload, $roles] = Cases::forJob($case, $overrides);
    $result = app(ReferenceSelection::class)->build($effective, $payload);
    $trace = collect($result['trace']['roles'])->keyBy('role_id');

    return [$result, $roles, $trace];
}

it('classifies the responsibilities a statement states, and only those', function () {
    expect(StoryDimensions::responsibilities(COVERAGE_BUILD))->toBe(['build'])
        ->and(StoryDimensions::responsibilities(COVERAGE_OWN))->toBe(['ownership'])
        ->and(StoryDimensions::responsibilities(COVERAGE_COLLAB))->toBe(['client'])
        ->and(StoryDimensions::responsibilities(COVERAGE_FOUNDER))->toBe(['business'])
        // A client named as the beneficiary is not collaboration with one; a bare result states no responsibility.
        ->and(StoryDimensions::responsibilities('Built lead-generation systems for clients, connecting landing pages to a CRM.'))->toBe(['build'])
        ->and(StoryDimensions::responsibilities('Increased lead generation by 201% without additional ad spend.'))->toBe([]);
});

it('gives a long role its distinct responsibilities even when the posting shares almost no words with them', function () {
    [$result, $roles, $trace] = coverageSelection('infrastructure', ['_facts' => responsibilityFacts(), ...quietFounder()]);
    $chosen = Cases::chosenByRole($result, $roles)['founder'];
    $coverage = $trace[$roles['founder']]['coverage'];

    expect(count(Cases::groupsByRole($result, $roles)['founder']))->toBeGreaterThanOrEqual(3)
        ->and($chosen)->toContain('x-own', 'x-collab')
        // The marketing results the posting does not ask for are still not what fills the role.
        ->and($chosen)->not->toContain('g-cro', 'g-testing')
        ->and($coverage['shows'])->toContain('ownership', 'client')
        ->and($coverage['short_of'])->toBeNull();
});

it('leaves a highly relevant recent role and every other role\'s bullets exactly as the relevance plan has them', function () {
    // The same career, with and without role coverage: nothing coverage adds is taken from another role.
    config(['resume.composition.coverage.min_years' => 100]);
    [$without, $withoutRoles] = coverageSelection('architect', ['_facts' => responsibilityFacts()]);
    config(['resume.composition.coverage.min_years' => 5]);
    [$with, $withRoles] = coverageSelection('architect', ['_facts' => responsibilityFacts()]);
    $before = Cases::groupsByRole($without, $withoutRoles);
    $after = Cases::groupsByRole($with, $withRoles);

    expect($after['current'])->toBe($before['current'])
        ->and($after['lead'])->toBe($before['lead'])
        ->and($after['search'])->toBe($before['search'])
        ->and(count($after['founder']))->toBeGreaterThan(count($before['founder']))
        ->and(array_sum(array_map('count', $after)))->toBeLessThanOrEqual((int) config('resume.composition.bullets.max'));
});

it('leads with the responsibility the posting asks for and keeps the same career narrative for different postings', function () {
    // An engineering posting: what was built leads. A delivery posting: owning the work leads.
    [$engineering, $engineeringRoles, $engineeringTrace] = coverageSelection('infrastructure', ['_facts' => responsibilityFacts(build: 0.7), ...quietFounder()]);
    [$delivery, $deliveryRoles, $deliveryTrace] = coverageSelection('infrastructure', ['_facts' => responsibilityFacts(own: 0.8, collab: 0.7), ...quietFounder()]);

    expect(Cases::groupsByRole($engineering, $engineeringRoles)['founder'][0])->toContain('x-build')
        ->and(Cases::groupsByRole($delivery, $deliveryRoles)['founder'][0])->toContain('x-own');

    // Whatever leads, the role keeps the narrative: owning the work and working with clients are shown either way, and a
    // posting that values what was built still gets its three bullets (three of four responsibilities is within the target).
    foreach ([[$engineeringTrace, $engineeringRoles], [$deliveryTrace, $deliveryRoles]] as [$trace, $roles]) {
        $coverage = $trace[$roles['founder']]['coverage'];

        expect($coverage['shows'])->toContain('ownership', 'client')
            ->and(count($coverage['shows']))->toBeGreaterThanOrEqual(3)
            ->and($coverage['achieved'])->toBeGreaterThanOrEqual(3);
    }
});

it('does not stretch a short role, however much it states', function () {
    $quiet = ['infrastructure' => 0.05, 'architect' => 0.05];
    $short = ['_roles' => ['intern' => ['title' => 'Platform Developer', 'start' => 2024, 'end' => 2025]], '_facts' => [
        ['i-own', COVERAGE_OWN, 'intern', null, 'implementation', 0.1, $quiet],
        ['i-collab', COVERAGE_COLLAB, 'intern', null, 'implementation', 0.1, $quiet],
        ['i-founder', COVERAGE_FOUNDER, 'intern', null, 'implementation', 0.1, $quiet],
    ]];
    [$result, $roles, $trace] = coverageSelection('infrastructure', $short);

    expect(count(Cases::groupsByRole($result, $roles)['intern']))->toBe(1)
        ->and($trace[$roles['intern']]['coverage']['applies'])->toBeFalse();
});

it('reports limited coverage for a long role with little to say, and invents nothing', function () {
    // The default founder role only states that it built things and was founded: one distinct responsibility.
    [$result, $roles, $trace] = coverageSelection('infrastructure', quietFounder());
    $coverage = $trace[$roles['founder']]['coverage'];
    $offered = array_column(Cases::forJob('infrastructure')[1]['career_facts'], 'key');

    expect(count(Cases::groupsByRole($result, $roles)['founder']))->toBe(1)
        ->and($coverage['applies'])->toBeTrue()
        ->and($coverage['short_of'])->toBe((int) config('resume.composition.coverage.minimum'))
        ->and($coverage['reason'])->toBe('evidence')
        ->and(array_diff(array_merge(...array_values(Cases::chosenByRole($result, $roles))), $offered))->toBe([]);

    $findings = app(CompositionChecks::class)->roleCoverage($result['trace']);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]['check'])->toBe('role_coverage')
        ->and($findings[0]['severity'])->toBe(CompositionChecks::INFORMATIONAL)
        ->and($findings[0]['message'])->toContain('Founder & Full Stack Developer', 'distinct responsibilit', 'Career Data');
});

it('flags a long role that is short while evidence for more exists as something to review', function () {
    [$result, $roles] = coverageSelection('infrastructure', ['_facts' => responsibilityFacts()]);
    $trace = $result['trace'];

    // The same selection, as if the bullet ceiling had cut the role to one bullet.
    foreach ($trace['roles'] as &$role) {
        if ($role['role_id'] === $roles['founder']) {
            $role['coverage'] = [...$role['coverage'], 'achieved' => 1, 'short_of' => 3, 'reason' => 'budget'];
        }
    }
    unset($role);
    $findings = app(CompositionChecks::class)->roleCoverage($trace);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]['severity'])->toBe(CompositionChecks::REVIEW)
        ->and($findings[0]['message'])->toContain('bullet ceiling');
});

it('never adds a role bullet that shows nothing new', function () {
    // Five more facts that only say something was built: one responsibility, not five more bullets.
    $quiet = ['infrastructure' => 0.05, 'architect' => 0.05];
    $builds = array_map(fn (int $i) => ["x-b{$i}", "Built integration number {$i} for a client web application.", 'founder', null, 'implementation', 0.1, $quiet], range(1, 5));
    [$result, $roles] = coverageSelection('infrastructure', ['_facts' => $builds, ...quietFounder()]);

    expect(count(Cases::groupsByRole($result, $roles)['founder']))->toBe(1);
});

it('keeps the user\'s exclusions out of role coverage', function () {
    [$result, $roles] = coverageSelection('infrastructure', ['_facts' => responsibilityFacts(), 'x-own' => ['included' => false], ...quietFounder()]);

    expect(Cases::chosenByRole($result, $roles)['founder'])->not->toContain('x-own');
});

it('lets legitimate client and project-management language through the claim checks', function () {
    $facts = ['own' => COVERAGE_OWN, 'collab' => COVERAGE_COLLAB, 'founder' => COVERAGE_FOUNDER, 'build' => COVERAGE_BUILD];
    $legitimate = [
        ['Managed multiple client projects from requirements gathering and solution design through development, launch, and ongoing support.', ['own']],
        ['Managed multiple client projects from requirements through launch and ongoing support.', ['own']],
        ['Worked directly with clients and stakeholders to translate business objectives into technical requirements and coordinate priorities.', ['collab']],
        ['Balanced hands-on development with project planning and client relationships across a diverse portfolio of client engagements as an independent founder and developer.', ['founder']],
        ['Built and integrated CMS and CRM integrations and marketing-automation tooling within client web applications.', ['build']],
    ];

    $flagged = [];

    foreach ($legitimate as [$text, $keys]) {
        if (app(ClaimFidelityChecker::class)->findings($text, $keys, $facts) !== [] || app(ClaimScopeChecker::class)->findings($text, $keys, $facts, []) !== []) {
            $flagged[] = $text;
        }
    }

    expect($flagged)->toBe([]);
});

it('still blocks invented headcount, budgets, outcomes and scope for the same role', function () {
    $facts = ['own' => COVERAGE_OWN, 'collab' => COVERAGE_COLLAB];
    $invented = [
        'Managed a team of 12 engineers across multiple client projects.' => 'figure_precision',
        'Managed a $2M budget across multiple client projects.' => 'figure_precision',
        'Managed multiple client projects, growing client revenue by 40%.' => 'figure_precision',
        'Managed client projects across enterprise clients through production maintenance.' => 'scope_expansion',
        'Directed a distributed team delivering enterprise-grade client projects.' => 'unsupported_qualifier',
    ];

    $missed = [];

    foreach ($invented as $text => $kind) {
        if (! in_array($kind, array_column(app(ClaimFidelityChecker::class)->findings($text, ['own'], $facts), 'kind'), true)) {
            $missed[] = $text;
        }
    }

    expect($missed)->toBe([]);
});

it('drops from the role with the most bullets, so a long role\'s coverage is the last thing a tight page gives up', function () {
    $role = fn (string $name, int $n) => new ResumeExperienceRole($name, 'Developer', '2020', false, array_map(fn (int $i) => "{$name} bullet {$i}.", range(1, $n)));
    $document = new ResumeDocument(
        new ResumeContact('Name', null, null, null, null, null), 'Summary.',
        [$role('Recent', 7), $role('Middle', 3), $role('Founder', 4)], [], [], [],
    );

    $step = $document->withoutLowestPriorityBlock()->withoutLowestPriorityBlock()->withoutLowestPriorityBlock();

    // Three drops come out of the 7-bullet role (7 to 4); the founder role keeps all four until it is the largest.
    expect(array_map(fn ($r) => count($r->bullets), $step->experience))->toBe([4, 3, 4]);
});

it('rejects an unsupported claim of leading people while allowing project and client management', function () {
    $facts = ['own' => COVERAGE_OWN, 'collab' => COVERAGE_COLLAB, 'founder' => COVERAGE_FOUNDER, 'lead' => 'Led a team of four engineers building the billing platform.'];
    $checker = app(ClaimFidelityChecker::class);
    $kinds = fn (string $text, string $key) => array_column($checker->findings($text, [$key], $facts), 'kind');

    $claimsOfPeople = [
        'Led a team of developers across multiple client projects.',
        'Managed a team delivering multiple client projects.',
        'Led a cross-functional team to deliver multiple client projects.',
        'Supervised developers on multiple client projects.',
        'Managed engineers and designers across multiple client projects.',
        'Oversaw direct reports while managing multiple client projects.',
        'Served as team lead for multiple client projects.',
    ];
    $projectLanguage = [
        'Managed multiple client projects from requirements through launch and ongoing support.',
        'Managed client projects and coordinated priorities with client teams.',
        "Delivered multiple client projects within the client team's priorities.",
        'Directed client teams to the right integration library.',
        'Worked directly with clients and stakeholders to coordinate priorities and deliver practical solutions.',
    ];

    $notRejected = array_values(array_filter($claimsOfPeople, fn ($t) => ! in_array('team_claim', $kinds($t, 'own'), true)));
    $wronglyRejected = array_values(array_filter($projectLanguage, fn ($t) => in_array('team_claim', $kinds($t, 'own'), true)));

    expect($notRejected)->toBe([])
        ->and($wronglyRejected)->toBe([])
        // Evidence that does state leading a team supports the same claim.
        ->and($kinds('Led a team of four engineers building the billing platform.', 'lead'))->not->toContain('team_claim');
});

it('omits a bullet with an unsupported people-leadership claim rather than rewriting it', function () {
    $finding = ['kind' => 'team_claim', 'severity' => 'block', 'detail' => 'Led a team', 'message' => 'x'];

    expect(in_array('team_claim', ClaimFidelityChecker::KINDS, true))->toBeTrue()
        ->and(app(ClaimFidelityChecker::class)->repair('Led a team of developers across multiple client projects.', $finding))->toBeNull();
});

it('does not touch a long role the relevance plan already gave its bullets, even if a responsibility goes unshown', function () {
    // The analytics lead role already has its bullets from what the posting asks for; its stakeholder claim is not worth a swap.
    $unrelated = ['l-stakeholders' => ['job' => 0.05]];
    config(['resume.composition.coverage.min_years' => 100]);
    [$without, $withoutRoles] = coverageSelection('infrastructure', $unrelated);
    config(['resume.composition.coverage.min_years' => 5]);
    [$with, $withRoles] = coverageSelection('infrastructure', $unrelated);

    expect(Cases::groupsByRole($with, $withRoles)['lead'])->toBe(Cases::groupsByRole($without, $withoutRoles)['lead']);
});
