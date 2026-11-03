<?php

use App\Enums\ClarificationSessionStatus;
use App\Jobs\ExpandAnswerClaimsJob;
use App\Models\CareerFact;
use App\Models\ClarificationQuestion;
use App\Models\ClarificationSession;
use App\Models\EvidenceProposal;
use App\Models\Project;
use App\Support\Clarification\EvidenceRevision;
use App\Support\Clarification\ReviewEvidenceProposal;
use App\Support\Clarification\SubmitClarificationAnswer;
use App\Support\Expansion\ExpandAnswerClaims;
use App\Support\Expansion\ExpandedClaims;
use App\Support\ResumeProfile\ProfileInterview;
use App\Support\ResumeProfile\QuestionWriter;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Support\FakeExpansionWriter;
use Tests\Support\ResumeProfileFixtures as F;

const TS_ANSWER = 'These projects were all built from the ground up with typescript so integrated it into the project, added types and interfaces and tests';

/**
 * A career with four RocketGate projects, a TypeScript question that NAMED three of them, and the
 * user's real-style answer. Returns the answer and a map of project ids.
 */
function expansionScenario(string $answerText = TS_ANSWER, array $scopes = ['implemented', 'troubleshot']): array
{
    $f = F::career();
    $projects = [];

    foreach (['workflow' => 'Workflow Intelligence', 'verbatim' => 'Verbatim', 'toolkit' => 'Transaction Toolkit', 'exporter' => 'Knowledge Exporter'] as $key => $name) {
        $projects[$key] = Project::factory()->create(['role_id' => $f['role']->id, 'name' => $name]);
    }

    $session = ClarificationSession::create(['career_profile_id' => $f['career']->id, 'resume_profile_id' => $f['profile']->id, 'mode' => 'profile', 'status' => ClarificationSessionStatus::Pending]);
    $question = $session->questions()->create([
        'topic_key' => 'typescript', 'topic_label' => 'TypeScript', 'related_finding_ids' => [], 'score' => 0.5, 'score_breakdown' => [], 'suggested_visibility' => 'restricted', 'status' => 'open',
        'question_text' => 'Your record lists TypeScript on Workflow Intelligence, Verbatim and Transaction Toolkit. Which involved the most substantial work?',
        'context' => ['kind' => 'deepening', 'refs' => array_map(fn ($k) => ['type' => 'project', 'id' => $projects[$k]->id, 'label' => $projects[$k]->name], ['workflow', 'verbatim', 'toolkit'])],
        'suggested_attributable_type' => 'project', 'suggested_attributable_id' => $projects['exporter']->id,
    ]);
    $original = app(SubmitClarificationAnswer::class)->submit($question, 'experience', $scopes, $answerText);

    return [...$f, 'projects' => $projects, 'question' => $question, 'answer' => $question->answers()->first(), 'original' => $original, 'session' => $session];
}

function tsClaim(array $s, array $overrides = []): array
{
    return [
        'capability' => 'typescript',
        'statement' => 'Built RocketGate applications from the ground up in TypeScript, adding types, interfaces and tests.',
        'attributions' => ["project:{$s['projects']['workflow']->id}", "project:{$s['projects']['verbatim']->id}", "project:{$s['projects']['toolkit']->id}"],
        'scopes' => ['implemented'],
        'source_quote' => 'built from the ground up with typescript',
        'confidence' => 'high',
        'rationale' => 'The answer says "these projects", the three named in the question.',
        ...$overrides,
    ];
}

function useWriter(array $content, bool $fail = false): FakeExpansionWriter
{
    $writer = new FakeExpansionWriter($content, $fail);
    app()->instance(QuestionWriter::class, $writer);

    return $writer;
}

