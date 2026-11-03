<?php

use App\Models\CareerFact;
use App\Models\CareerProfile;
use App\Models\Role;
use App\Support\ProfileSynthesis\ReferenceSelection;

/**
 * Builds the two inputs ReferenceSelection reads (the user's effective profile and the offered facts) from a
 * compact spec: [key, statement, project (1-based, null = role-level), kind, score, extras].
 */
function selectionInputs(array $specs, array $projects = [1 => [], 2 => [], 3 => []], array $roleTitles = ['Developer Support Engineer'], array $overrides = []): array
{
    $roles = array_map(fn (string $title) => Role::factory()->create(['title' => $title])->id, $roleTitles);
    $roleId = $roles[0];
    $accomplishments = [];
    $facts = [];
    $offered = [];

    foreach ($specs as $i => [$key, $statement, $project, $kind, $score]) {
        $extra = $specs[$i][5] ?? [];
        $accomplishments[] = ['id' => "accomplishment:{$key}", 'fact_key' => $key, 'project_id' => $project, 'role_id' => $extra['role'] ?? $roleId, 'kind' => $kind, 'score' => $score, 'included' => $extra['included'] ?? true, 'pinned' => $extra['pinned'] ?? false, 'rank' => $i + 1];
        $facts[$key] = ['type' => $extra['type'] ?? 'bullet', 'statement' => $statement];
        $offered[] = ['key' => $key, 'statement' => $statement, 'visibility' => $extra['visibility'] ?? 'public', 'skills' => $extra['skills'] ?? [], 'attribution' => ['role_id' => $extra['role'] ?? $roleId, 'project_id' => $project], ...($extra['shared'] ?? false ? ['shared_claim' => ['also_attached_to' => ['Other place']]] : [])];
    }

    $rank = 0;
    $effective = [
        'projects' => array_map(fn ($pid) => ['id' => "project:{$pid}", 'project_id' => $pid, 'personal' => false, 'included' => $projects[$pid]['included'] ?? true, 'pinned' => $projects[$pid]['pinned'] ?? false, 'rank' => ++$rank], array_keys($projects)),
        'accomplishments' => $accomplishments, 'facts' => $facts,
        'presentation' => ['summary_fact_keys' => [], 'differentiators' => [], 'headline' => 'Full-Stack Developer', 'summary' => 'x'],
        'capabilities' => ['direct' => []],
    ];

    return [array_replace_recursive($effective, $overrides), ['career_facts' => $offered], $roleId];
}

function chosenKeys(array $result, ?int $roleId = null): array
{
    return collect($result['content']['experience'])->when($roleId, fn ($c) => $c->where('role_id', $roleId))->flatMap(fn ($r) => collect($r['bullet_groups'])->flatMap(fn ($g) => $g['career_fact_keys']))->all();
}

const OWN = 'Independently owned Flow from initial business requirements through architecture, implementation, testing and delivery.';
const ARCH = 'Designed Flow around deterministic, evidence-linked metrics computed once and reused everywhere they appear.';
const BACKEND = "Built Flow's application backend and integrated the Jira and GitLab APIs to retrieve and process workflow data.";
const SECURITY = "Designed Vault's credential handling around a local-first model, with keys encrypted at rest and held only in memory.";
const GENERIC_TS = 'Developed TypeScript-based applications from the ground up, with typed interfaces and automated tests.';
const LEADERSHIP = 'Provided technical leadership by owning implementations and making architecture and technology decisions.';

it('chooses distinctive engineering evidence over generic ownership, TypeScript and leadership claims', function () {
    config(['resume_profiles.reference.total_bullets' => 4, 'resume_profiles.reference.per_role_max' => 4]);
    [$effective, $payload, $role] = selectionInputs([
        ['lead', LEADERSHIP, null, 'implementation', 0.67],
        ['ts', GENERIC_TS, 2, 'ownership', 0.63],
        ['own', OWN, 1, 'ownership', 0.47],
        ['arch', ARCH, 1, 'implementation', 0.22],
        ['backend', BACKEND, 1, 'implementation', 0.12],
        ['security', SECURITY, 2, 'implementation', 0.45],
    ]);

    $keys = chosenKeys(app(ReferenceSelection::class)->build($effective, $payload), $role);

    // The three kinds the profile calls differentiating are in; the generic claims are not crowding them out.
    expect($keys)->toContain('arch', 'backend', 'security')->not->toContain('ts', 'lead');
});

