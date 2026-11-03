<?php

use App\Models\CareerFact;
use App\Models\ClarificationQuestion;
use App\Models\EvidenceProposal;
use App\Models\Project;
use App\Models\Skill;
use App\Support\ResumeProfile\CapabilityCoverage;
use App\Support\ResumeProfile\ProfileInterview;
use Tests\Support\ResumeProfileFixtures as F;

/** Real projects with a TypeScript Skill but facts that never say so, plus API and schema design work. */
function projectCareer(): array
{
    $f = F::career();
    $f['react']->update(['statement' => 'Built React interfaces for merchant dashboards.']);
    $workflow = Project::factory()->create(['role_id' => $f['role']->id, 'name' => 'Workflow Intelligence']);
    $toolkit = Project::factory()->create(['role_id' => $f['role']->id, 'name' => 'Transaction Toolkit']);
    $make = fn (string $key, string $statement, Project $p) => CareerFact::factory()->create([
        'career_profile_id' => $f['career']->id, 'key' => "{$key}-{$f['career']->id}", 'statement' => $statement, 'verification' => 'strongly_supported', 'visibility' => 'public',
        'attributable_type' => 'project', 'attributable_id' => $p->id,
    ]);
    $ts = Skill::factory()->create(['career_profile_id' => $f['career']->id, 'name' => 'TypeScript']);
    $make('wi-platform', 'Built a Laravel and Vue platform that gives teams visibility into work items.', $workflow)->skills()->attach($ts);
    $make('tt-api', 'Designed REST APIs for payment transaction data.', $toolkit);
    $make('tt-schema', 'Designed the database schema and migrations for transaction records.', $toolkit);

    return [...$f, 'workflow' => $workflow, 'toolkit' => $toolkit];
}

function interviewFor(array $f, array $specs)
{
    F::sampleWith($f['profile'], $specs);
    app(ProfileInterview::class)->sync($f['profile']->refresh());

    return ClarificationQuestion::query()->get()->keyBy('topic_key');
}

it('treats a known skill with no substance as evidence deepening, and asks for the substantial example', function () {
    $f = projectCareer();
    $q = interviewFor($f, array_fill(0, 4, ['required' => ['Strong TypeScript experience.', 'Build React interfaces.']]))['typescript'];

    expect($q->context['kind'])->toBe('deepening')
        ->and($q->context['coverage'])->toBe(CapabilityCoverage::UNDERDOCUMENTED)
        ->and($q->question_text)->toContain('Your record lists TypeScript on Workflow Intelligence')
        ->and($q->question_text)->toContain('Which of your applications involved the most substantial TypeScript work?')
        ->and($q->question_text)->toContain('What did you personally design or implement')
        ->and($q->question_text)->toContain('quantify')
        ->and($q->question_text)->not->toContain('Have you worked with')
        ->and($q->suggested_attributable_type)->toBe('project')
        ->and($q->suggested_attributable_id)->toBe($f['workflow']->id)
        ->and($q->context['refs'][0]['label'])->toContain('Workflow Intelligence');
});

it('asks about a documented but thin capability to deepen it, at a lower weight than a genuine gap', function () {
    $f = projectCareer();
    $questions = interviewFor($f, array_fill(0, 4, ['required' => ['Strong REST API design.', 'Experience with Kubernetes.']]));

    $api = $questions['rest_api_design'];
    $k8s = $questions['kubernetes'];

    expect($api->context['kind'])->toBe('deepening')
        ->and($api->context['depth'])->toBe('shallow')
        ->and($api->question_text)->toContain('Your record mentions REST API design and development on Transaction Toolkit')
        ->and($api->question_text)->toContain('Designed REST APIs for payment transaction data')
        ->and($k8s->context['kind'])->toBe('discovery')
        ->and($k8s->question_text)->toContain('Nothing in your record covers this yet')
        ->and($k8s->question_text)->not->toContain('substantial')
        ->and($api->score_breakdown['gap'])->toEqual(0.6)
        ->and($k8s->score_breakdown['gap'])->toEqual(1.0);
});

it('does not ask about a capability that is documented in depth', function () {
    $f = projectCareer();
    foreach (['a', 'b', 'c'] as $n) {
        CareerFact::factory()->create(['career_profile_id' => $f['career']->id, 'key' => "api-extra-{$n}-{$f['career']->id}", 'statement' => "Built REST API feature {$n}.", 'verification' => 'strongly_supported', 'visibility' => 'public', 'attributable_type' => 'project', 'attributable_id' => $n === 'a' ? $f['workflow']->id : $f['toolkit']->id]);
    }

    $questions = interviewFor($f, array_fill(0, 4, ['required' => ['Strong REST API design.']]));

    expect($questions)->not->toHaveKey('rest_api_design');
});