it('turns one answer into one claim attributed to every project the answer covers, not just the first match', function () {
    $s = expansionScenario();
    $writer = useWriter(['claims' => [tsClaim($s)], 'followups' => []]);
    $revision = EvidenceRevision::current($s['career']);

    $expansion = app(ExpandAnswerClaims::class)->expand($s['answer']);

    $proposals = EvidenceProposal::where('expansion_id', $expansion->id)->orderBy('id')->get();
    expect($expansion->status)->toBe('ready')
        ->and($proposals)->toHaveCount(3)
        ->and($proposals->pluck('claim_group')->unique())->toHaveCount(1)
        ->and($proposals->pluck('attributable_id')->all())->toBe([$s['projects']['workflow']->id, $s['projects']['verbatim']->id, $s['projects']['toolkit']->id])
        // The Knowledge Exporter (the question's old suggested anchor) is not arbitrarily chosen.
        ->and($proposals->pluck('attributable_id')->all())->not->toContain($s['projects']['exporter']->id)
        ->and($proposals->every(fn ($p) => $p->status->value === 'proposed' && $p->topic_key === 'typescript' && $p->clarification_answer_id === $s['answer']->id))->toBeTrue()
        ->and($proposals->first()->source_quote)->toBe('built from the ground up with typescript')
        ->and($proposals->first()->confidence)->toBe('high')
        ->and($proposals->first()->history[0]['action'])->toBe('proposed')
        // Nothing is approved and the corpus is unchanged.
        ->and(CareerFact::where('key', 'like', '%user-confirmed')->count())->toBe(0)
        ->and(EvidenceRevision::current($s['career']))->toBe($revision)
        // Inference time and usage are recorded.
        ->and($expansion->model)->toBe('fake-model')->and($expansion->latency_ms)->toBeInt()->and($expansion->usage['output_tokens'])->toBe(400)
        ->and($writer->calls)->toBe(1);
    // The original single-fact proposal is untouched and still available as the fallback.
    expect($s['original']->fresh()->status->value)->toBe('proposed');
});

it('sends the model only eligible context: the named projects, listed targets, and no Private evidence', function () {
    $s = expansionScenario();
    $secret = Project::factory()->create(['role_id' => $s['role']->id, 'name' => 'Zebra Sealed Project', 'default_visibility' => 'private']);
    CareerFact::factory()->create(['career_profile_id' => $s['career']->id, 'key' => "zebra-{$s['career']->id}", 'statement' => 'Sealed ZEBRA-CLIENT-X details.', 'visibility' => 'private', 'attributable_type' => 'role', 'attributable_id' => $s['role']->id]);
    $writer = useWriter(['claims' => [], 'followups' => []]);

    app(ExpandAnswerClaims::class)->expand($s['answer']);

    expect($writer->lastUser)->toContain('Workflow Intelligence')->toContain(TS_ANSWER)->toContain('Question capability: typescript')
        ->and($writer->lastUser)->not->toContain('ZEBRA')->not->toContain('Zebra Sealed Project')
        ->and($writer->lastSystem)->toContain('Never invent a technology')->toContain('Never attribute to only the first match')
        ->and($writer->lastSchema['properties']['claims']['items']['properties']['attributions']['items']['enum'])->toContain("project:{$s['projects']['workflow']->id}")->not->toContain("project:{$secret->id}")
        ->and($writer->lastSchema['properties']['claims']['maxItems'])->toBe(8);
});

it('drops claims that go beyond the answer, each with its reason, and keeps the valid ones', function () {
    $s = expansionScenario();
    $w = $s['projects']['workflow']->id;
    useWriter(['claims' => [
        tsClaim($s),
        tsClaim($s, ['capability' => 'kubernetes', 'statement' => 'Deployed the TypeScript projects on Kubernetes clusters.', 'source_quote' => 'built from the ground up']),
        tsClaim($s, ['statement' => 'Built the TypeScript projects and cut errors by 40% across releases.', 'capability' => 'automated_testing', 'source_quote' => 'added types and interfaces and tests', 'attributions' => ["project:{$w}"]]),
        tsClaim($s, ['statement' => 'Reduced production defects substantially through strict typing.', 'capability' => 'automated_testing', 'source_quote' => 'added types and interfaces and tests', 'attributions' => ["project:{$w}"]]),
        tsClaim($s, ['source_quote' => 'a sentence the user never wrote', 'capability' => 'automated_testing', 'attributions' => ["project:{$w}"]]),
        tsClaim($s, ['scopes' => ['advised'], 'capability' => 'automated_testing', 'attributions' => ["project:{$w}"]]),
        tsClaim($s, ['attributions' => ['project:99999'], 'capability' => 'automated_testing']),
        tsClaim($s, ['statement' => 'Built RocketGate applications from the ground up in TypeScript with types, interfaces and tests added.']),
    ], 'followups' => []]);

    $expansion = app(ExpandAnswerClaims::class)->expand($s['answer']);
    $reasons = collect($expansion->result['rejected'])->pluck('reason')->all();

    expect($expansion->result['claims'])->toHaveCount(1)
        ->and($reasons)->toContain('technology "Kubernetes" is not in the answer')
        ->toContain('number not in the answer')
        ->toContain('outcome or metric not in the answer')
        ->toContain('quote not found in the answer')
        ->toContain('scope not selected by the user')
        ->toContain('no valid attribution')
        ->toContain('repeats another claim')
        ->and(EvidenceProposal::where('expansion_id', $expansion->id)->count())->toBe(3);
});

