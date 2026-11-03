<?php

use App\Models\Role;
use App\Support\ProfileSynthesis\ReferenceSelection;

/**
 * Builds the two inputs ReferenceSelection reads (the user's effective profile and the offered facts) from a
 * compact spec: [key, statement, project (1-based, null = role-level), kind, score, extras].
 */
function selectionInputs(array $specs, array $projects = [1 => [], 2 => [], 3 => []], array $roleTitles = ['Developer Support Engineer']): array
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
        $offered[] = ['key' => $key, 'statement' => $statement, 'attribution' => ['role_id' => $extra['role'] ?? $roleId, 'project_id' => $project]];
    }

    $rank = 0;
    $effective = [
        'projects' => array_map(fn ($pid) => ['id' => "project:{$pid}", 'project_id' => $pid, 'personal' => false, 'included' => $projects[$pid]['included'] ?? true, 'pinned' => $projects[$pid]['pinned'] ?? false, 'rank' => ++$rank], array_keys($projects)),
        'accomplishments' => $accomplishments, 'facts' => $facts,
        'presentation' => ['summary_fact_keys' => [], 'differentiators' => [], 'headline' => 'Full-Stack Developer', 'summary' => 'x'],
        'capabilities' => ['direct' => []],
    ];

    return [$effective, ['career_facts' => $offered], $roleId];
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