it('lets overlapping facts of one project and kind share a bullet, and never packs more than three facts into one', function () {
    [$effective, $payload, $role] = selectionInputs([
        ['backend', BACKEND, 1, 'implementation', 0.2],
        ['unify', 'Normalized Jira work items and GitLab merge requests into a single workflow model read by every view.', 1, 'implementation', 0.2],
        ['api2', 'Integrated the REST APIs of Jira and GitLab behind one backend client.', 1, 'implementation', 0.2],
        ['m1', 'Flow reduced reporting time by 85%.', 1, 'outcome', 0.4],
        ['m2', 'Flow saved approximately 20 hours per week across teams.', 1, 'outcome', 0.4],
    ]);

    $groups = collect(app(ReferenceSelection::class)->build($effective, $payload)['content']['experience'])->flatMap(fn ($r) => $r['bullet_groups']);

    expect($groups->max(fn ($g) => count($g['career_fact_keys'])))->toBeLessThanOrEqual(3)
        ->and($groups->filter(fn ($g) => array_intersect($g['career_fact_keys'], ['backend', 'unify']) === ['backend', 'unify'] || count(array_intersect($g['career_fact_keys'], ['backend', 'unify'])) === 2)->count())->toBe(1);
});

it('skips a statement that merely restates one already chosen', function () {
    config(['resume_profiles.reference.total_bullets' => 4, 'resume_profiles.reference.per_role_max' => 4]);
    [$effective, $payload, $role] = selectionInputs([
        ['own', OWN, 1, 'ownership', 0.5],
        ['own2', 'Independently owned Flow from business requirements through architecture, implementation, testing and delivery.', 1, 'ownership', 0.4],
        ['security', SECURITY, 2, 'implementation', 0.4],
    ]);

    $keys = chosenKeys(app(ReferenceSelection::class)->build($effective, $payload), $role);

    expect(count(array_intersect($keys, ['own', 'own2'])))->toBe(1)->and($keys)->toContain('security');
});

it('respects the user\'s pins, exclusions and project order', function () {
    config(['resume_profiles.reference.total_bullets' => 3, 'resume_profiles.reference.per_role_max' => 3]);
    [$effective, $payload, $role] = selectionInputs([
        ['arch', ARCH, 1, 'implementation', 0.4],
        ['backend', BACKEND, 1, 'implementation', 0.4],
        ['security', SECURITY, 2, 'implementation', 0.4],
        ['weak', 'Worked with Slack integrations for a team channel.', 3, 'implementation', 0.05, ['pinned' => true]],
        ['gone', 'Designed a distinctive caching layer with idempotent invalidation.', 3, 'implementation', 0.9, ['included' => false]],
    ], [1 => [], 2 => [], 3 => []]);

    $keys = chosenKeys(app(ReferenceSelection::class)->build($effective, $payload), $role);

    expect($keys)->toContain('weak')->not->toContain('gone');

    // Excluding a whole project removes every one of its facts, however strong.
    [$effective, $payload, $role] = selectionInputs([
        ['arch', ARCH, 1, 'implementation', 0.4], ['security', SECURITY, 2, 'implementation', 0.9], ['own', OWN, 3, 'ownership', 0.4],
    ], [1 => [], 2 => ['included' => false], 3 => []]);

    expect(chosenKeys(app(ReferenceSelection::class)->build($effective, $payload), $role))->not->toContain('security');
});

it('does not require every project to appear', function () {
    config(['resume_profiles.reference.total_bullets' => 2, 'resume_profiles.reference.per_role_max' => 2, 'resume_profiles.reference.priority_projects' => 1]);
    [$effective, $payload, $role] = selectionInputs([
        ['arch', ARCH, 1, 'implementation', 0.5], ['backend', BACKEND, 1, 'implementation', 0.4],
        ['security', SECURITY, 2, 'implementation', 0.4], ['minor', 'Built a small internal dashboard for one team.', 3, 'implementation', 0.1],
    ], roleTitles: ['Analyst']);

    $result = app(ReferenceSelection::class)->build($effective, $payload);

    expect(chosenKeys($result, $role))->not->toContain('minor')->and(collect($result['trace']['omitted'])->pluck('fact_key'))->toContain('minor');
});