it('keeps strong phrasing the answer supports, and blocks building claimed beyond the selected scope', function () {
    $s = expansionScenario('We configured AWS to host all of the projects', ['configured']);
    useWriter(['claims' => [
        ['capability' => 'cloud_platforms', 'statement' => 'Configured AWS as the hosting environment for the projects.', 'attributions' => ["project:{$s['projects']['workflow']->id}"], 'scopes' => ['configured'], 'source_quote' => 'configured AWS to host all of the projects', 'confidence' => 'high', 'rationale' => 'Stated plainly.'],
        ['capability' => 'cloud_platforms', 'statement' => 'Architected and built the AWS hosting platform for the projects.', 'attributions' => ["project:{$s['projects']['verbatim']->id}"], 'scopes' => ['configured'], 'source_quote' => 'configured AWS to host all of the projects', 'confidence' => 'high', 'rationale' => 'Upgraded verbs.'],
    ], 'followups' => []]);

    $expansion = app(ExpandAnswerClaims::class)->expand($s['answer']);

    expect($expansion->result['claims'])->toHaveCount(1)
        ->and($expansion->result['claims'][0]['statement'])->toContain('Configured AWS as the hosting environment')
        ->and($expansion->result['rejected'][0]['reason'])->toBe('claims more than the selected scope supports');
});

it('caches by answer and evidence revision: asking again is free, new evidence or force re-reads, and only undecided claims are replaced', function () {
    $s = expansionScenario();
    $writer = useWriter(['claims' => [tsClaim($s)], 'followups' => []]);
    $first = app(ExpandAnswerClaims::class)->expand($s['answer']);
    $again = app(ExpandAnswerClaims::class)->expand($s['answer']);

    expect($again->id)->toBe($first->id)->and($writer->calls)->toBe(1);

    // Approve one claim member, then re-read: the approved fact stays, the undecided ones are replaced.
    $approvedMember = EvidenceProposal::where('expansion_id', $first->id)->orderBy('id')->first();
    app(ReviewEvidenceProposal::class)->approve($approvedMember, ['visibility' => 'restricted']);

    $second = app(ExpandAnswerClaims::class)->expand($s['answer']);
    expect($second->id)->not->toBe($first->id)->and($writer->calls)->toBe(2)
        ->and($approvedMember->fresh()->status->value)->toBe('approved')
        ->and(EvidenceProposal::where('expansion_id', $first->id)->where('status', 'superseded')->count())->toBe(2)
        ->and(EvidenceProposal::where('expansion_id', $second->id)->where('status', 'proposed')->count())->toBe(3);

    app(ExpandAnswerClaims::class)->expand($s['answer'], force: true);
    expect($writer->calls)->toBe(3);
});

it('falls back cleanly when the model fails: the original proposal is untouched and nothing is created', function () {
    $s = expansionScenario();
    useWriter([], fail: true);
    $before = EvidenceProposal::count();

    $expansion = app(ExpandAnswerClaims::class)->expand($s['answer']);

    expect($expansion->status)->toBe('failed')->and($expansion->failure)->toContain('original proposal is unchanged')
        ->and(EvidenceProposal::count())->toBe($before)
        ->and($s['original']->fresh()->status->value)->toBe('proposed');
    $this->get(route('resume-profiles.show', $s['profile']))->assertInertia(fn ($page) => $page
        ->where('resume_profile.interview.answers.0.expansion.state', 'failed')
        ->where('resume_profile.interview.answers.0.proposal.status', 'proposed'));
});

