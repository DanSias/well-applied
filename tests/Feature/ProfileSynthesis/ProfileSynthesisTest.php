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
use App\Support\ProfileSynthesis\ResolveEvidence;
use App\Support\ProfileSynthesis\ReviewProfileSynthesis;
use App\Support\ProfileSynthesis\SynthesizeProfile;
use App\Support\ResumeDocument\GenerateResumeDocument;
use App\Support\ResumeDocument\SkillPresentation;
use App\Support\ResumeProfile\QuestionWriter;
use App\Support\ResumeVariant\GenerateResumeVariant;
use App\Support\ResumeVariant\ResumeCandidatePayloadBuilder;
use App\Support\ResumeVariant\ResumeWordingProviderResponse;
use App\Support\ResumeVariant\ResumeWordingResponseValidator;
use App\Support\ResumeVariant\WordingCheckContext;
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
        // First-person wording may authorize a capability but is support-only: never an accomplishment, never in a project's facts.
        ->and($inventory['facts']['first-person']['support_only'])->toBeTrue()
        ->and(array_column($inventory['accomplishments'], 'fact_key'))->not->toContain('first-person');
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
    // A personal project appears on a reference resume only when the user includes it.
    app(ReviewProfileSynthesis::class)->edit($synthesis, "project:{$s['projects']['personal']->id}", ['include' => true]);
    app(ReviewProfileSynthesis::class)->approve($synthesis->fresh());
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
        ->and($variant->selection_prompt_version)->toBe('reference-selection-v2')->and($variant->wording_prompt_version)->toBe('resume-wording-reference-v3')
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

    // The inventory predates the new fact; a refresh brings it in. Then the wording alone resolves to it automatically.
    $draft = $review->refreshEvidence($draft);
    $review->edit($draft, 'summary', ['text' => $edited]);
    expect(EffectiveProfile::resolve($draft->fresh())['presentation']['summary_fact_keys'])->toContain('nexus-sql')
        ->and($draft->fresh()->edits['resolution']['summary']['status'])->toBe('supported');
    $draft = $review->refreshEvidence($draft->fresh());
    $draft->update(['edits' => []]);

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

    $this->get(route('resume-profiles.show', $s['profile']))->assertInertia(fn ($page) => $page->missing('resume_profile.synthesis.synthesis.candidates'));
    $keys = collect($this->getJson(route('profile-syntheses.evidence-candidates', $synthesis))->assertOk()->json('candidates'))->pluck('key');
    expect($keys)->toContain('wi-owned')->not->toContain('private-secret');

    $this->patch(route('profile-syntheses.update', $synthesis), ['id' => 'differentiator:0', 'fact_keys' => ['wi-owned', 'wi-deterministic']])->assertRedirect();
    $this->patch(route('profile-syntheses.update', $synthesis), ['id' => 'summary', 'fact_keys' => []])->assertSessionHasErrors();
});

/**
 * Approves the scenario's profile and runs generateReference with a stand-in wording provider. `$wording` receives the
 * call number and the approved selection the model would have seen, and returns the response content.
 */
function referenceRunWith(array $s, Closure $wording, ?array $presentation = null): array
{
    $synthesis = synthesize($s, $presentation);
    app(ReviewProfileSynthesis::class)->approve($synthesis);
    $log = new stdClass;
    $log->calls = 0;
    $log->prompts = [];
    app()->instance(GeneratesResumeWording::class, new class($wording, $log) implements GeneratesResumeWording
    {
        public function __construct(private Closure $wording, private stdClass $log) {}

        public function generate(string $system, string $user, array $schema): ResumeWordingProviderResponse
        {
            $this->log->calls++;
            $this->log->prompts[] = $user;
            preg_match('/--- Approved selection[^\n]*\n(.*?)\n--- End of approved selection/s', $user, $m);

            return new ResumeWordingProviderResponse('fake', 'stub', ($this->wording)($this->log->calls, json_decode($m[1], true)));
        }
    });

    $variant = app(GenerateResumeVariant::class)->generateReference($synthesis->fresh());

    return [$variant->fresh(['experienceRoles.bullets', 'projects']), $log, $synthesis];
}

