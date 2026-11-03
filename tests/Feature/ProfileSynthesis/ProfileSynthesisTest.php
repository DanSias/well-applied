<?php

use App\Contracts\GeneratesResumeWording;
use App\Enums\CareerFactType;
use App\Enums\Verification;
use App\Enums\Visibility;
use App\Jobs\GenerateReferenceResumeJob;
use App\Jobs\SynthesizeProfileJob;
use App\Models\CareerFact;
use App\Models\CareerLimitation;
use App\Models\Employer;
use App\Models\JobMatch;
use App\Models\Project;
use App\Models\ResumeProfileSynthesis;
use App\Models\Role;
use App\Models\Skill;
use App\Support\Clarification\EvidenceRevision;
use App\Support\ProfileSynthesis\EffectiveProfile;
use App\Support\ProfileSynthesis\GenerateReferenceResume;
use App\Support\ProfileSynthesis\ProfileAssembly;
use App\Support\ProfileSynthesis\ProfileSynthesisPrompt;
use App\Support\ProfileSynthesis\ReferenceSelection;
use App\Support\ProfileSynthesis\ReviewProfileSynthesis;
use App\Support\ProfileSynthesis\SynthesizeProfile;
use App\Support\ResumeProfile\QuestionWriter;
use App\Support\ResumeVariant\GenerateResumeVariant;
use App\Support\ResumeVariant\ResumeCandidatePayloadBuilder;
use App\Support\ResumeVariant\ResumeWordingProviderResponse;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Support\FakeSequenceWriter;
use Tests\Support\ResumeProfileFixtures as F;

/**
 * A career shaped like the real one in miniature: an owned flagship project, a measured analytics
 * project at a second employer, a personal project and a whole-career personal fact, a first-person
 * answer, a shared claim, a restricted fact with a figure, a Private fact, and a confirmed limitation.
 */
function synthesisScenario(): array
{
    $f = F::career();
    $career = $f['career'];
    $skill = fn (string $name) => Skill::factory()->create(['career_profile_id' => $career->id, 'name' => $name, 'slug' => strtolower($name)]);
    $fact = function (string $key, string $statement, string $type, int $id, Visibility $v = Visibility::Public, Verification $verification = Verification::Verified, array $skills = [], ?string $group = null) use ($career) {
        $fact = CareerFact::factory()->create([
            'career_profile_id' => $career->id, 'key' => $key, 'statement' => $statement, 'visibility' => $v, 'verification' => $verification, 'fact_type' => CareerFactType::Bullet,
            'attributable_type' => $type, 'attributable_id' => $id, 'claim_group' => $group,
        ]);
        $fact->skills()->sync(array_map(fn (string $n) => Skill::query()->where('career_profile_id', $career->id)->where('name', $n)->firstOrFail()->id, $skills));

        return $fact;
    };

    foreach (['TypeScript', 'React', 'Node.js', 'BigQuery'] as $name) {
        $skill($name);
    }

    $pearson = Role::factory()->create(['employer_id' => Employer::factory()->create(['career_profile_id' => $career->id, 'name' => 'Pearson Online'])->id, 'title' => 'Data Lead Developer', 'start_year' => 2015, 'end_year' => 2024]);
    $flagship = Project::factory()->create(['role_id' => $f['role']->id, 'name' => 'Workflow Intelligence']);
    $trivial = Project::factory()->create(['role_id' => $f['role']->id, 'name' => 'Many Small Things']);
    $nexus = Project::factory()->create(['role_id' => $pearson->id, 'name' => 'Nexus']);
    $personal = Project::factory()->create(['role_id' => null, 'career_profile_id' => $career->id, 'name' => 'Well Prompted']);
    $group = '33333333-3333-4333-8333-333333333333';

    $facts = [
        'owned' => $fact('wi-owned', 'Independently owned Workflow Intelligence from business requirements through architecture, implementation, testing and delivery.', 'project', $flagship->id, skills: ['TypeScript']),
        'deterministic' => $fact('wi-deterministic', 'Designed Workflow Intelligence around deterministic, evidence-linked metrics, each computed once and reused everywhere it appears.', 'project', $flagship->id),
        'ts-wi' => $fact('ts-wi', 'Developed TypeScript-based applications from the ground up, with typed interfaces and automated tests.', 'project', $flagship->id, group: $group),
        'ts-trivial' => $fact('ts-trivial', 'Developed TypeScript-based applications from the ground up, with typed interfaces and automated tests.', 'project', $trivial->id, group: $group),
        'nexus-built' => $fact('nexus-built', 'Built Nexus, a full-stack React and Node.js analytics platform unifying BigQuery and Salesforce data behind a single API layer.', 'project', $nexus->id, skills: ['React', 'Node.js', 'BigQuery']),
        'nexus-85' => $fact('nexus-85', 'Nexus reduced reporting time by 85%.', 'project', $nexus->id),
        'personal-docker' => $fact('personal-docker', 'Built and maintained a personal, self-hosted application environment using Docker Compose.', 'career_profile', $career->id),
        'personal-project' => $fact('well-prompted', 'Built a prompt library with React and Node.js that keeps AI-assisted development consistent.', 'project', $personal->id, skills: ['React']),
        'first-person' => $fact('first-person', "I've used JavaScript extensively throughout my career.", 'role', $f['role']->id),
        'restricted' => $fact('remediation', 'Corrected approximately 32,000 transaction records for a merchant through idempotent, audited writes.', 'role', $f['role']->id, Visibility::Restricted),
        'private' => $fact('private-secret', 'Handled the SECRET-PROJECT-ZEBRA outage.', 'role', $f['role']->id, Visibility::Private),
    ];

    for ($i = 1; $i <= 6; $i++) {
        $fact("trivia-{$i}", "Used a small utility number {$i} at work.", 'project', $trivial->id);
    }

    CareerLimitation::create(['career_profile_id' => $career->id, 'topic_key' => 'ruby_on_rails', 'topic' => 'Ruby on Rails', 'terms' => ['Ruby on Rails', 'Rails'], 'status' => 'no_direct_experience', 'source' => 'user_confirmation', 'confirmed_at' => now()]);
    F::sampleWith($f['profile'], array_fill(0, 3, ['required' => ['TypeScript', 'React', 'Node.js'], 'duties' => ['Design and build REST APIs.']]));

    return [...$f, 'facts' => $facts, 'projects' => compact('flagship', 'trivial', 'nexus', 'personal'), 'pearson' => $pearson, 'group' => $group];
}