it('only experience answers can be expanded', function () {
    $s = expansionScenario();
    $s['answer']->update(['mode' => 'preference']);
    $writer = useWriter(['claims' => [], 'followups' => []]);

    $expansion = app(ExpandAnswerClaims::class)->expand($s['answer']->fresh());

    expect($expansion->status)->toBe('failed')->and($writer->calls)->toBe(0);
});

/** Expands the scenario's answer with one TypeScript claim over the three named projects. */
function expandedClaim(array $s, array $overrides = []): array
{
    $writer = useWriter(['claims' => [tsClaim($s, $overrides)], 'followups' => []]);
    $expansion = app(ExpandAnswerClaims::class)->expand($s['answer']);

    return [$expansion, EvidenceProposal::where('expansion_id', $expansion->id)->orderBy('id')->get(), $writer];
}

it('approves a multi-project claim as one fact per project, each keeping the exact answer, the source quote and the claim group', function () {
    $s = expansionScenario();
    [, $proposals] = expandedClaim($s);
    $group = $proposals->first()->claim_group;
    $revision = EvidenceRevision::current($s['career']);

    $this->post(route('claim-groups.approve', $group), ['visibility' => 'public'])->assertRedirect();

    $facts = CareerFact::where('key', 'like', '%typescript-user-confirmed%')->orderBy('id')->get();
    expect($facts)->toHaveCount(3)
        ->and($facts->pluck('attributable_id')->all())->toBe([$s['projects']['workflow']->id, $s['projects']['verbatim']->id, $s['projects']['toolkit']->id])
        ->and($facts->every(fn ($f) => $f->visibility->value === 'public' && $f->verification->value === 'strongly_supported' && $f->statement === $proposals->first()->statement))->toBeTrue()
        ->and($facts->first()->evidence()->first()->quoted_text)->toBe(TS_ANSWER)
        ->and($facts->first()->evidence()->first()->metadata)->toMatchArray(['origin' => 'clarification', 'topic_key' => 'typescript', 'source_quote' => 'built from the ground up with typescript', 'claim_group' => $group])
        ->and(EvidenceRevision::current($s['career']))->toBeGreaterThan($revision)
        ->and(EvidenceProposal::where('claim_group', $group)->pluck('status')->map->value->unique()->all())->toBe(['approved']);
});

it('requires an explicit visibility and never approves anything without it', function () {
    $s = expansionScenario();
    [, $proposals] = expandedClaim($s);

    $this->post(route('claim-groups.approve', $proposals->first()->claim_group), [])->assertSessionHasErrors('visibility');

    expect(CareerFact::where('key', 'like', '%user-confirmed%')->count())->toBe(0);
});

it('rolls the whole claim back when one place cannot be approved, and says which place', function () {
    $s = expansionScenario();
    [, $proposals] = expandedClaim($s);
    // A fact with the identical wording already exists on Transaction Toolkit.
    CareerFact::factory()->create(['career_profile_id' => $s['career']->id, 'key' => 'tt-existing', 'statement' => $proposals->first()->statement, 'attributable_type' => 'project', 'attributable_id' => $s['projects']['toolkit']->id]);

    $this->post(route('claim-groups.approve', $proposals->first()->claim_group), ['visibility' => 'public'])->assertSessionHasErrors('claim');

    expect(session('errors')->first('claim'))->toContain('Transaction Toolkit')->toContain('already recorded')
        ->and(CareerFact::where('key', 'like', '%typescript-user-confirmed%')->count())->toBe(0)
        ->and(EvidenceProposal::where('claim_group', $proposals->first()->claim_group)->where('status', 'proposed')->count())->toBe(3);
});