/** Facts a reference resume would be tempted to embellish, on top of the standard scenario. */
function embellishableScenario(): array
{
    $s = synthesisScenario();
    $mk = fn (string $key, string $statement, string $type, int $id) => CareerFact::factory()->create([
        'career_profile_id' => $s['career']->id, 'key' => $key, 'statement' => $statement, 'visibility' => Visibility::Public, 'verification' => Verification::Verified,
        'fact_type' => CareerFactType::Bullet, 'attributable_type' => $type, 'attributable_id' => $id,
    ]);
    $mk('nexus-20', "Nexus's automated reporting saved approximately 20+ hours per week across teams by replacing spreadsheet-driven workflows.", 'project', $s['projects']['nexus']->id);
    $mk('conv-37', 'Delivered a 37% increase in post-purchase conversion rates.', 'role', $s['pearson']->id);
    $mk('onboard', 'Reduced average new-merchant onboarding effort from approximately 10 active hours to approximately 3 active hours.', 'role', $s['role']->id);

    return $s;
}

function goodSummary(): string
{
    return 'Full-Stack Developer who independently owned Workflow Intelligence from business requirements through delivery. Built Nexus, a full-stack React and Node.js analytics platform unifying BigQuery and Salesforce data, reducing reporting time by 85%.';
}

it('repairs unsupported scope, cause, role and figure precision in a reference resume without another model call', function () {
    $s = embellishableScenario();

    [$variant, $log] = referenceRunWith($s, function (int $call, array $in) {
        // A faithful bullet states every cited fact; the stand-in then adds exactly the embellishment under test.
        $embellish = function (array $facts): string {
            $keys = array_column($facts, 'key');
            $text = implode(' ', array_map(fn ($f) => rtrim($f['statement'], '.').'.', $facts));

            in_array('conv-37', $keys, true) && $text = rtrim($text, '.').' across managed client engagements.';
            in_array('onboard', $keys, true) && $text = rtrim($text, '.').' through improved documentation and knowledge-base content.';
            in_array('nexus-20', $keys, true) && $text = str_replace('approximately 20+', '20+', $text);
            in_array('nexus-built', $keys, true) && $text = str_replace('Built Nexus', 'Architected Nexus', $text);

            return $text;
        };

        return [
            'summary' => goodSummary(),
            'experience' => array_map(fn ($r) => ['role_id' => $r['role_id'], 'bullets' => array_map(fn ($g) => ['bullet_group_index' => $g['bullet_group_index'], 'text' => $embellish($g['career_facts'])], $r['bullet_groups'])], $in['experience']),
            'selected_projects' => array_map(fn ($p) => ['project_id' => $p['project_id'], 'text' => rtrim($p['career_facts'][0]['statement'], '.').'.'], $in['selected_projects']),
        ];
    });

    $texts = $variant->experienceRoles->flatMap(fn ($r) => $r->bullets->pluck('text'))->implode(' | ');
    $report = collect($variant->review_report['items']);
    $kinds = $report->flatMap(fn ($i) => collect($i['findings'])->pluck('kind'))->unique()->values()->all();

    expect($log->calls)->toBe(1)
        ->and($texts)->not->toContain('across managed client engagements')->and($texts)->toContain('37% increase in post-purchase conversion rates')
        ->and($texts)->not->toContain('through improved documentation')->and($texts)->toContain('approximately 3 active hours')
        ->and($texts)->toContain('approximately 20+ hours')
        ->and($texts)->not->toContain('Architected')
        // Each repair is on the record, flagged for the person reviewing.
        ->and($kinds)->toContain('scope_expansion', 'unsupported_cause', 'figure_precision', 'ownership_upgrade')
        ->and($report->where('status', 'generalized')->count())->toBeGreaterThanOrEqual(3);
});

