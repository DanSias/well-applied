<?php

use App\Enums\CareerFactType;
use App\Enums\EvidenceSource;
use App\Enums\ProposalKind;
use App\Enums\ProposalStatus;
use App\Enums\Visibility;
use App\Models\CareerFact;
use App\Models\CareerProfile;
use App\Models\Employer;
use App\Models\Evidence;
use App\Models\EvidenceProposal;
use App\Models\Project;
use App\Models\Role;
use App\Models\Skill;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The Career Data views are read-only and derived. Helpers are `browse`-prefixed: Pest loads every Feature file into one
 * global namespace.
 */
function browseProfile(): array
{
    $profile = CareerProfile::factory()->create(['name' => 'Test Person']);
    $employer = Employer::factory()->for($profile)->create(['name' => 'Acme Corp']);
    $role = Role::factory()->for($employer)->create(['title' => 'Engineer', 'start_year' => 2022, 'start_month' => 3]);
    $project = Project::factory()->for($role)->create(['name' => 'Widget Platform', 'slug' => 'widget-platform']);

    return [$profile, $employer, $role, $project];
}

function browseFact(CareerProfile $profile, Model $place, array $attributes = []): CareerFact
{
    return CareerFact::factory()->for($profile)->create($attributes + [
        'attributable_type' => $place->getMorphClass(),
        'attributable_id' => $place->id,
    ]);
}

it('derives a skill\'s project count and links from approved project facts, not from the unused pivot', function () {
    [$profile, , $role, $widget] = browseProfile();
    $other = Project::factory()->for($role)->create(['name' => 'Gadget', 'slug' => 'gadget']);
    $php = Skill::factory()->for($profile)->create(['name' => 'PHP', 'slug' => 'php']);
    $unused = Skill::factory()->for($profile)->create(['name' => 'Unused', 'slug' => 'unused']);
    $roleOnly = Skill::factory()->for($profile)->create(['name' => 'RoleOnly', 'slug' => 'role-only']);

    $php->careerFacts()->attach([browseFact($profile, $widget)->id, browseFact($profile, $widget)->id, browseFact($profile, $other)->id]);
    $roleOnly->careerFacts()->attach(browseFact($profile, $role)->id);

    expect(DB::table('project_skill')->count())->toBe(0);

    $skills = collect($this->get(route('career-data.index'))->inertiaProps()['skills'])->keyBy('slug');

    expect($skills['php']['project_count'])->toBe(2)
        ->and(collect($skills['php']['projects'])->pluck('slug')->sort()->values()->all())->toBe(['gadget', 'widget-platform'])
        // Two facts on Widget Platform, one on Gadget: the busier project comes first.
        ->and($skills['php']['projects'][0])->toMatchArray(['slug' => 'widget-platform', 'employer' => 'Acme Corp', 'fact_count' => 2])
        ->and($skills['php']['career_fact_count'])->toBe(3)
        // Only role-level evidence: the skill is documented, but not evidenced on any project.
        ->and($skills['role-only']['project_count'])->toBe(0)->and($skills['role-only']['career_fact_count'])->toBe(1)
        ->and($skills['unused']['project_count'])->toBe(0);
});

it('does not write anything while browsing', function () {
    [$profile, , , $project] = browseProfile();
    browseFact($profile, $project);
    $before = [CareerFact::count(), Skill::count(), DB::table('project_skill')->count()];

    $this->get(route('career-data.index', ['view' => 'facts']))->assertOk();
    $this->get(route('career-data.index', ['view' => 'skills']))->assertOk();

    expect([CareerFact::count(), Skill::count(), DB::table('project_skill')->count()])->toBe($before);
});

it('lists facts paginated with a range, defaulting to 25 and clamping the page', function () {
    [$profile, , , $project] = browseProfile();
    foreach (range(1, 60) as $i) {
        browseFact($profile, $project, ['statement' => sprintf('Fact %03d', $i), 'sort_order' => $i]);
    }

    $this->get(route('career-data.index', ['view' => 'facts']))->assertInertia(fn (Assert $page) => $page
        ->where('view', 'facts')
        ->has('facts', 25)
        ->where('facts.0.statement', 'Fact 001')
        ->where('pagination.total', 60)->where('pagination.last_page', 3)->where('pagination.from', 1)->where('pagination.to', 25)
        ->where('counts.facts', 60));

    $this->get(route('career-data.index', ['view' => 'facts', 'page' => 99, 'per_page' => 50]))->assertInertia(fn (Assert $page) => $page
        ->where('pagination.page', 2)->has('facts', 10)->where('pagination.per_page', 50));
    $this->get(route('career-data.index', ['view' => 'facts', 'per_page' => 7]))->assertInertia(fn (Assert $page) => $page->where('pagination.per_page', 25));
});

it('does not load the facts list unless the facts view is open', function () {
    [$profile, , , $project] = browseProfile();
    browseFact($profile, $project);

    $this->get(route('career-data.index'))->assertInertia(fn (Assert $page) => $page->where('view', 'experience')->where('facts', null)->where('pagination', null));
    $this->get(route('career-data.index', ['view' => 'bogus']))->assertInertia(fn (Assert $page) => $page->where('view', 'experience'));
});

