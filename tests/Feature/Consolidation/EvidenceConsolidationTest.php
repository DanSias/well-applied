<?php

use App\Enums\ClarificationSessionStatus;
use App\Models\CareerFact;
use App\Models\ClarificationSession;
use App\Models\EvidenceConsolidation;
use App\Models\EvidenceProposal;
use App\Models\Project;
use App\Models\Skill;
use App\Support\Clarification\EvidenceRevision;
use App\Support\Clarification\ReviewEvidenceProposal;
use App\Support\Clarification\SubmitClarificationAnswer;
use App\Support\Consolidation\ApplyConsolidation;
use App\Support\Consolidation\ConsolidationPlan;
use App\Support\Consolidation\PrepareConsolidation;
use App\Support\Consolidation\PresentConsolidations;
use App\Support\Expansion\SharedClaims;
use App\Support\ResumeProfile\CapabilityCoverage;
use Illuminate\Validation\ValidationException;
use Tests\Support\ResumeProfileFixtures as F;

const REST_ANSWER = 'I\'ve worked extensively with REST APIs, both developing backend application endpoints and integrating external services. For Workflow Intelligence, I built the application backend and integrated Jira and GitLab APIs to retrieve and process engineering workflow data for the frontend and analytics features.';

/**
 * A career with one interview answer that was approved, as-is, as one long fact on the role: the
 * shape the consolidation corrects.
 */
function consolidationScenario(string $answer = REST_ANSWER, string $statement = REST_ANSWER): array
{
    $f = F::career();
    $project = Project::factory()->create(['role_id' => $f['role']->id, 'name' => 'Workflow Intelligence']);
    $session = ClarificationSession::create(['career_profile_id' => $f['career']->id, 'resume_profile_id' => $f['profile']->id, 'mode' => 'profile', 'status' => ClarificationSessionStatus::Pending]);
    $question = $session->questions()->create([
        'topic_key' => 'rest_api_design', 'topic_label' => 'REST API design', 'related_finding_ids' => [], 'score' => 0.5, 'score_breakdown' => [], 'suggested_visibility' => 'restricted', 'status' => 'open',
        'question_text' => 'Did you do REST API design and development?', 'context' => ['kind' => 'deepening'],
    ]);
    $proposal = app(SubmitClarificationAnswer::class)->submit($question, 'experience', ['implemented', 'integrated'], $answer);
    $approved = app(ReviewEvidenceProposal::class)->approve($proposal->fresh(), ['statement' => $statement, 'visibility' => 'restricted', 'attributable_type' => 'role', 'attributable_id' => $f['role']->id]);

    return [...$f, 'project' => $project, 'answer' => $question->answers()->first(), 'fact' => $approved->careerFact, 'proposal' => $approved];
}

function consolidationItem(array $s, array $attributes = []): EvidenceConsolidation
{
    return EvidenceConsolidation::create([
        'career_profile_id' => $s['career']->id, 'code' => 'T-'.EvidenceConsolidation::count(), 'theme' => 'Test', 'action' => 'revise', 'status' => 'proposed', 'sort_order' => 1,
        'title' => 'Test', 'target_fact_id' => $s['fact']->id, 'source_answer_id' => $s['answer']->id, 'topic_key' => 'rest_api_design',
        'statement' => 'Built Workflow Intelligence\'s application backend and integrated the Jira and GitLab APIs to retrieve and process engineering workflow data for the frontend and analytics features.',
        'scopes' => ['implemented', 'integrated'], 'attributable_type' => 'project', 'attributable_id' => $s['project']->id,
        'visibility' => 'restricted', 'verification' => 'strongly_supported', 'history' => [],
        ...$attributes,
    ]);
}

it('revises the wording and place of an approved fact as a new revision, keeping its key, answer and history', function () {
    $s = consolidationScenario();
    $item = consolidationItem($s);
    $revision = EvidenceRevision::current($s['career']);

    app(ApplyConsolidation::class)->approve($item);

    $fact = $s['fact']->fresh();
    expect($fact->key)->toBe($s['fact']->key)
        ->and($fact->statement)->toStartWith('Built Workflow Intelligence')
        ->and($fact->attributable_type)->toBe('project')->and($fact->attributable_id)->toBe($s['project']->id)
        ->and(EvidenceRevision::current($s['career']))->toBeGreaterThan($revision)
        ->and($item->fresh()->status)->toBe('approved')->and($item->fresh()->applied['fact_id'])->toBe($fact->id);

    // The original wording survives as a superseded revision, and every evidence row quotes the user's own answer.
    expect(EvidenceProposal::where('career_fact_id', $fact->id)->where('status', 'superseded')->first()->statement)->toBe(REST_ANSWER)
        ->and($fact->evidence()->pluck('quoted_text')->unique()->all())->toBe([REST_ANSWER])
        ->and($fact->evidence()->count())->toBeGreaterThanOrEqual(2);
});