it('gives a reference summary that drops approved content one corrective retry, naming exactly what was dropped', function () {
    $s = embellishableScenario();

    [$variant, $log] = referenceRunWith($s, function (int $call, array $in) {
        return [
            'summary' => $call === 1 ? 'Full-Stack Developer who independently owns applications end to end.' : goodSummary(),
            'experience' => array_map(fn ($r) => ['role_id' => $r['role_id'], 'bullets' => array_map(fn ($g) => ['bullet_group_index' => $g['bullet_group_index'], 'text' => implode(' ', array_map(fn ($f) => rtrim($f['statement'], '.').'.', $g['career_facts']))], $r['bullet_groups'])], $in['experience']),
            'selected_projects' => array_map(fn ($p) => ['project_id' => $p['project_id'], 'text' => rtrim($p['career_facts'][0]['statement'], '.').'.'], $in['selected_projects']),
        ];
    });

    expect($log->calls)->toBe(2)->and($variant->summary)->toContain('Workflow Intelligence')->and($variant->summary)->toContain('React')
        ->and($log->prompts[1])->toContain('The summary dropped')->and($log->prompts[1])->toContain('Workflow Intelligence')
        ->and(collect($variant->review_report['items'])->where('location', 'profile'))->toBeEmpty();
});

it('ships a reference resume that keeps dropping approved content after one retry, flagged for review rather than failing', function () {
    $s = embellishableScenario();

    [$variant, $log] = referenceRunWith($s, fn (int $call, array $in) => [
        'summary' => 'Full-Stack Developer who independently owns applications end to end.',
        'experience' => array_map(fn ($r) => ['role_id' => $r['role_id'], 'bullets' => array_map(fn ($g) => ['bullet_group_index' => $g['bullet_group_index'], 'text' => implode(' ', array_map(fn ($f) => rtrim($f['statement'], '.').'.', $g['career_facts']))], $r['bullet_groups'])], $in['experience']),
        'selected_projects' => array_map(fn ($p) => ['project_id' => $p['project_id'], 'text' => rtrim($p['career_facts'][0]['statement'], '.').'.'], $in['selected_projects']),
    ]);

    $coverage = collect($variant->review_report['items'])->firstWhere('location', 'profile');

    // Two calls, no more: the dropped content is on the review report, not a failed run.
    expect($log->calls)->toBe(2)->and($variant->exists)->toBeTrue()->and($coverage['status'])->toBe('review')
        ->and(collect($coverage['findings'])->pluck('kind')->unique()->all())->toBe(['retention']);
});

it('keeps the job-linked wording checks exactly as they were', function () {
    $context = new WordingCheckContext([], [], [], [], ['summary' => ['conv']], [], [], [], [], [], [], ['conv' => 'Delivered a 37% increase in conversion.'], [], []);

    expect($context->claimFidelity)->toBeFalse()->and($context->retention)->toBe([])
        ->and(app(ResumeWordingResponseValidator::class)->textFindings('summary', 'Drove a 37% increase across managed client engagements.', 'summary', $context, false, false))->toBe([]);
});

it('leaves personal projects out of a reference resume unless the user includes them', function () {
    $s = synthesisScenario();
    $synthesis = synthesize($s);
    $id = "project:{$s['projects']['personal']->id}";
    $personal = fn (ResumeProfileSynthesis $x) => collect(EffectiveProfile::resolve($x)['projects'])->firstWhere('id', $id);
    $selected = function (ResumeProfileSynthesis $x) use ($s) {
        $payload = app(ResumeCandidatePayloadBuilder::class)->buildForCareer($s['career'], [], []);

        return app(ReferenceSelection::class)->build(EffectiveProfile::resolve($x), $payload)['content']['selected_projects'];
    };

    // Nothing fills the space; the project is still in the profile and its record is untouched.
    expect($personal($synthesis)['included'])->toBeFalse()->and($selected($synthesis))->toBe([])->and(Project::find($s['projects']['personal']->id)->exists)->toBeTrue();

    app(ReviewProfileSynthesis::class)->edit($synthesis, $id, ['include' => true]);

    expect($personal($synthesis->fresh())['included'])->toBeTrue()->and(array_column($selected($synthesis->fresh()), 'project_id'))->toBe([$s['projects']['personal']->id]);
});