it('shows how each claim relates to an existing fact, and replaces an earlier same-topic fact only when told to, keeping its history', function () {
    $s = expansionScenario();
    // The user's earlier answer was approved as ONE fact on Verbatim.
    $original = app(ReviewEvidenceProposal::class)->approve(
        $s['original']->fresh()->forceFill(['attributable_type' => 'project', 'attributable_id' => $s['projects']['verbatim']->id]),
        ['visibility' => 'restricted'],
    );
    $existing = $original->careerFact;
    [, $proposals] = expandedClaim($s);
    $verbatim = $proposals->firstWhere('attributable_id', $s['projects']['verbatim']->id);

    $this->get(route('resume-profiles.show', $s['profile']))->assertInertia(fn ($page) => $page
        ->where('resume_profile.interview.answers.0.expansion.claims.0.members', function ($members) use ($existing) {
            $m = collect($members)->keyBy('attribution');

            return $m['RocketGate: Workflow Intelligence (project)']['existing'] === null
                && $m['RocketGate: Verbatim (project)']['existing']['relation'] === 'same_topic'
                && $m['RocketGate: Verbatim (project)']['existing']['fact_key'] === $existing->key;
        }));

    // Without an explicit replace the whole claim is refused, and the existing fact is untouched.
    $this->post(route('claim-groups.approve', $verbatim->claim_group), ['visibility' => 'restricted'])->assertSessionHasErrors('claim');
    expect($existing->fresh()->statement)->toBe($original->statement)->and(CareerFact::where('key', 'like', '%typescript-user-confirmed%')->count())->toBe(1);

    $this->post(route('claim-groups.approve', $verbatim->claim_group), ['visibility' => 'restricted', 'supersede' => [$verbatim->id]])->assertRedirect();

    $revised = $existing->fresh();
    expect($revised->statement)->toBe($verbatim->statement)
        ->and(EvidenceProposal::where('career_fact_id', $existing->id)->orderBy('id')->pluck('status')->map->value->all())->toContain('superseded', 'approved')
        ->and($revised->evidence()->count())->toBe(2)
        // Workflow and Toolkit got their own new facts.
        ->and(CareerFact::where('key', 'like', '%typescript-user-confirmed%')->count())->toBe(3);
});

it('flags a near-duplicate of a different existing fact on the same project', function () {
    $s = expansionScenario();
    CareerFact::factory()->create(['career_profile_id' => $s['career']->id, 'key' => 'wi-ts', 'statement' => 'Built RocketGate applications from the ground up in TypeScript, adding types, interfaces and tests.', 'visibility' => 'public', 'attributable_type' => 'project', 'attributable_id' => $s['projects']['workflow']->id]);
    expandedClaim($s);

    $this->get(route('resume-profiles.show', $s['profile']))->assertInertia(fn ($page) => $page
        ->where('resume_profile.interview.answers.0.expansion.claims.0.members.0.existing.relation', 'near_duplicate')
        ->where('resume_profile.interview.answers.0.expansion.claims.0.members.0.existing.fact_key', 'wi-ts'));
});

it('never compares against or reveals a Private fact when checking for duplicates', function () {
    $s = expansionScenario();
    CareerFact::factory()->create(['career_profile_id' => $s['career']->id, 'key' => 'secret-ts', 'statement' => 'Built RocketGate applications from the ground up in TypeScript, adding types, interfaces and tests.', 'visibility' => 'private', 'attributable_type' => 'project', 'attributable_id' => $s['projects']['workflow']->id]);
    expandedClaim($s);

    $json = $this->get(route('resume-profiles.show', $s['profile']))->getContent();

    expect($json)->not->toContain('secret-ts');
});

it('lets the user edit the wording and scope, and add or remove places, before approving', function () {
    $s = expansionScenario();
    [, $proposals] = expandedClaim($s);
    $group = $proposals->first()->claim_group;
    $exporter = "project:{$s['projects']['exporter']->id}";

    $this->patch(route('claim-groups.update', $group), [
        'statement' => 'Built the RocketGate applications in TypeScript with types, interfaces and tests.',
        'scopes' => ['implemented'],
        'attributions' => ["project:{$s['projects']['workflow']->id}", $exporter],
    ])->assertRedirect();

    $open = EvidenceProposal::where('claim_group', $group)->where('status', 'proposed')->get();
    expect($open->pluck('attributable_id')->sort()->values()->all())->toBe(collect([$s['projects']['workflow']->id, $s['projects']['exporter']->id])->sort()->values()->all())
        ->and($open->every(fn ($p) => $p->statement === 'Built the RocketGate applications in TypeScript with types, interfaces and tests.'))->toBeTrue()
        ->and(EvidenceProposal::where('claim_group', $group)->where('status', 'rejected')->count())->toBe(2)
        ->and($open->firstWhere('attributable_id', $s['projects']['exporter']->id)->source_quote)->toBe('built from the ground up with typescript');

    $this->patch(route('claim-groups.update', $group), ['attributions' => ['employer:1']])->assertSessionHasErrors('attributions');
});