it('changes nothing until an item is approved, and rejecting leaves the fact exactly as it was', function () {
    $s = consolidationScenario();
    $item = consolidationItem($s);
    $before = $s['fact']->fresh()->only(['statement', 'attributable_type', 'attributable_id', 'visibility']);

    app(PresentConsolidations::class)->present($s['career']->id);
    app(ApplyConsolidation::class)->update($item, ['statement' => 'Built Workflow Intelligence\'s application backend.']);
    expect($s['fact']->fresh()->only(['statement', 'attributable_type', 'attributable_id', 'visibility']))->toEqual($before);

    app(ApplyConsolidation::class)->reject($item);
    expect($item->fresh()->status)->toBe('rejected')
        ->and($s['fact']->fresh()->only(['statement', 'attributable_type', 'attributable_id', 'visibility']))->toEqual($before)
        ->and(fn () => app(ApplyConsolidation::class)->approve($item->fresh()))->toThrow(ValidationException::class);
});

it('refuses wording that claims more than the original answer supports', function () {
    $s = consolidationScenario('I helped configure the webhooks for a merchant.', 'Helped configure webhooks for a merchant.');
    $item = consolidationItem($s, ['statement' => 'Built the webhook handlers for every merchant.', 'scopes' => ['configured'], 'attributable_type' => 'role', 'attributable_id' => $s['role']->id]);

    expect(fn () => app(ApplyConsolidation::class)->approve($item))->toThrow(ValidationException::class)
        ->and($s['fact']->fresh()->statement)->toBe('Helped configure webhooks for a merchant.');
});

it('creates a new fact from the original answer and places it where the user chose', function () {
    $s = consolidationScenario();
    $item = consolidationItem($s, [
        'action' => 'create', 'target_fact_id' => null, 'statement' => 'Worked extensively with REST APIs in full-stack applications, developing backend endpoints and integrating external services.',
        'attributable_type' => 'career_profile', 'attributable_id' => $s['career']->id, 'skills' => ['REST APIs'], 'topic_key' => 'rest_api_design', 'notes' => ['topic_label' => 'REST API design'],
    ]);

    app(ApplyConsolidation::class)->approve($item);

    $created = CareerFact::findOrFail($item->fresh()->applied['fact_id']);
    expect($created->attributable_type)->toBe('career_profile')->and($created->attributable_id)->toBe($s['career']->id)
        ->and($created->evidence()->first()->quoted_text)->toBe(REST_ANSWER)
        ->and($created->skills->pluck('name')->all())->toBe(['REST APIs'])
        // The original fact is untouched.
        ->and($s['fact']->fresh()->statement)->toBe(REST_ANSWER);
});

it('keeps one shared claim across places and refuses a place whose wording disagrees', function () {
    $s = consolidationScenario();
    $other = Project::factory()->create(['role_id' => $s['role']->id, 'name' => 'Nexus']);
    $group = '11111111-1111-4111-8111-111111111111';
    $wording = 'Owned the implementation of the frontend experience alongside the underlying application functionality.';
    $make = fn (int $projectId, string $statement) => consolidationItem($s, ['action' => 'create', 'target_fact_id' => null, 'statement' => $statement, 'attributable_type' => 'project', 'attributable_id' => $projectId, 'claim_group' => $group, 'topic_key' => 'frontend_architecture', 'scopes' => ['implemented']]);
    $first = $make($s['project']->id, $wording);
    $second = $make($other->id, 'Owned the frontend.');

    app(ApplyConsolidation::class)->approve($first);
    expect(fn () => app(ApplyConsolidation::class)->approve($second))->toThrow(ValidationException::class)
        ->and($second->fresh()->status)->toBe('proposed');

    app(ApplyConsolidation::class)->approve(app(ApplyConsolidation::class)->update($second->fresh(), ['statement' => $wording]));

    $facts = CareerFact::where('claim_group', $group)->with('attributable')->get();
    [$kept, $also] = SharedClaims::collapse($facts);
    // Two places, one claim: it is offered once, with the other place listed.
    expect($facts)->toHaveCount(2)->and($kept)->toHaveCount(1)->and(array_values($also)[0])->toHaveCount(1);
});