it('presents reference skills in recognizable categories with professional labels and no stranded skill', function () {
    config(['resume.skill_presentation.line_chars' => 60]);
    $skills = ['TypeScript', 'JavaScript', 'PHP', 'SQL', 'Python-like internal tag'];
    $mapped = array_values(array_filter(array_map(fn ($n) => SkillPresentation::for($n)[1] ?? null, $skills)));

    expect($mapped)->toBe(['TypeScript', 'JavaScript', 'PHP', 'SQL'])
        ->and(SkillPresentation::for('Google Cloud (general)'))->toBe(['Data & Cloud', 'Google Cloud'])
        ->and(SkillPresentation::for('Node.js'))->toBe(['Backend', 'Node.js'])
        ->and(SkillPresentation::for('Deterministic AI boundaries'))->toBeNull()
        ->and(SkillPresentation::groups())->toBe(['Languages', 'Frontend', 'Backend', 'Data & Cloud', 'AI & Integrations']);

    // Eight short skills in a narrow column: balanced lines, never one skill alone, deterministic.
    $items = ['TypeScript', 'JavaScript', 'PHP', 'SQL', 'Go', 'Rust', 'Kotlin', 'Swift'];
    $lines = SkillPresentation::pack('Languages', $items);

    expect($lines)->toBe(SkillPresentation::pack('Languages', $items))
        ->and(count($lines))->toBeGreaterThan(1)
        ->and(collect($lines)->every(fn ($l) => substr_count($l, ' · ') >= 1))->toBeTrue()
        ->and(collect($lines)->every(fn ($l, $i) => mb_strlen(($i === 0 ? 'Languages: ' : '').$l) <= 60))->toBeTrue()
        ->and(implode(' · ', $lines))->toBe(implode(' · ', $items))
        ->and(SkillPresentation::pack('Languages', ['TypeScript', 'SQL']))->toBe(['TypeScript · SQL']);
});

it('keeps the original skills presentation for job-linked variants and uses the new one for reference resumes', function () {
    $s = synthesisScenario();
    $synthesis = synthesize($s);
    app(ReviewProfileSynthesis::class)->approve($synthesis);
    app()->instance(GeneratesResumeWording::class, new class implements GeneratesResumeWording
    {
        public function generate(string $system, string $user, array $schema): ResumeWordingProviderResponse
        {
            preg_match('/--- Approved selection[^\n]*\n(.*?)\n--- End of approved selection/s', $user, $m);
            $in = json_decode($m[1], true);

            return new ResumeWordingProviderResponse('fake', 'stub', [
                'summary' => 'Full-Stack Developer.',
                'experience' => array_map(fn ($r) => ['role_id' => $r['role_id'], 'bullets' => array_map(fn ($g) => ['bullet_group_index' => $g['bullet_group_index'], 'text' => implode(' ', array_map(fn ($f) => rtrim($f['statement'], '.').'.', $g['career_facts']))], $r['bullet_groups'])], $in['experience']),
                'selected_projects' => [],
            ]);
        }
    });

    $variant = app(GenerateResumeVariant::class)->generateReference($synthesis->fresh());
    $groups = app(GenerateResumeDocument::class)->generate($variant)->skills;

    expect(array_map(fn ($g) => $g->label, $groups))->each->toBeIn(SkillPresentation::groups())
        ->and(collect($groups)->every(fn ($g) => $g->lines !== []))->toBeTrue();

    // The same selections on a variant with no profile keep the original groups and a single flowing line.
    // (Variants are immutable, so the copy is changed in memory only.)
    $copy = $variant->fresh();
    $copy->resume_profile_synthesis_id = null;
    $legacy = app(GenerateResumeDocument::class)->generate($copy)->skills;

    expect(collect($legacy)->every(fn ($g) => $g->lines === []))->toBeTrue()->and(array_map(fn ($g) => $g->label, $legacy))->toContain('Languages & Frameworks');
});