function goodPresentation(array $overrides = []): array
{
    return [
        'headline' => 'Full-Stack Developer',
        'summary' => 'Full-Stack Developer who independently owned Workflow Intelligence from business requirements through architecture, implementation, testing and delivery. Designed it around deterministic, evidence-linked metrics reused everywhere they appear. At Pearson Online, built Nexus, a full-stack React and Node.js analytics platform unifying BigQuery and Salesforce data, reducing reporting time by 85%.',
        'summary_fact_keys' => ['wi-owned', 'wi-deterministic', 'nexus-built', 'nexus-85'],
        'differentiators' => [
            ['statement' => 'Owned Workflow Intelligence end to end, from business requirements to delivery.', 'fact_keys' => ['wi-owned']],
            ['statement' => 'Designs around deterministic, evidence-linked metrics computed once and reused.', 'fact_keys' => ['wi-deterministic']],
            ['statement' => 'Built a full-stack analytics platform that reduced reporting time by 85%.', 'fact_keys' => ['nexus-built', 'nexus-85']],
        ],
        'project_notes' => [['project_id' => 0, 'note' => 'Owned end to end.', 'fact_keys' => ['wi-owned']]],
        ...$overrides,
    ];
}

function useSynthesisWriter(array $contents, bool $fail = false): FakeSequenceWriter
{
    $writer = new FakeSequenceWriter($contents, $fail);
    app()->instance(QuestionWriter::class, $writer);

    return $writer;
}

function synthesize(array $s, ?array $presentation = null): ResumeProfileSynthesis
{
    $presentation ??= goodPresentation(['project_notes' => [['project_id' => $s['projects']['flagship']->id, 'note' => 'Owned end to end.', 'fact_keys' => ['wi-owned']]]]);
    useSynthesisWriter([$presentation]);

    return app(SynthesizeProfile::class)->synthesize($s['profile']);
}