it('rejects a whole claim without touching the answer\'s other proposals or marking the question skipped', function () {
    $s = expansionScenario();
    [, $proposals] = expandedClaim($s);

    $this->post(route('claim-groups.reject', $proposals->first()->claim_group))->assertRedirect();

    expect(EvidenceProposal::where('claim_group', $proposals->first()->claim_group)->pluck('status')->map->value->unique()->all())->toBe(['rejected'])
        ->and($s['original']->fresh()->status->value)->toBe('proposed')
        ->and($s['question']->fresh()->status)->toBe('answered');
});

it('merges two claims into one: places move across, duplicates are dropped, wording is the target\'s', function () {
    $s = expansionScenario();
    $w = "project:{$s['projects']['workflow']->id}";
    $e = "project:{$s['projects']['exporter']->id}";
    useWriter(['claims' => [
        tsClaim($s, ['attributions' => [$w, "project:{$s['projects']['verbatim']->id}"]]),
        tsClaim($s, ['capability' => 'automated_testing', 'statement' => 'Added automated tests to the RocketGate TypeScript projects.', 'source_quote' => 'added types and interfaces and tests', 'attributions' => [$w, $e]]),
    ], 'followups' => []]);
    $expansion = app(ExpandAnswerClaims::class)->expand($s['answer']);
    $groups = EvidenceProposal::where('expansion_id', $expansion->id)->get()->groupBy('claim_group')->keys()->all();

    $this->post(route('claim-groups.merge', $groups[1]), ['into' => $groups[0]])->assertRedirect();

    $open = EvidenceProposal::where('claim_group', $groups[0])->where('status', 'proposed')->get();
    expect($open->pluck('attributable_id')->sort()->values()->all())->toBe(collect([$s['projects']['workflow']->id, $s['projects']['verbatim']->id, $s['projects']['exporter']->id])->sort()->values()->all())
        ->and($open->pluck('topic_key')->unique()->all())->toBe(['typescript'])
        ->and(EvidenceProposal::where('claim_group', $groups[1])->where('status', 'rejected')->count())->toBe(1);
    $this->post(route('claim-groups.merge', $groups[0]), ['into' => $groups[0]])->assertSessionHasErrors('claim');
});

it('suggests follow-up questions only as options, and adds one to the interview only when asked; it survives a re-rank', function () {
    $s = expansionScenario();
    $writer = useWriter(['claims' => [tsClaim($s)], 'followups' => [
        ['capability' => 'typescript', 'question' => 'What specific technical problems did your types and interfaces solve on these projects?'],
        ['capability' => 'automated_testing', 'question' => 'What kind of tests did you write, and did the team adopt your testing patterns?'],
        ['capability' => 'typescript', 'question' => 'No question mark here so this is dropped'],
    ]]);
    $expansion = app(ExpandAnswerClaims::class)->expand($s['answer']);
    $count = ClarificationQuestion::count();

    expect($expansion->result['followups'])->toHaveCount(2)->and(ClarificationQuestion::count())->toBe($count);

    $this->post(route('expansions.followups.ask', [$expansion, 1]))->assertRedirect();
    $this->post(route('expansions.followups.ask', [$expansion, 1]))->assertRedirect();

    $asked = ClarificationQuestion::where('context->source', 'followup')->sole();
    expect($asked->topic_key)->toBe('automated_testing')->and($asked->status)->toBe('open')->and(ClarificationQuestion::count())->toBe($count + 1);

    EvidenceRevision::bump($s['career']);
    app(ProfileInterview::class)->sync($s['profile']->refresh());
    expect($asked->fresh()->status)->toBe('open');
    $this->post(route('expansions.followups.ask', [$expansion, 9]))->assertSessionHasErrors('followup');
});