it('names real projects when asking about system design, from related work', function () {
    $f = projectCareer();
    $q = interviewFor($f, array_fill(0, 4, ['required' => ['Write system design documents.']]))['system_design'];

    expect($q->context['kind'])->toBe('discovery')
        ->and($q->context['coverage'])->toBe(CapabilityCoverage::TRANSFERABLE)
        ->and($q->question_text)->toContain('Your record has related work on Transaction Toolkit')
        ->and($q->question_text)->toContain('Did any of that include system or architecture design')
        ->and($q->context['refs'][0]['type'])->toBe('project');
});

it('never mixes in Private evidence or its project when naming references', function () {
    $f = projectCareer();
    $secret = Project::factory()->create(['role_id' => $f['role']->id, 'name' => 'Zebra Sealed Project']);
    CareerFact::factory()->create(['career_profile_id' => $f['career']->id, 'key' => "zebra-{$f['career']->id}", 'statement' => 'Designed REST APIs for the sealed project.', 'visibility' => 'private', 'verification' => 'strongly_supported', 'attributable_type' => 'project', 'attributable_id' => $secret->id]);

    $questions = interviewFor($f, array_fill(0, 4, ['required' => ['Strong REST API design.']]));

    expect(json_encode($questions->values()))->not->toContain('Zebra');
});

it('still turns an approved deepening answer into shared career evidence attributed to the project', function () {
    $f = projectCareer();
    $q = interviewFor($f, array_fill(0, 4, ['required' => ['Strong TypeScript experience.']]))['typescript'];

    $this->post(route('resume-profiles.answer', [$f['profile'], $q]), ['mode' => 'experience', 'scopes' => ['implemented'], 'answer_text' => 'I designed the typed API client and the shared domain types for Workflow Intelligence, which removed a class of runtime errors.'])->assertRedirect();
    $proposal = EvidenceProposal::sole();
    expect($proposal->attributable_type)->toBe('project')->and($proposal->attributable_id)->toBe($f['workflow']->id)->and($proposal->visibility->value)->toBe('public');

    $this->post(route('evidence-proposals.approve', $proposal), ['visibility' => 'public'])->assertRedirect();
    $fact = CareerFact::where('key', 'like', '%typescript-user-confirmed')->sole();

    expect($fact->attributable_id)->toBe($f['workflow']->id)
        ->and($fact->evidence()->first()->quoted_text)->toContain('typed API client');
    // The topic now has approved evidence, so it is not asked again.
    app(ProfileInterview::class)->sync($f['profile']->refresh());
    expect($q->fresh()->status)->toBe('answered');
});

it('anchors an architecture question on the best-documented real projects when nothing closer is known', function () {
    $f = projectCareer();
    $q = interviewFor($f, array_fill(0, 4, ['required' => ['Own the architecture of our platform.']]))['architecture_ownership'];

    expect($q->context['coverage'])->toBe(CapabilityCoverage::NONE)
        ->and($q->question_text)->toContain('Thinking of work such as')
        ->and($q->question_text)->toContain('Transaction Toolkit (RocketGate)')
        ->and($q->question_text)->toContain('did you do owning architecture or technical direction')
        ->and($q->question_text)->not->toContain('Have you done anything like');
});

it('brings a retired question back when its topic ranks again, and retired questions do not use up the topic budget', function () {
    $f = projectCareer();
    config(['resume_profiles.profiles.full-stack-product.interview.max_topics' => 2]);
    $interview = app(ProfileInterview::class);
    F::sampleWith($f['profile'], array_fill(0, 4, ['required' => ['Strong Kubernetes experience.', 'Strong Terraform experience.']]));
    $interview->sync($f['profile']->refresh());
    $kubernetes = ClarificationQuestion::where('topic_key', 'kubernetes')->sole();

    // The market stops asking for Kubernetes: retired, and its slot is free for another topic.
    F::sampleWith($f['profile'], array_fill(0, 4, ['required' => ['Strong GraphQL experience.', 'Strong Terraform experience.', 'Strong Redis experience.']]));
    $interview->sync($f['profile']->refresh());
    expect($kubernetes->fresh()->status)->toBe('retired')
        ->and(ClarificationQuestion::whereNotIn('status', ['retired', 'covered'])->count())->toBe(2);

    F::sampleWith($f['profile'], array_fill(0, 4, ['required' => ['Strong Kubernetes experience.']]));
    $interview->sync($f['profile']->refresh());
    expect($kubernetes->fresh()->status)->toBe('open');
});