it('ranks projects on engineering signals, never on how many facts they have', function () {
    $s = synthesisScenario();
    $inventory = app(ProfileAssembly::class)->assemble($s['profile']);
    $names = array_column($inventory['projects'], 'name');
    $trivial = collect($inventory['projects'])->firstWhere('name', 'Many Small Things');
    $flagship = collect($inventory['projects'])->firstWhere('name', 'Workflow Intelligence');

    // Eight facts against three: the owned, designed flagship still outranks the pile of trivia.
    expect(count($trivial['fact_keys']))->toBeGreaterThan(count($flagship['fact_keys']))
        ->and($flagship['score'])->toBeGreaterThan($trivial['score'])
        ->and(array_search('Workflow Intelligence', $names))->toBeLessThan(array_search('Many Small Things', $names))
        ->and($flagship['signals'])->toHaveKeys(['ownership', 'architecture', 'complexity', 'relevance', 'distinctiveness', 'outcomes'])
        // Employment first; the personal project comes after every employment project.
        ->and(end($names))->toBe('Well Prompted');
});

it('keeps Private evidence out entirely and flags what was not used as written', function () {
    $s = synthesisScenario();
    $inventory = app(ProfileAssembly::class)->assemble($s['profile']);

    expect(json_encode($inventory))->not->toContain('SECRET-PROJECT-ZEBRA')->and($inventory['facts'])->not->toHaveKey('private-secret')
        ->and($inventory['private_omitted'])->toBe(2)
        ->and(array_column($inventory['excluded'], 'fact_key'))->toContain('first-person')
        ->and($inventory['facts'])->not->toHaveKey('first-person');
});

it('counts a shared claim once, under the place whose project ranks highest, and keeps whole-career facts out of the bullets', function () {
    $s = synthesisScenario();
    $inventory = app(ProfileAssembly::class)->assemble($s['profile']);
    $keys = array_column($inventory['accomplishments'], 'fact_key');

    expect(array_intersect($keys, ['ts-wi', 'ts-trivial']))->toBe([array_values(array_intersect($keys, ['ts-wi', 'ts-trivial']))[0]])
        ->and($keys)->toContain('ts-wi')->not->toContain('ts-trivial')
        ->and($keys)->not->toContain('personal-docker')
        ->and($inventory['facts']['personal-docker'])->toMatchArray(['whole_career' => true, 'personal' => true])
        // Each place of the claim still counts as evidence for its own project.
        ->and(collect($inventory['projects'])->firstWhere('name', 'Many Small Things')['fact_keys'])->toContain('ts-trivial');
});

it('keeps direct capabilities apart from related ones', function () {
    $s = synthesisScenario();
    $capabilities = app(ProfileAssembly::class)->assemble($s['profile'])['capabilities'];

    expect(array_column($capabilities['direct'], 'key'))->toContain('typescript', 'react')->and(array_column($capabilities['transferable'], 'key'))->not->toContain('typescript');
});

it('shows the model only eligible, figure-safe evidence', function () {
    $s = synthesisScenario();
    $inventory = app(ProfileAssembly::class)->assemble($s['profile']);
    $input = json_encode(app(ProfileSynthesisPrompt::class)->input($inventory, $s['career']));

    expect($input)->not->toContain('SECRET-PROJECT-ZEBRA')->and($input)->not->toContain('32,000')->and($input)->toContain('[figure withheld]')
        ->and($input)->toContain('Ruby on Rails')->and($input)->toContain('limitations_never_mention');
});

it('synthesizes a draft profile with one model call and reuses it when nothing changed', function () {
    $s = synthesisScenario();
    $writer = useSynthesisWriter([goodPresentation(['project_notes' => [['project_id' => $s['projects']['flagship']->id, 'note' => 'Owned end to end.', 'fact_keys' => ['wi-owned']]]])]);

    $synthesis = app(SynthesizeProfile::class)->synthesize($s['profile']);

    expect($synthesis->status)->toBe('draft')->and($synthesis->version)->toBe(1)->and($synthesis->pilot)->toBeTrue()->and($synthesis->usage['model_calls'])->toBe(1)
        ->and($synthesis->evidence_revision)->toBe(EvidenceRevision::current($s['career']))
        ->and($synthesis->content)->toHaveKeys(['inventory', 'presentation'])->and($synthesis->generated_by)->toBe('ollama:fake-model');

    $again = app(SynthesizeProfile::class)->synthesize($s['profile']);

    expect($again->id)->toBe($synthesis->id)->and($writer->calls)->toBe(1);
});