it('refreshes a stale profile\'s evidence without a model call, keeping its language and the user\'s decisions', function () {
    $s = synthesisScenario();
    $synthesis = synthesize($s);
    $review = app(ReviewProfileSynthesis::class);
    $review->edit($synthesis, 'project:'.$s['projects']['nexus']->id, ['pinned' => true]);
    $review->approve($synthesis->fresh());
    $s['facts']['nexus-85']->update(['statement' => 'Nexus reduced reporting time by 85% and removed the manual export step.']);
    EvidenceRevision::bump($s['career']);
    $writer = useSynthesisWriter([]);

    expect($synthesis->fresh()->isStale())->toBeTrue();

    $draft = $review->refreshEvidence($synthesis->fresh());

    expect($writer->calls)->toBe(0)->and($draft->status)->toBe('draft')->and($draft->version)->toBe(2)->and($draft->isStale())->toBeFalse()
        ->and($draft->content['inventory']['facts']['nexus-85']['statement'])->toContain('removed the manual export step')
        ->and($draft->content['presentation'])->toEqual($synthesis->content['presentation'])->and($draft->edits)->toEqual($synthesis->fresh()->edits)
        ->and($draft->usage['model_calls'])->toBe(0)
        // Nothing is approved for the user, and the approved version is untouched until they approve this one.
        ->and($synthesis->fresh()->status)->toBe('approved');

    $review->approve($draft);

    expect($synthesis->fresh()->status)->toBe('superseded');
});

it('refuses to refresh when the changed evidence no longer supports the language', function () {
    $s = synthesisScenario();
    $synthesis = synthesize($s);
    $s['facts']['nexus-built']->update(['statement' => 'Built Nexus, an analytics platform.']);
    EvidenceRevision::bump($s['career']);

    expect(fn () => app(ReviewProfileSynthesis::class)->refreshEvidence($synthesis->fresh()))->toThrow(ValidationException::class, 'no longer matches')
        ->and(ResumeProfileSynthesis::count())->toBe(1);
});

it('records the confirmed RocketGate dates in the canonical import file', function () {
    $roles = collect(json_decode((string) file_get_contents(base_path('data/canonical-career-data.proposed.json')), true)['roles']);
    $rocketgate = $roles->firstWhere('key', 'rocketgate-developer-support-engineer');

    expect([$rocketgate['start_year'], $rocketgate['start_month'], $rocketgate['end_year'], $rocketgate['end_month']])->toBe([2025, 8, 2026, 10]);
});

/**
 * A draft whose inventory includes every fact the scenario defines (extra facts are created first).
 *
 * @param  array<int, array{0: string, 1: string, 2?: Visibility}>  $extra  key, statement, visibility
 */
function resolutionDraft(?array &$s = null, array $extra = []): ResumeProfileSynthesis
{
    $s = synthesisScenario();

    foreach ($extra as [$key, $statement, $visibility]) {
        CareerFact::factory()->create([
            'career_profile_id' => $s['career']->id, 'key' => $key, 'statement' => $statement, 'visibility' => $visibility ?? Visibility::Public,
            'verification' => Verification::StronglySupported, 'fact_type' => CareerFactType::Bullet, 'attributable_type' => 'role', 'attributable_id' => $s['pearson']->id,
        ]);
    }

    return synthesize($s);
}

function resolveText(ResumeProfileSynthesis $draft, string $id, string $text, array $preferred = []): array
{
    return app(ResolveEvidence::class)->resolve($draft, $id, $text, $preferred);
}