it('protects the user\'s top projects with a swap first and a small, bounded extra allowance only if needed', function () {
    config(['resume_profiles.reference.total_bullets' => 2, 'resume_profiles.reference.per_role_max' => 2, 'resume_profiles.reference.per_project_max' => 2, 'resume_profiles.reference.priority_projects' => 3, 'resume_profiles.reference.coverage_extra_bullets' => 1]);
    [$effective, $payload, $role] = selectionInputs([
        ['arch', ARCH, 1, 'implementation', 0.5], ['backend', BACKEND, 1, 'implementation', 0.5],
        ['security', SECURITY, 2, 'implementation', 0.4], ['own3', 'Owned the implementation of the whole Gamma service end to end with its own datastore.', 3, 'ownership', 0.3],
    ], roleTitles: ['Analyst']);

    $result = app(ReferenceSelection::class)->build($effective, $payload);
    $bullets = count($result['content']['experience'][0]['bullet_groups']);

    // Three protected projects, two base slots, one extra allowed: never more than base + extra, and every protected project is covered.
    expect($bullets)->toBeLessThanOrEqual(3)->and($result['trace']['extra_bullets_used'])->toBeLessThanOrEqual(1)
        ->and(chosenKeys($result, $role))->toContain('security', 'own3');

    config(['resume_profiles.reference.coverage_extra_bullets' => 0]);
    $none = app(ReferenceSelection::class)->build($effective, $payload);

    expect(count($none['content']['experience'][0]['bullet_groups']))->toBe(2)->and($none['trace']['extra_bullets_used'])->toBe(0);
});

it('keeps scope descriptions and whole-career facts out of the bullets, and still represents every role', function () {
    [$effective, $payload, $role] = selectionInputs([
        ['scope', 'Worked across marketing analytics and marketing-technology systems spanning campaign tracking and attribution.', null, 'implementation', 0.9, ['type' => 'narrative']],
        ['real', ARCH, 1, 'implementation', 0.2],
    ]);
    $second = Role::factory()->create(['title' => 'Analyst'])->id;
    $effective['accomplishments'][] = ['id' => 'accomplishment:only', 'fact_key' => 'only', 'project_id' => null, 'role_id' => $second, 'kind' => 'implementation', 'score' => 0.1, 'included' => false, 'pinned' => false, 'rank' => 9];
    $effective['facts']['only'] = ['type' => 'bullet', 'statement' => 'Ran weekly search-performance reviews for the university programs.'];
    $payload['career_facts'][] = ['key' => 'only', 'statement' => 'Ran weekly search-performance reviews for the university programs.', 'attribution' => ['role_id' => $second, 'project_id' => null]];

    $result = app(ReferenceSelection::class)->build($effective, $payload);

    expect(chosenKeys($result, $role))->toContain('real')->not->toContain('scope')
        // The role whose only fact the user excluded still appears (role completeness), through its fallback.
        ->and(chosenKeys($result, $second))->toBe(['only'])->and(collect($result['trace']['roles'])->firstWhere('role_id', $second)['fallback'])->toBeTrue();
});

it('lets the approved profile\'s cited differentiators weigh in, so its narrative informs the selection', function () {
    config(['resume_profiles.reference.total_bullets' => 1, 'resume_profiles.reference.per_role_max' => 1, 'resume_profiles.reference.priority_projects' => 0]);
    [$effective, $payload, $role] = selectionInputs([
        ['a', 'Designed Alpha around deterministic, evidence-linked metrics computed once and reused everywhere.', 1, 'implementation', 0.4],
        ['b', 'Designed Beta around deterministic, idempotent retrieval with every answer traced to its source.', 2, 'implementation', 0.4],
    ], [1 => [], 2 => []], ['Analyst'], ['presentation' => ['differentiators' => [['id' => 'differentiator:0', 'included' => true, 'statement' => 'x', 'fact_keys' => ['b']]]]]);

    expect(chosenKeys(app(ReferenceSelection::class)->build($effective, $payload), $role))->toBe(['b']);
});

it('gives the claim a cited sibling stands for to the summary, so approved summary content keeps its evidence', function () {
    $group = '44444444-4444-4444-8444-444444444444';
    $career = CareerProfile::factory()->create();
    foreach (['ts-sibling', 'ts-offered'] as $key) {
        CareerFact::factory()->create(['career_profile_id' => $career->id, 'key' => $key, 'claim_group' => $group, 'statement' => 'Developed TypeScript-based applications from the ground up.']);
    }
    [$effective, $payload] = selectionInputs([['ts-offered', 'Developed TypeScript-based applications from the ground up.', 1, 'ownership', 0.3], ['arch', ARCH, 1, 'implementation', 0.4]], [1 => []], ['Analyst'], ['presentation' => ['summary_fact_keys' => ['ts-sibling', 'arch']]]);

    // The profile cited a place of the shared claim that is not the one offered; the offered place stands for it.
    expect(app(ReferenceSelection::class)->build($effective, $payload)['content']['summary_evidence'])->toContain('ts-offered', 'arch')->not->toContain('ts-sibling');
});