it('rejects language the evidence does not support, and retries once with the exact problems', function () {
    $s = synthesisScenario();
    $bad = goodPresentation([
        'summary' => 'Full-Stack Developer with 12 years of experience who built Kubernetes platforms and Ruby on Rails services, cutting cloud costs by 40% for Pearson Online and RocketGate customers worldwide.',
        'summary_fact_keys' => ['wi-owned', 'nexus-85'],
        'differentiators' => [['statement' => 'Corrected 32,000 records.', 'fact_keys' => ['remediation']], ['statement' => 'Owned the thing.', 'fact_keys' => ['not-a-fact']], ['statement' => 'Built Nexus.', 'fact_keys' => ['nexus-built']]],
    ]);
    $writer = useSynthesisWriter([$bad, $bad]);

    expect(fn () => app(SynthesizeProfile::class)->synthesize($s['profile']))->toThrow(RuntimeException::class, 'failed validation');

    $problems = (string) $writer->lastUser;
    expect($writer->calls)->toBe(2)->and(ResumeProfileSynthesis::count())->toBe(0)
        ->and($problems)->toContain('years')->and($problems)->toContain('not an eligible fact')->and($problems)->toContain('32000')
        ->and($problems)->toContain('Kubernetes')->and($problems)->toContain('Ruby on Rails');
});

it('recovers when the second attempt fixes the first', function () {
    $s = synthesisScenario();
    $good = goodPresentation(['project_notes' => [['project_id' => $s['projects']['flagship']->id, 'note' => 'Owned end to end.', 'fact_keys' => ['wi-owned']]]]);
    $writer = useSynthesisWriter([goodPresentation(['headline' => 'I am a Full-Stack Developer']), $good]);

    $synthesis = app(SynthesizeProfile::class)->synthesize($s['profile']);

    expect($writer->calls)->toBe(2)->and($synthesis->usage['model_calls'])->toBe(2)->and($synthesis->content['presentation']['headline'])->toBe('Full-Stack Developer');
});

it('will not let a statement upgrade the evidence\'s verb to a stronger ownership claim', function () {
    $s = synthesisScenario();
    $presentation = goodPresentation([
        'differentiators' => [
            ['statement' => 'Architected Nexus as a full-stack analytics platform.', 'fact_keys' => ['nexus-built']],
            ['statement' => 'Owned Workflow Intelligence end to end, from business requirements to delivery.', 'fact_keys' => ['wi-owned']],
            ['statement' => 'Built Nexus.', 'fact_keys' => ['nexus-built']],
        ],
    ]);
    $writer = useSynthesisWriter([$presentation, $presentation]);

    expect(fn () => app(SynthesizeProfile::class)->synthesize($s['profile']))->toThrow(RuntimeException::class)
        ->and((string) $writer->lastUser)->toContain('architected');
});

it('will not let a statement name an employer that none of its cited facts belongs to', function () {
    $s = synthesisScenario();
    $presentation = goodPresentation([
        'differentiators' => [
            ['statement' => 'Built Docker Compose environments at RocketGate for multi-service deployments.', 'fact_keys' => ['personal-docker']],
            ['statement' => 'Owned Workflow Intelligence end to end.', 'fact_keys' => ['wi-owned']],
            ['statement' => 'Built Nexus.', 'fact_keys' => ['nexus-built']],
        ],
    ]);
    $writer = useSynthesisWriter([$presentation, $presentation]);

    expect(fn () => app(SynthesizeProfile::class)->synthesize($s['profile']))->toThrow(RuntimeException::class)
        ->and((string) $writer->lastUser)->toContain('names')->and((string) $writer->lastUser)->toContain('RocketGate');
});