it('keeps the wording of an existing approved summary supported, with no more citations than it needs', function () {
    $draft = resolutionDraft();
    $r = resolveText($draft, 'summary', goodPresentation()['summary'], goodPresentation()['summary_fact_keys']);

    expect($r['status'])->toBe('supported')->and($r['keys'])->toContain('wi-owned', 'nexus-85')->and(count($r['keys']))->toBeLessThanOrEqual(5);
});

it('resolves a SQL aggregation sentence to the aggregation fact, not to a generic database fact', function () {
    $draft = resolutionDraft(extra: [
        ['nexus-sql', 'Optimized Nexus analytics queries using SQL-side aggregation, including GROUP BY and SUM, to calculate reporting totals in the database.', Visibility::Restricted],
        ['generic-mssql', 'Migrated Salesforce data between systems, working with MS SQL Server tables.', Visibility::Public],
    ]);
    $r = resolveText($draft, 'summary', 'Moved Nexus reporting totals into SQL aggregation in the database.');

    expect($r['status'])->toBe('supported')->and($r['keys'])->toContain('nexus-sql')->not->toContain('generic-mssql');
});

it('lets first-person evidence authorize a capability without ever putting its wording on a resume', function () {
    $draft = resolutionDraft();
    $r = resolveText($draft, 'summary', 'Full-Stack Developer with extensive JavaScript experience.');

    expect($r['status'])->toBe('supported')->and($r['keys'])->toContain('first-person');

    $review = app(ReviewProfileSynthesis::class);
    $summary = goodPresentation()['summary'].' Brings extensive JavaScript experience.';
    $review->edit($draft, 'summary', ['text' => $summary]);

    // The wording is the user's; the first-person sentence is support-only.
    expect(EffectiveProfile::resolve($draft->fresh())['presentation']['summary_fact_keys'])->toContain('first-person')
        ->and(EffectiveProfile::resolve($draft->fresh())['presentation']['summary'])->not->toContain("I've")->and($draft->fresh()->content['inventory']['facts']['first-person']['support_only'])->toBeTrue();
});

it('lets several facts jointly support one sentence', function () {
    $draft = resolutionDraft();
    $r = resolveText($draft, 'differentiator:2', 'Built a full-stack React and Node.js analytics platform for Nexus that reduced reporting time by 85%.');

    expect($r['status'])->toBe('supported')->and($r['keys'])->toContain('nexus-built', 'nexus-85');
});

it('refuses technologies and scale that no approved fact states', function () {
    $draft = resolutionDraft();

    foreach (['Built Kubernetes deployment pipelines for Workflow Intelligence.', 'Built a Rust analytics engine.'] as $text) {
        $r = resolveText($draft, 'summary', $text);
        expect($r['status'])->not->toBe('supported');
    }

    $scale = resolveText($draft, 'summary', 'Built Nexus, a high-traffic analytics platform serving millions of users.');
    expect($scale['status'])->toBe('unsupported')->and(collect($scale['gaps'])->pluck('kind'))->toContain('scale');
});

it('does not let a fact that denies scale authorize it', function () {
    $draft = resolutionDraft(extra: [['scale-denied', 'Built internal tools for small teams rather than high-traffic consumer systems.', Visibility::Public]]);
    $r = resolveText($draft, 'summary', 'Built high-traffic consumer systems.');

    expect($r['status'])->toBe('unsupported');
});

it('refuses a stronger ownership, a widened scope and an unexplained cause', function () {
    $draft = resolutionDraft();

    $owner = resolveText($draft, 'differentiator:2', 'Led and architected the Nexus analytics platform.');
    $scope = resolveText($draft, 'differentiator:2', 'Built Nexus analytics across all client portfolios.');
    $cause = resolveText($draft, 'differentiator:2', 'Built Nexus, which cut reporting time by 85% by introducing caching.');

    expect($owner['status'])->not->toBe('supported')->and($scope['status'])->not->toBe('supported')->and($cause['status'])->not->toBe('supported');
});