it('lets an existing fact join a shared claim without losing its history', function () {
    $s = consolidationScenario();
    $group = '22222222-2222-4222-8222-222222222222';
    $sibling = CareerFact::factory()->create(['career_profile_id' => $s['career']->id, 'statement' => 'Developed TypeScript-based applications from the ground up.', 'claim_group' => $group, 'attributable_type' => 'role', 'attributable_id' => $s['role']->id]);
    $item = consolidationItem($s, ['statement' => $sibling->statement, 'claim_group' => $group, 'attributable_type' => 'project', 'attributable_id' => $s['project']->id]);

    app(ApplyConsolidation::class)->approve($item);

    $fact = $s['fact']->fresh();
    expect($fact->claim_group)->toBe($group)->and($fact->key)->toBe($s['fact']->key)
        ->and(EvidenceProposal::where('career_fact_id', $fact->id)->count())->toBe(2);
});

it('will not approve an item whose place is still undecided, and never assumes one', function () {
    $s = consolidationScenario();
    $item = consolidationItem($s, ['attributable_type' => null, 'attributable_id' => null, 'decision' => ['required' => true, 'question' => 'Where?', 'candidates' => []]]);

    expect(fn () => app(ApplyConsolidation::class)->approve($item))->toThrow(ValidationException::class)
        ->and($s['fact']->fresh()->attributable_type)->toBe('role');

    app(ApplyConsolidation::class)->approve($item, ['attributable_type' => 'career_profile', 'attributable_id' => $s['career']->id]);
    expect($s['fact']->fresh()->attributable_type)->toBe('career_profile');
});

it('attaches only skills the fact itself names', function () {
    $s = consolidationScenario();
    $tag = fn (array $skills) => EvidenceConsolidation::create([
        'career_profile_id' => $s['career']->id, 'code' => 'TAG-'.implode('', $skills), 'theme' => 'Test', 'action' => 'tag', 'status' => 'proposed', 'sort_order' => 1, 'title' => 'Tag',
        'fact_ids' => [$s['fact']->id], 'skills' => $skills, 'history' => [],
    ]);

    expect(fn () => app(ApplyConsolidation::class)->approve($tag(['Kubernetes'])))->toThrow(ValidationException::class)
        ->and($s['fact']->fresh()->skills)->toHaveCount(0)
        ->and(Skill::where('name', 'Kubernetes')->exists())->toBeFalse();

    app(ApplyConsolidation::class)->approve($tag(['Jira', 'GitLab']));
    expect($s['fact']->fresh()->skills->pluck('name')->sort()->values()->all())->toBe(['GitLab', 'Jira'])
        ->and($s['fact']->fresh()->statement)->toBe(REST_ANSWER);
});

it('rejects a redundant pending proposal only when its advice is approved', function () {
    $s = consolidationScenario();
    $pending = EvidenceProposal::create([
        'career_profile_id' => $s['career']->id, 'kind' => 'fact', 'status' => 'proposed', 'topic_key' => 'system_design', 'topic_label' => 'System design', 'statement' => 'Built architecture from scratch.',
        'scopes' => ['implemented'], 'visibility' => 'restricted', 'verification' => 'strongly_supported', 'history' => [],
    ]);
    $advice = fn (string $code) => EvidenceConsolidation::create(['career_profile_id' => $s['career']->id, 'code' => $code, 'theme' => 'Test', 'action' => 'advice', 'status' => 'proposed', 'sort_order' => 1, 'title' => 'Advice', 'target_proposal_id' => $pending->id, 'history' => []]);

    $declined = $advice('ADV-A');
    app(ApplyConsolidation::class)->reject($declined);
    expect($pending->fresh()->status->value)->toBe('proposed');

    app(ApplyConsolidation::class)->approve($advice('ADV-B'));
    expect($pending->fresh()->status->value)->toBe('rejected');
});

it('never reproduces Private evidence in the review queue', function () {
    $s = consolidationScenario();
    $item = consolidationItem($s, ['links' => [$s['private']->id]]);

    $payload = app(PresentConsolidations::class)->present($s['career']->id);

    expect($payload['items'][0]['links'][0]['statement'])->toBeNull()
        ->and(json_encode($payload))->not->toContain('ZEBRA-CLIENT-X')
        ->and($payload['items'][0]['original']['statement'])->toBe(REST_ANSWER)
        ->and($payload['items'][0]['proposed']['place']['label'])->toContain('Workflow Intelligence')
        ->and($payload['items'][0]['attribution_change']['from'])->toContain('Developer Support Engineer')
        ->and($payload['counts'])->toBe(['proposed' => 1, 'approved' => 0, 'rejected' => 0])
        ->and($item->fresh()->status)->toBe('proposed');
});