it('lets the user include, exclude, pin, reorder and reword, leaving the synthesis itself untouched', function () {
    $s = synthesisScenario();
    $synthesis = synthesize($s);
    $original = $synthesis->content;
    $review = app(ReviewProfileSynthesis::class);
    $flagship = "project:{$s['projects']['flagship']->id}";
    $nexus = "project:{$s['projects']['nexus']->id}";

    $review->edit($synthesis, $nexus, ['pinned' => true]);
    $review->edit($synthesis->fresh(), $flagship, ['include' => false]);
    $review->edit($synthesis->fresh(), 'headline', ['text' => 'Full-Stack Product Engineer']);
    $effective = EffectiveProfile::resolve($synthesis->fresh());
    $byId = collect($effective['projects'])->keyBy('id');

    expect($effective['projects'][0]['id'])->toBe($nexus)->and($byId[$nexus]['pinned'])->toBeTrue()->and($byId[$flagship]['included'])->toBeFalse()
        ->and($effective['presentation']['headline'])->toBe('Full-Stack Product Engineer')->and($effective['presentation']['headline_original'])->toBe('Full-Stack Developer')
        ->and($synthesis->fresh()->content)->toEqual($original);

    $ids = array_column($effective['accomplishments'], 'id');
    $review->reorder($synthesis->fresh(), array_reverse($ids));

    expect(EffectiveProfile::resolve($synthesis->fresh())['accomplishments'][0]['id'])->toBe(end($ids));
});

it('holds reworded language to the same rules as the model, and refuses unknown items', function () {
    $s = synthesisScenario();
    $synthesis = synthesize($s);
    $review = app(ReviewProfileSynthesis::class);

    expect(fn () => $review->edit($synthesis, 'summary', ['text' => 'Full-Stack Developer with 15 years of experience building Kubernetes platforms for every employer, owning each end to end across the stack and the business.']))->toThrow(ValidationException::class)
        ->and(EffectiveProfile::resolve($synthesis->fresh())['presentation']['summary'])->toBe(goodPresentation()['summary'])
        ->and(fn () => $review->edit($synthesis, 'project:99999', ['include' => false]))->toThrow(ValidationException::class);
});

it('locks an approved version, supersedes the previous one, and starts a new draft without a model call', function () {
    $s = synthesisScenario();
    $first = synthesize($s);
    $review = app(ReviewProfileSynthesis::class);
    $review->approve($first);

    expect($first->fresh()->status)->toBe('approved')->and($first->fresh()->approved_at)->not->toBeNull()
        ->and(fn () => $review->edit($first->fresh(), 'headline', ['text' => 'Something else']))->toThrow(ValidationException::class);

    $writer = useSynthesisWriter([]);
    $draft = $review->newVersion($first->fresh());

    expect($writer->calls)->toBe(0)->and($draft->version)->toBe(2)->and($draft->status)->toBe('draft')->and($draft->content)->toEqual($first->content);

    $review->approve($draft);

    expect($first->fresh()->status)->toBe('superseded')->and($draft->fresh()->status)->toBe('approved');
});

it('refuses approval and generation once the evidence has moved on', function () {
    $s = synthesisScenario();
    $synthesis = synthesize($s);
    EvidenceRevision::bump($s['career']);

    expect($synthesis->fresh()->isStale())->toBeTrue()
        ->and(fn () => app(ReviewProfileSynthesis::class)->approve($synthesis->fresh()))->toThrow(ValidationException::class, 'changed');

    $synthesis->update(['status' => 'approved']);

    expect(fn () => app(GenerateReferenceResume::class)->generate($synthesis->fresh()))->toThrow(ValidationException::class, 'changed');
});

it('will not generate a reference resume from a draft', function () {
    $s = synthesisScenario();
    $synthesis = synthesize($s);

    expect(fn () => app(GenerateReferenceResume::class)->generate($synthesis))->toThrow(ValidationException::class, 'Approve the profile');
});