it('searches statements and notes and combines the filters', function () {
    [$profile, $employer, $role, $project] = browseProfile();
    $other = Project::factory()->for($role)->create(['slug' => 'other']);
    browseFact($profile, $project, ['statement' => 'Built a billing system', 'visibility' => Visibility::Public]);
    browseFact($profile, $project, ['statement' => 'Wrote docs', 'notes' => 'about billing', 'visibility' => Visibility::Restricted]);
    browseFact($profile, $other, ['statement' => 'Billing for others', 'visibility' => Visibility::Public]);
    browseFact($profile, $role, ['statement' => 'Unrelated', 'visibility' => Visibility::Public]);

    $total = fn (array $query) => $this->get(route('career-data.index', ['view' => 'facts', ...$query]))->inertiaProps()['pagination']['total'];

    expect($total(['q' => 'billing']))->toBe(3)
        ->and($total(['q' => 'billing', 'visibility' => 'public']))->toBe(2)
        ->and($total(['q' => 'billing', 'project' => 'widget-platform']))->toBe(2)
        ->and($total(['employer' => $employer->id]))->toBe(4)
        ->and($total(['employer' => 999999]))->toBe(0)
        ->and($total(['visibility' => 'nonsense']))->toBe(4)
        ->and($total(['q' => '%']))->toBe(0);
});

it('filters by provenance and reports where each fact came from', function () {
    [$profile, , , $project] = browseProfile();
    $confirmed = browseFact($profile, $project, ['statement' => 'Confirmed by me']);
    Evidence::factory()->for($confirmed, 'careerFact')->create(['source' => EvidenceSource::UserConfirmed, 'confirmed_at' => '2026-09-08']);
    $resume = browseFact($profile, $project, ['statement' => 'From resume']);
    Evidence::factory()->for($resume, 'careerFact')->resume()->create();
    $interview = browseFact($profile, $project, ['statement' => 'From interview']);
    EvidenceProposal::query()->forceCreate([
        'career_profile_id' => $profile->id, 'kind' => ProposalKind::Fact, 'status' => ProposalStatus::Approved, 'career_fact_id' => $interview->id,
        'topic_key' => 't', 'topic_label' => 'T', 'statement' => 's', 'scopes' => [], 'visibility' => 'restricted', 'verification' => 'strongly_supported', 'history' => [],
    ]);
    browseFact($profile, $project, ['statement' => 'No evidence']);

    $statements = fn (string $provenance) => collect($this->get(route('career-data.index', ['view' => 'facts', 'provenance' => $provenance]))->inertiaProps()['facts'])->pluck('statement')->all();

    expect($statements('confirmed'))->toBe(['Confirmed by me'])
        ->and($statements('resume'))->toBe(['From resume'])
        ->and($statements('interview'))->toBe(['From interview'])
        ->and($statements('bogus'))->toHaveCount(4);

    $this->get(route('career-data.index', ['view' => 'facts', 'provenance' => 'confirmed']))->assertInertia(fn (Assert $page) => $page
        ->where('facts.0.confirmed', true)->where('facts.0.confirmed_at', '2026-09-08')->where('facts.0.sources', ['user_confirmed'])
        ->where('facts.0.place', ['Acme Corp', 'Engineer', 'Widget Platform'])->where('facts.0.project_slug', 'widget-platform')
        ->where('counts.confirmed', 1));
});

it('filters by skill and offers only real places as filter options', function () {
    [$profile, $employer, , $project] = browseProfile();
    $php = Skill::factory()->for($profile)->create(['slug' => 'php']);
    $tagged = browseFact($profile, $project, ['statement' => 'Uses PHP']);
    browseFact($profile, $project, ['statement' => 'Other']);
    $php->careerFacts()->attach($tagged->id);

    $this->get(route('career-data.index', ['view' => 'facts', 'skill' => 'php']))->assertInertia(fn (Assert $page) => $page
        ->has('facts', 1)->where('facts.0.statement', 'Uses PHP')->where('facts.0.skills.0.slug', 'php')
        ->where('filter_options.employers.0.id', $employer->id)->where('filter_options.projects.0.slug', 'widget-platform'));
});

it('orders employers and roles newest first', function () {
    [$profile, $employer] = browseProfile();
    Role::factory()->for($employer)->create(['title' => 'Older role', 'start_year' => 2010, 'start_month' => 1]);
    $oldEmployer = Employer::factory()->for($profile)->create(['name' => 'Old Co']);
    Role::factory()->for($oldEmployer)->create(['start_year' => 2000, 'start_month' => 1]);

    $this->get(route('career-data.index'))->assertInertia(fn (Assert $page) => $page
        ->where('employers.0.name', 'Acme Corp')->where('employers.1.name', 'Old Co')
        ->where('employers.0.roles.0.title', 'Engineer')->where('employers.0.roles.1.title', 'Older role'));
});

it('puts metric facts first in a role\'s highlights and caps them at three', function () {
    [$profile, , $role] = browseProfile();
    foreach (range(1, 4) as $i) {
        browseFact($profile, $role, ['statement' => "Bullet {$i}", 'sort_order' => $i]);
    }
    browseFact($profile, $role, ['statement' => 'A metric', 'fact_type' => CareerFactType::Metric, 'sort_order' => 9]);

    $this->get(route('career-data.index'))->assertInertia(fn (Assert $page) => $page
        ->has('employers.0.roles.0.highlights', 3)->where('employers.0.roles.0.highlights.0.statement', 'A metric')
        ->where('employers.0.roles.0.fact_count', 5));
});

it('shows provenance and the project slug on a fact page', function () {
    [$profile, , , $project] = browseProfile();
    $fact = browseFact($profile, $project);
    Evidence::factory()->for($fact, 'careerFact')->create(['source' => EvidenceSource::UserConfirmed, 'confirmed_at' => '2026-09-08']);

    $this->get(route('career-data.facts.show', $fact))->assertInertia(fn (Assert $page) => $page
        ->where('fact.confirmed', true)->where('fact.confirmed_at', '2026-09-08')->where('fact.via_interview', false)->where('fact.project_slug', 'widget-platform')
        ->where('fact.attribution.path', ['Acme Corp', 'Engineer', 'Widget Platform']));
});