it('queues an expansion only when the user asks, once at a time, and never calls a model on a page load', function () {
    Queue::fake();
    $s = expansionScenario();
    $writer = useWriter(['claims' => [], 'followups' => []]);

    $this->get(route('resume-profiles.show', $s['profile']))->assertInertia(fn ($page) => $page
        ->where('resume_profile.interview.answers.0.expansion.state', 'none')->where('resume_profile.interview.answers.0.expansion.can_expand', true));
    expect($writer->calls)->toBe(0);

    $this->post(route('answers.expand', $s['answer']))->assertRedirect();
    $this->post(route('answers.expand', $s['answer']))->assertRedirect();
    Queue::assertPushed(ExpandAnswerClaimsJob::class, 1);
    $this->get(route('resume-profiles.show', $s['profile']))->assertInertia(fn ($page) => $page->where('resume_profile.interview.answers.0.expansion.state', 'running'));

    (new ExpandAnswerClaimsJob($s['answer']->id))->handle(app(ExpandAnswerClaims::class));
    expect($writer->calls)->toBe(1);
});

it('refuses claim groups and answers that belong to another career profile', function () {
    $s = expansionScenario();
    [, $proposals] = expandedClaim($s);
    $other = F::career();
    EvidenceProposal::where('claim_group', $proposals->first()->claim_group)->update(['career_profile_id' => $other['career']->id]);

    $this->post(route('claim-groups.reject', $proposals->first()->claim_group))->assertNotFound();
    $this->post(route('claim-groups.approve', 'no-such-group'), ['visibility' => 'public'])->assertNotFound();
});

it('keeps previously approved facts and their revisions intact through the whole flow', function () {
    $s = expansionScenario();
    $approved = app(ReviewEvidenceProposal::class)->approve($s['original']->fresh()->forceFill(['attributable_type' => 'project', 'attributable_id' => $s['projects']['exporter']->id]), ['visibility' => 'public']);
    $before = [$approved->careerFact->statement, $approved->careerFact->evidence()->count(), $approved->careerFact->key];
    [, $proposals] = expandedClaim($s);

    app(ExpandedClaims::class)->approve($proposals->first()->claim_group, 'restricted');
    app(ExpandedClaims::class)->reject('nonexistent');

    $fact = $approved->careerFact->fresh();
    expect([$fact->statement, $fact->evidence()->count(), $fact->key])->toBe($before)
        ->and($approved->fresh()->status->value)->toBe('approved')
        ->and(fn () => app(ExpandedClaims::class)->approve($proposals->first()->claim_group, 'restricted'))->toThrow(ValidationException::class);
});

it('drops scale and breadth qualifiers the answer never used', function () {
    $s = expansionScenario('We configured AWS to host all of the projects', ['configured']);
    $w = "project:{$s['projects']['workflow']->id}";
    useWriter(['claims' => [
        ['capability' => 'cloud_platforms', 'statement' => 'Configured AWS as the hosting environment across multiple client engagements.', 'attributions' => [$w], 'scopes' => ['configured'], 'source_quote' => 'configured AWS to host all of the projects', 'confidence' => 'high', 'rationale' => 'x'],
        ['capability' => 'cloud_platforms', 'statement' => 'Configured AWS as the hosting environment for all of the projects.', 'attributions' => [$w], 'scopes' => ['configured'], 'source_quote' => 'configured AWS to host all of the projects', 'confidence' => 'high', 'rationale' => 'x'],
    ], 'followups' => []]);

    $expansion = app(ExpandAnswerClaims::class)->expand($s['answer']);

    expect($expansion->result['claims'])->toHaveCount(1)
        ->and($expansion->result['rejected'][0]['reason'])->toBe('qualifier "multiple" is not in the answer');
});

it('keeps the answer\'s own proposal on the answer card even when expansion claims exist', function () {
    $s = expansionScenario();
    expandedClaim($s);

    $this->get(route('resume-profiles.show', $s['profile']))->assertInertia(fn ($page) => $page
        ->where('resume_profile.interview.answers.0.proposal.id', $s['original']->id)
        ->where('resume_profile.interview.answers.0.proposal.statement', $s['original']->statement));
});