it('composes a job-independent resume through the existing validators, without presenting personal evidence as employment', function () {
    $s = synthesisScenario();
    $synthesis = synthesize($s);
    app(ReviewProfileSynthesis::class)->approve($synthesis);
    $seen = new stdClass;
    app()->instance(GeneratesResumeWording::class, new class($seen) implements GeneratesResumeWording
    {
        public function __construct(private stdClass $seen) {}

        public function generate(string $system, string $user, array $schema): ResumeWordingProviderResponse
        {
            $this->seen->system = $system;
            preg_match('/--- Approved selection[^\n]*\n(.*?)\n--- End of approved selection/s', $user, $m);
            $in = json_decode($m[1], true);
            $this->seen->input = $in;
            $first = fn (array $facts) => rtrim((string) preg_replace('/\s+/', ' ', $facts[0]['statement']), '.').'.';

            return new ResumeWordingProviderResponse('fake', 'stub', [
                'summary' => 'Full-Stack Developer who owns applications end to end.',
                'experience' => array_map(fn ($r) => ['role_id' => $r['role_id'], 'bullets' => array_map(fn ($g) => ['bullet_group_index' => $g['bullet_group_index'], 'text' => $first($g['career_facts'])], $r['bullet_groups'])], $in['experience']),
                'selected_projects' => array_map(fn ($p) => ['project_id' => $p['project_id'], 'text' => $first($p['career_facts'])], $in['selected_projects']),
            ]);
        }
    });

    $variant = app(GenerateResumeVariant::class)->generateReference($synthesis->fresh());
    $variant->load('experienceRoles.bullets.citations.careerFact', 'projects', 'summaryEvidence.careerFact');
    $cited = $variant->experienceRoles->flatMap(fn ($r) => $r->bullets->flatMap(fn ($b) => $b->citations->map(fn ($c) => $c->careerFact->key)))->all();

    expect($variant->job_match_id)->toBeNull()->and($variant->resume_profile_synthesis_id)->toBe($synthesis->id)
        ->and($variant->selection_prompt_version)->toBe('reference-selection-v2')->and($variant->wording_prompt_version)->toBe('resume-wording-reference-v1')
        ->and($variant->selection_generated_by)->toBe('deterministic:profile-v1')
        // Every role that has evidence appears; the whole-career fact and the Private fact are in no bullet.
        ->and($variant->experienceRoles)->toHaveCount(2)->and($cited)->not->toContain('personal-docker', 'private-secret', 'first-person')
        // The shared TypeScript claim appears once, not once per project.
        ->and(count(array_intersect($cited, ['ts-wi', 'ts-trivial'])))->toBeLessThanOrEqual(1)
        // The personal project is a Selected Project, not an employer's accomplishment.
        ->and($variant->projects->pluck('name')->all())->toBe(['Well Prompted'])
        ->and($seen->system)->toContain('no job description')->and($seen->input['reference_context'])->toMatchArray(['kind' => 'reference_resume', 'target_identity' => 'Full-Stack Developer'])
        ->and(json_encode($seen->input))->not->toContain('SECRET-PROJECT-ZEBRA');
});

it('leaves every job-linked variant path intact', function () {
    expect((new ReflectionMethod(GenerateResumeVariant::class, 'generateFull'))->getParameters()[0]->getType()->getName())->toBe(JobMatch::class)
        ->and(Schema::getColumnListing('resume_variants'))->toContain('job_match_id', 'resume_profile_synthesis_id');
});

it('builds, edits, approves and generates over HTTP, and shows the profile on the Resume Profile page', function () {
    Queue::fake();
    $s = synthesisScenario();
    $synthesis = synthesize($s);

    $this->post(route('profile-syntheses.store', $s['profile']))->assertRedirect();
    Queue::assertPushed(SynthesizeProfileJob::class);

    $this->patch(route('profile-syntheses.update', $synthesis), ['id' => 'headline', 'text' => 'Full-Stack Product Engineer'])->assertRedirect();
    $this->patch(route('profile-syntheses.update', $synthesis), ['id' => 'summary', 'text' => 'Short.'])->assertSessionHasErrors('text');
    $this->post(route('profile-syntheses.reference-resume', $synthesis))->assertSessionHasErrors('profile');

    $this->get(route('resume-profiles.show', $s['profile']))->assertInertia(fn ($page) => $page
        ->where('resume_profile.synthesis.synthesis.version', 1)
        ->where('resume_profile.synthesis.synthesis.profile.presentation.headline', 'Full-Stack Product Engineer')
        ->where('resume_profile.synthesis.synthesis.pilot', true)
        ->has('resume_profile.synthesis.synthesis.profile.projects'));

    $this->post(route('profile-syntheses.approve', $synthesis))->assertRedirect();
    expect($synthesis->fresh()->status)->toBe('approved');

    $this->post(route('profile-syntheses.reference-resume', $synthesis))->assertRedirect();
    Queue::assertPushed(GenerateReferenceResumeJob::class);
});