it('refuses a figure that only Restricted evidence states in a place the policy does not allow, and never offers Private facts', function () {
    $draft = resolutionDraft();
    $r = resolveText($draft, 'summary', 'Corrected 32,000 merchant transaction records through idempotent, audited writes.');

    expect(collect($r['gaps'])->pluck('claim')->implode(' '))->not->toContain('ZEBRA')
        ->and(json_encode($r))->not->toContain('SECRET-PROJECT-ZEBRA')->and($draft->content['inventory']['facts'])->not->toHaveKey('private-secret');
});

it('restricts a project note to its project and the role evidence around it', function () {
    $s = null;
    $draft = resolutionDraft($s);
    $note = 'note:'.$s['projects']['nexus']->id;
    $r = resolveText($draft, $note, 'Built a full-stack analytics platform that reduced reporting time by 85%.');
    $stray = resolveText($draft, $note, 'Independently owned Workflow Intelligence end to end.');

    expect($r['status'])->toBe('supported')->and($r['keys'])->not->toContain('wi-owned')->and($stray['status'])->not->toBe('supported');
});

it('saves resolved wording automatically with an audit record, makes no model call, and still honors manual evidence', function () {
    $draft = resolutionDraft();
    $writer = useSynthesisWriter([]);
    $review = app(ReviewProfileSynthesis::class);
    $text = 'Designs around deterministic, evidence-linked metrics, each computed once and reused.';

    $review->edit($draft, 'differentiator:1', ['text' => $text]);
    $saved = $draft->fresh();

    expect($saved->edits['keys']['differentiator:1'])->toBe(['wi-deterministic'])
        ->and($saved->edits['resolution']['differentiator:1'])->toMatchArray(['mode' => 'auto', 'status' => 'supported', 'fact_count' => 1])
        ->and($writer->calls)->toBe(0);

    // Unsupported wording is not saved; the error explains the claim and carries the structured gap.
    try {
        $review->edit($saved, 'differentiator:1', ['text' => 'Built Kubernetes clusters at scale.']);
        $thrown = null;
    } catch (ValidationException $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull()->and(json_decode($thrown->errors()['resolution'][0], true)['gaps'])->not->toBeEmpty()
        ->and($saved->fresh()->edits['text']['differentiator:1'])->toBe($text);

    // Manual evidence editing still works and is judged with the wording.
    expect(fn () => $review->edit($saved->fresh(), 'differentiator:1', ['text' => $text, 'fact_keys' => ['wi-deterministic', 'personal-docker']]))->toThrow(ValidationException::class, 'does not support');
    $review->edit($saved->fresh(), 'differentiator:1', ['text' => $text, 'fact_keys' => ['wi-deterministic']]);
    expect($saved->fresh()->edits['keys']['differentiator:1'])->toBe(['wi-deterministic']);
});

it('offers Refresh evidence for a draft whose inventory is out of date even when its evidence revision is current', function () {
    $s = synthesisScenario();
    $synthesis = synthesize($s);
    $url = fn () => $this->get(route('resume-profiles.show', $s['profile']));

    $url()->assertInertia(fn ($page) => $page->where('resume_profile.synthesis.synthesis.outdated', false)->where('resume_profile.synthesis.synthesis.stale', false));

    // An inventory built before first-person facts became support-only: same evidence revision, different inventory.
    $content = $synthesis->content;
    unset($content['inventory']['facts']['first-person']);
    $synthesis->update(['content' => $content]);

    $url()->assertInertia(fn ($page) => $page->where('resume_profile.synthesis.synthesis.outdated', true)->where('resume_profile.synthesis.synthesis.stale', false));

    $this->post(route('profile-syntheses.refresh-evidence', $synthesis))->assertRedirect();
    $url()->assertInertia(fn ($page) => $page->where('resume_profile.synthesis.synthesis.outdated', false)->where('resume_profile.synthesis.synthesis.version', 2));
});