it('shows how the matcher would read the new wording before approval', function () {
    $s = consolidationScenario('We used Docker Compose for local environments, rather than Terraform.', 'Used Docker Compose for local environments, rather than Terraform.');
    consolidationItem($s, ['statement' => 'Used Docker Compose for local environments, rather than Terraform.', 'scopes' => ['implemented']]);

    $matching = app(PresentConsolidations::class)->present($s['career']->id)['items'][0]['matching'];

    expect($matching['direct'])->toContain('Docker and containers')->not->toContain('Infrastructure as code');
});

it('exposes approve, edit and reject over HTTP for the owner only', function () {
    $s = consolidationScenario();
    $item = consolidationItem($s);

    $this->patch(route('evidence-consolidations.update', $item), ['visibility' => 'public'])->assertRedirect();
    expect($item->fresh()->visibility)->toBe('public');

    $this->post(route('evidence-consolidations.approve', $item), [])->assertRedirect();
    expect($item->fresh()->status)->toBe('approved')->and($s['fact']->fresh()->visibility->value)->toBe('public');

    $second = consolidationItem($s, ['code' => 'T-second', 'target_fact_id' => $s['fact']->id, 'statement' => 'Another wording of the backend work.']);
    $this->post(route('evidence-consolidations.reject', $second))->assertRedirect();
    expect($second->fresh()->status)->toBe('rejected')
        ->and($this->post(route('evidence-consolidations.approve', $second))->assertSessionHasErrors('item'));

    $this->patch(route('evidence-consolidations.update', consolidationItem($s, ['code' => 'T-x'])), ['attributable_type' => 'role', 'attributable_id' => 999999])->assertSessionHasErrors('attributable_type');
});

it('puts the consolidation queue on the Resume Profile page', function () {
    $s = consolidationScenario();
    consolidationItem($s);

    $this->get(route('resume-profiles.show', $s['profile']))->assertInertia(fn ($page) => $page
        ->where('resume_profile.consolidation.counts.proposed', 1)
        ->where('resume_profile.consolidation.items.0.code', fn ($code) => str_starts_with($code, 'T-'))
        ->has('resume_profile.consolidation.options'));
});

it('prepares the reviewed plan idempotently, and only as review items', function () {
    $s = consolidationScenario();
    $facts = CareerFact::count();
    $proposals = EvidenceProposal::count();

    // Items whose places are missing report a problem instead of failing; preparing creates review items only.
    $result = app(PrepareConsolidation::class)->run($s['career'], $s['profile']);

    expect($result['problems'])->not->toBeEmpty()->and($result['created'])->toBe(EvidenceConsolidation::count())
        ->and(CareerFact::count())->toBe($facts)->and(EvidenceProposal::count())->toBe($proposals);

    $codes = array_column(ConsolidationPlan::items(), 'code');
    expect($codes)->toBe(array_values(array_unique($codes)))
        ->and(collect(ConsolidationPlan::items())->every(fn ($i) => in_array($i['action'], ['revise', 'create', 'tag', 'advice'], true)))->toBeTrue()
        ->and(ConsolidationPlan::questions())->toHaveCount(5);

    // A second run never duplicates or overwrites what exists.
    $again = app(PrepareConsolidation::class)->run($s['career']);
    expect($again['created'])->toBe(0)->and($again['existing'])->toBe($result['created']);
});

it('reads capabilities from affirmative wording only, and counts passing mentions as underdocumented', function () {
    $coverage = app(CapabilityCoverage::class);

    expect($coverage->capabilitiesIn('Configured Docker and Docker Compose environments rather than cloud provisioning with Terraform or CloudFormation.'))
        ->toContain('docker')->not->toContain('infrastructure_as_code')
        ->and($coverage->capabilitiesIn('Independently owned the platform from initial business requirements through architecture, implementation, testing and delivery.'))->toContain('end_to_end_ownership')
        ->and($coverage->capabilitiesIn('Designed and evolved application architectures, defining component boundaries.'))->toContain('system_design')
        ->and($coverage->capabilitiesIn('Used SQL aggregation queries to calculate totals, reducing unnecessary data transfer.'))->toContain('scalability_performance', 'sql_databases')
        ->and($coverage->capabilitiesIn('Documented merchant payment-gateway integration.'))->toContain('payment_gateways')
        ->and($coverage->capabilitiesIn('Built the application backend, developing REST APIs and integrating Jira.'))->toContain('rest_api_design')
        ->and($coverage->capabilitiesIn('Encrypted gateway credentials at rest.'))->not->toContain('auth_security');
});

it('reports a mention-only capability as underdocumented, never as direct experience', function () {
    $s = F::career();
    $s['webhook']->update(['statement' => 'Kept an eye on monitoring dashboards during launches.']);

    $coverage = app(CapabilityCoverage::class)->compute($s['career'])['capabilities']['observability'];

    expect($coverage['status'])->toBe(CapabilityCoverage::UNDERDOCUMENTED);
});