it('lets the user add an existing fact as supporting evidence for an edited summary, without rerunning synthesis', function () {
    $s = synthesisScenario();
    $sql = $s['facts']['nexus-built'];
    $aggregate = CareerFact::factory()->create([
        'career_profile_id' => $s['career']->id, 'key' => 'nexus-sql', 'visibility' => Visibility::Restricted, 'verification' => Verification::StronglySupported, 'fact_type' => CareerFactType::Bullet,
        'statement' => 'Optimized Nexus analytics queries using SQL-side aggregation, including GROUP BY and SUM, to calculate reporting totals in the database.',
        'attributable_type' => 'role', 'attributable_id' => $s['pearson']->id,
    ]);
    $synthesis = synthesize($s);
    $review = app(ReviewProfileSynthesis::class);
    $review->approve($synthesis);
    $writer = useSynthesisWriter([]);
    $draft = $review->newVersion($synthesis->fresh());
    $edited = goodPresentation()['summary'].' Moved Nexus reporting totals into SQL aggregation in the database.';

    // The wording alone is refused: nothing it cites states SQL, and the refusal names the facts that do.
    expect(fn () => $review->edit($draft, 'summary', ['text' => $edited]))->toThrow(ValidationException::class, 'SQL')
        ->and($draft->fresh()->edits['text'] ?? [])->toBe([]);

    // An unrelated fact is refused as evidence, and so is one that is not in the inventory.
    expect(fn () => $review->edit($draft, 'summary', ['text' => $edited, 'fact_keys' => [...goodPresentation()['summary_fact_keys'], 'personal-docker']]))->toThrow(ValidationException::class, 'does not support')
        ->and(fn () => $review->edit($draft, 'summary', ['text' => $edited, 'fact_keys' => ['not-a-fact']]))->toThrow(ValidationException::class, 'not an eligible');

    // Citing the SQL fact makes the same wording valid; it saves and approves with no model call.
    $review->edit($draft, 'summary', ['text' => $edited, 'fact_keys' => [...goodPresentation()['summary_fact_keys'], 'nexus-sql']]);
    $effective = EffectiveProfile::resolve($draft->fresh());

    expect($effective['presentation']['summary'])->toBe($edited)->and($effective['presentation']['summary_fact_keys'])->toContain('nexus-sql')
        ->and($effective['presentation']['summary_fact_keys_original'])->not->toContain('nexus-sql')
        ->and($draft->fresh()->content)->toEqual($synthesis->content);

    $review->approve($draft->fresh());

    expect($draft->fresh()->status)->toBe('approved')->and($synthesis->fresh()->status)->toBe('superseded')->and($writer->calls)->toBe(0);

    // The reference selection keeps every fact the edited summary cites.
    $payload = app(ResumeCandidatePayloadBuilder::class)->buildForCareer($s['career'], [], []);
    $selection = app(ReferenceSelection::class)->build(EffectiveProfile::resolve($draft->fresh()), $payload);

    expect($selection['content']['summary_evidence'])->toContain('nexus-sql')->and($aggregate->exists)->toBeTrue()->and($sql->exists)->toBeTrue();
});

it('offers the editor every eligible fact as a candidate, and none that are Private', function () {
    $s = synthesisScenario();
    $synthesis = synthesize($s);

    $this->get(route('resume-profiles.show', $s['profile']))->assertInertia(fn ($page) => $page
        ->has('resume_profile.synthesis.synthesis.candidates')
        ->where('resume_profile.synthesis.synthesis.candidates', fn ($candidates) => collect($candidates)->pluck('key')->contains('wi-owned') && ! collect($candidates)->pluck('key')->contains('private-secret')));

    $this->patch(route('profile-syntheses.update', $synthesis), ['id' => 'differentiator:0', 'fact_keys' => ['wi-owned', 'wi-deterministic']])->assertRedirect();
    $this->patch(route('profile-syntheses.update', $synthesis), ['id' => 'summary', 'fact_keys' => []])->assertSessionHasErrors();
});