it('does not promise a confidential figure: a restricted result is evidence, not an outcome bullet', function () {
    [$effective, $payload, $role] = selectionInputs([
        ['cpl', 'Contributed to an engagement in which cost per lead fell from $1.21 to $0.45, a reduction of approximately 63%.', null, 'outcome', 0.5, ['visibility' => 'restricted']],
        ['plain', 'Delivered a 37% increase in post-purchase conversion rates.', null, 'outcome', 0.4],
    ], [1 => []], ['Analyst']);

    $facets = app(ReferenceSelection::class)->build($effective, $payload)['trace']['roles'][0]['facets'];

    expect(array_count_values($facets)['outcome'] ?? 0)->toBe(1);
});

it('shows a developer role at least one thing that was built, from approved evidence, not only results', function () {
    config(['resume_profiles.reference.total_bullets' => 3, 'resume_profiles.reference.per_role_max' => 3, 'resume_profiles.reference.developer_role_floor' => 3, 'resume_profiles.reference.priority_projects' => 0]);
    [$effective, $payload, $role] = selectionInputs([
        ['r1', 'Delivered a 37% increase in post-purchase conversion rates.', null, 'outcome', 0.5],
        ['r2', 'Delivered 201.2% growth in lead generation without increasing advertising spend.', null, 'outcome', 0.5],
        ['eng', 'Contributed experiment setup and landing page implementation as part of the engagement team.', 1, 'implementation', 0.4],
        ['built', 'Implemented ecommerce functionality for clients, including shopping-cart integrations and checkout and payment flows.', null, 'implementation', 0.2],
    ], [1 => []], ['Founder & Full Stack Developer']);

    expect(chosenKeys(app(ReferenceSelection::class)->build($effective, $payload), $role))->toContain('built');
});

it('prefers concrete project work over a general claim shared by several projects', function () {
    config(['resume_profiles.reference.total_bullets' => 1, 'resume_profiles.reference.per_role_max' => 1, 'resume_profiles.reference.priority_projects' => 0]);
    [$effective, $payload, $role] = selectionInputs([
        ['shared', 'Owned the implementation of the frontend experience alongside the underlying application functionality.', 1, 'ownership', 0.45, ['shared' => true]],
        ['concrete', 'Built the retrieval layer so every answer is traced to the exact source section it came from.', 2, 'implementation', 0.4],
    ], [1 => [], 2 => []], ['Analyst']);

    expect(chosenKeys(app(ReferenceSelection::class)->build($effective, $payload), $role))->toBe(['concrete']);
});

it('draws skills from the curated profile and project stacks, not citation counts, and keeps the section short', function () {
    $skill = fn (int $id, string $name, string $category = 'build_technology') => ['id' => $id, 'name' => $name, 'category' => $category];
    [$effective, $payload] = selectionInputs([
        ['a1', ARCH, 1, 'implementation', 0.4, ['skills' => [$skill(1, 'TypeScript'), $skill(2, 'Laravel'), $skill(3, 'MySQL')]]],
        ['a2', BACKEND, 1, 'implementation', 0.4, ['skills' => [$skill(4, 'Jira', 'platform_integration')]]],
        // Cited by every bullet and attached to the minor project, but not part of a protected stack nor a kept capability.
        ['b1', SECURITY, 2, 'implementation', 0.4, ['skills' => [$skill(5, 'Prisma'), $skill(6, 'Docker')]]],
        ['r', 'Handled a restricted service.', 2, 'implementation', 0.3, ['visibility' => 'restricted', 'skills' => [$skill(7, 'Kubernetes')]]],
    ], [1 => [], 2 => []], ['Analyst'], ['capabilities' => ['direct' => [['key' => 'typescript', 'included' => true, 'market_share' => 0.8]]]]);

    $ids = array_column(app(ReferenceSelection::class)->build($effective, $payload)['content']['skills'], 'skill_id');

    // The kept capability leads; the flagship project's stack is present; a skill only restricted evidence carries is not.
    expect($ids[0])->toBe(1)->and($ids)->toContain(2, 3)->not->toContain(7)->and(count($ids))->toBeLessThanOrEqual(14);
});
