<?php

use App\Contracts\GeneratesReadinessReview;
use App\Contracts\GeneratesResumeWording;
use App\Enums\CareerFactType;
use App\Enums\DescriptionCompleteness;
use App\Enums\GenerationStatus;
use App\Enums\GenerationType;
use App\Enums\Verification;
use App\Enums\Visibility;
use App\Exceptions\ReadinessReviewException;
use App\Exceptions\ResumeGenerationProviderException;
use App\Exceptions\ResumeRevisionException;
use App\Jobs\GenerateReferenceResumeJob;
use App\Jobs\GenerateResumeVariantJob;
use App\Jobs\RunReadinessReviewJob;
use App\Jobs\SynthesizeProfileJob;
use App\Models\CareerFact;
use App\Models\CareerLimitation;
use App\Models\Employer;
use App\Models\GenerationAttempt;
use App\Models\JobAnalysis;
use App\Models\JobAnalysisFinding;
use App\Models\JobMatch;
use App\Models\JobPosting;
use App\Models\JobResumeSelection;
use App\Models\Project;
use App\Models\ResumeProfileSynthesis;
use App\Models\ResumeReadinessReview;
use App\Models\ResumeVariant;
use App\Models\ResumeVariantExperienceBullet;
use App\Models\Role;
use App\Models\Skill;
use App\Support\Clarification\EvidenceRevision;
use App\Support\JobTailoring\JobRelevance;
use App\Support\JobTailoring\ProfileJobAdapter;
use App\Support\ProfileSynthesis\EffectiveProfile;
use App\Support\ProfileSynthesis\GenerateReferenceResume;
use App\Support\ProfileSynthesis\ProfileAssembly;
use App\Support\ProfileSynthesis\ProfileSynthesisPrompt;
use App\Support\ProfileSynthesis\ReferenceSelection;
use App\Support\ProfileSynthesis\ResolveEvidence;
use App\Support\ProfileSynthesis\ReviewProfileSynthesis;
use App\Support\ProfileSynthesis\SynthesizeProfile;
use App\Support\ReadinessReview\DocumentChecks;
use App\Support\ReadinessReview\PresentReadiness;
use App\Support\ReadinessReview\ReadinessPrompt;
use App\Support\ReadinessReview\ResumeArtifact;
use App\Support\ReadinessReview\RunReadinessReview;
use App\Support\ReadinessReview\ValidateAssessment;
use App\Support\ResumeDocument\AssessResumeLayout;
use App\Support\ResumeDocument\GenerateResumeDocument;
use App\Support\ResumeDocument\ResumeContact;
use App\Support\ResumeDocument\ResumeDocument;
use App\Support\ResumeDocument\ResumeExperienceRole;
use App\Support\ResumeDocument\ResumePdfFilename;
use App\Support\ResumeDocument\ResumePdfStore;
use App\Support\ResumeDocument\SkillPresentation;
use App\Support\ResumeProfile\QuestionWriter;
use App\Support\ResumeRevision\JobResumes;
use App\Support\ResumeRevision\ReviseResumeVariant;
use App\Support\ResumeVariant\CompositionChecks;
use App\Support\ResumeVariant\GenerateResumeVariant;
use App\Support\ResumeVariant\ResumeCandidatePayloadBuilder;
use App\Support\ResumeVariant\ResumeWordingProviderResponse;
use App\Support\ResumeVariant\ResumeWordingResponseValidator;
use App\Support\ResumeVariant\WordingCheckContext;
use App\Support\TailoredResume\PresentTailoredResume;
use App\Support\TailoredResume\TailoredResumePipeline;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Smalot\PdfParser\Parser;
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
        ->and(SkillPresentation::groups())->toBe(['Languages', 'Frontend', 'Backend', 'Data & Cloud', 'Tools & Workflow', 'AI & Integrations'])
        ->and(SkillPresentation::for('OpenAI')[1])->toBe('LLM integration')->and(SkillPresentation::for('Anthropic')[1])->toBe('LLM integration')
        ->and(SkillPresentation::for('Jira'))->toBe(['Tools & Workflow', 'Jira'])->and(SkillPresentation::for('API integration & design')[1])->toBe('API design & integration');

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

it('regroups skills for a tailored resume through a layout, without adding or renaming any skill', function () {
    $names = ['TypeScript', 'React', 'Laravel', 'REST APIs', 'Jira', 'GitLab', 'Salesforce', 'OpenAI', 'BigQuery', 'PostgreSQL', 'AWS', 'Google Cloud (general)', 'Deterministic AI boundaries'];
    $groups = SkillPresentation::groups('tailored');
    $placed = collect($names)->mapWithKeys(fn ($n) => [$n => SkillPresentation::for($n, 'tailored')]);

    expect($groups)->toBe(['Languages & Frameworks', 'Architecture & Integration', 'AI & Data', 'Cloud & Delivery'])
        ->and($placed['React'])->toBe(['Languages & Frameworks', 'React'])
        ->and($placed['Jira'])->toBe(['Architecture & Integration', 'Jira'])
        ->and($placed['OpenAI'])->toBe(['AI & Data', 'LLM integration'])
        ->and($placed['GitLab'])->toBe(['Cloud & Delivery', 'GitLab'])
        ->and($placed['Deterministic AI boundaries'])->toBeNull()
        // Every skill a layout places is one the shared map already allows, and lands in a group the layout declares.
        ->and(collect((array) config('resume.skill_presentation.layouts.tailored.categories'))->keys()->every(fn ($n) => SkillPresentation::for($n) !== null))->toBeTrue()
        ->and(collect((array) config('resume.skill_presentation.layouts.tailored.categories'))->values()->every(fn ($g) => in_array($g, $groups, true)))->toBeTrue()
        // The reference grouping is unchanged.
        ->and(SkillPresentation::for('Jira'))->toBe(['Tools & Workflow', 'Jira'])->and(SkillPresentation::groups())->toHaveCount(6);
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

it('keeps breadth in the skills list: a core language with one supporting fact is not crowded out by a deep stack', function () {
    $s = synthesisScenario();
    $sql = Skill::factory()->create(['career_profile_id' => $s['career']->id, 'name' => 'SQL', 'slug' => 'sql']);
    $s['facts']['nexus-built']->skills()->attach($sql->id);
    foreach (['PostgreSQL', 'MySQL', 'AWS', 'Google Cloud (general)', 'Microsoft Azure'] as $n) {
        $k = Skill::factory()->create(['career_profile_id' => $s['career']->id, 'name' => $n, 'slug' => strtolower(str_replace([' ', '(', ')'], '', $n))]);
        $s['facts']['nexus-85']->skills()->attach($k->id);
    }
    config(['resume_profiles.reference.skills' => 8]);
    $synthesis = synthesize($s);
    $payload = app(ResumeCandidatePayloadBuilder::class)->buildForCareer($s['career'], [], []);
    $names = Skill::query()->whereIn('id', array_column(app(ReferenceSelection::class)->build(EffectiveProfile::resolve($synthesis), $payload)['content']['skills'], 'skill_id'))->pluck('name')->all();

    // A small cap would otherwise fill with data and cloud tags; each category gets its strongest first.
    expect($names)->toContain('SQL', 'React')->and(count(array_filter($names, fn ($n) => in_array($n, ['PostgreSQL', 'MySQL', 'BigQuery', 'AWS', 'Google Cloud (general)', 'Microsoft Azure'], true))))->toBeLessThanOrEqual(4);
});

it('groups consecutive roles at one employer on a reference resume only', function () {
    $role = fn (string $title, string $dates) => new ResumeExperienceRole('Pearson', $title, $dates, false, ['One bullet.']);
    $other = new ResumeExperienceRole('RocketGate', 'Engineer', '2025', false, ['b']);
    $contact = new ResumeContact('A B', null, null, null, null, null);
    $make = fn (bool $group) => new ResumeDocument($contact, 's', [$other, $role('Lead', '2015–2024'), $role('SEO Analyst', '2013–2015')], [], [], [], $group);

    $grouped = $make(true)->employerGroups();
    $plain = $make(false)->employerGroups();

    expect($grouped)->toHaveCount(2)->and($grouped[1]['shared'])->toBeTrue()->and(array_map(fn ($r) => $r->displayTitle, $grouped[1]['roles']))->toBe(['Lead', 'SEO Analyst'])
        ->and($plain)->toHaveCount(3);

    $html = view('resume.print', ['document' => $make(true)])->render();
    expect(substr_count($html, 'class="group-employer"'))->toBe(1)->and(strpos($html, 'Lead'))->toBeLessThan(strpos($html, 'SEO Analyst'));
});

/**
 * A job (payments and webhooks, with a Rails preference the career confirms it lacks) and facts the reference resume does
 * not emphasize: payment and webhook work at the flagship's employer and a merchant-onboarding fact.
 */
function tailoringScenario(): array
{
    $s = synthesisScenario();
    $mk = fn (string $key, string $statement, string $type, int $id, Visibility $v = Visibility::Public) => CareerFact::factory()->create([
        'career_profile_id' => $s['career']->id, 'key' => $key, 'statement' => $statement, 'visibility' => $v, 'verification' => Verification::Verified,
        'fact_type' => CareerFactType::Bullet, 'attributable_type' => $type, 'attributable_id' => $id,
    ]);
    $mk('webhooks', 'Built webhook handlers for payment events with retry schedules and idempotent processing of duplicate deliveries.', 'role', $s['role']->id);
    $mk('recurring', 'Set up recurring billing for merchants through the payment gateway API.', 'role', $s['role']->id);
    $mk('private-card', 'Handled the SECRET-PROJECT-ZEBRA payment webhook outage.', 'role', $s['role']->id, Visibility::Private);

    $posting = JobPosting::factory()->create(['career_profile_id' => $s['career']->id, 'title' => 'Full Stack Engineer', 'company' => 'Acme Finance']);
    $analysis = JobAnalysis::factory()->create(['job_posting_id' => $posting->id, 'prompt_version' => 'job-analysis-v4', 'role_summary' => 'Own payment integrations and the signup funnel.']);
    $finding = fn (string $category, string $label, string $statement) => JobAnalysisFinding::factory()->create([
        'job_analysis_id' => $analysis->id, 'category' => $category, 'label' => $label, 'statement' => $statement,
    ]);
    $finding('required_qualification', 'webhook_integration', 'Strong experience building webhook-driven payment integrations with retries and idempotency.');
    $finding('responsibility', 'payments', 'Own payment gateway and recurring billing integrations.');
    $finding('preferred_qualification', 'rails', 'Ruby on Rails experience.');
    $finding('required_qualification', 'frontend', 'Working proficiency with React and TypeScript.');

    return [...$s, 'posting' => $posting, 'analysis' => $analysis->fresh()];
}

it('ranks approved evidence by the job, reports gaps, and never ranks a Private fact', function () {
    $s = tailoringScenario();
    $synthesis = synthesize($s);
    $relevance = app(JobRelevance::class)->analyze($s['analysis'], $synthesis->content['inventory']['facts'], $s['career']);

    // Webhook and recurring-billing facts are relevant even though the reference summary never cites them.
    expect($relevance['facts'])->toHaveKeys(['webhooks', 'recurring'])->not->toHaveKey('private-card')->not->toHaveKey('private-secret')
        ->and($relevance['facts']['webhooks']['score'])->toBeGreaterThan($relevance['facts']['wi-owned']['score'] ?? 0)
        ->and(array_column($relevance['gaps'], 'capability'))->toContain('ruby_on_rails')
        ->and(collect($relevance['gaps'])->firstWhere('capability', 'ruby_on_rails')['status'])->toBe('limitation');
});

it('adapts the profile to a job without overriding exclusions or pins', function () {
    $s = tailoringScenario();
    $synthesis = synthesize($s);
    $review = app(ReviewProfileSynthesis::class);
    $review->edit($synthesis, 'accomplishment:webhooks', ['include' => false]);
    $effective = EffectiveProfile::resolve($synthesis->fresh());
    $relevance = app(JobRelevance::class)->analyze($s['analysis'], $synthesis->content['inventory']['facts'], $s['career']);
    $adapted = app(ProfileJobAdapter::class)->adapt($effective, $relevance);
    $byKey = collect($adapted['accomplishments'])->keyBy('fact_key');

    // An exclusion the user made holds; a relevant included fact outranks an irrelevant one.
    expect($byKey['webhooks']['included'])->toBeFalse()
        ->and($byKey['recurring']['score'])->toBeGreaterThan($byKey['trivia-1']['score'])
        ->and($adapted['presentation']['summary_fact_keys'])->not->toContain('webhooks');
});

it('composes a job-tailored resume from the approved profile with one wording call, bringing in relevant evidence and never claiming a limitation', function () {
    $s = tailoringScenario();
    $synthesis = synthesize($s);
    app(ReviewProfileSynthesis::class)->approve($synthesis);
    $seen = new stdClass;
    $seen->calls = 0;
    app()->instance(GeneratesResumeWording::class, new class($seen) implements GeneratesResumeWording
    {
        public function __construct(private stdClass $seen) {}

        public function generate(string $system, string $user, array $schema): ResumeWordingProviderResponse
        {
            $this->seen->calls++;
            $this->seen->system = $system;
            preg_match('/--- Approved selection[^\n]*\n(.*?)\n--- End of approved selection/s', $user, $m);
            $in = json_decode($m[1], true);
            $this->seen->input = $in;
            $first = fn (array $facts) => implode(' ', array_map(fn ($f) => rtrim((string) preg_replace('/\s+/', ' ', $f['statement']), '.').'.', $facts));

            return new ResumeWordingProviderResponse('fake', 'stub', [
                'summary' => 'Full-Stack Developer who owns applications end to end and builds payment integrations.',
                'experience' => array_map(fn ($r) => ['role_id' => $r['role_id'], 'bullets' => array_map(fn ($g) => ['bullet_group_index' => $g['bullet_group_index'], 'text' => $first($g['career_facts'])], $r['bullet_groups'])], $in['experience']),
                'selected_projects' => [],
            ]);
        }
    });

    $variant = app(GenerateResumeVariant::class)->generateForJob($s['analysis'], $synthesis->fresh());
    $variant->load('experienceRoles.bullets.citations.careerFact');
    $cited = $variant->experienceRoles->flatMap(fn ($r) => $r->bullets->flatMap(fn ($b) => $b->citations->map(fn ($c) => $c->careerFact->key)))->all();
    $tailoring = $variant->selection_input_snapshot['tailoring'];

    expect($seen->calls)->toBe(1)
        ->and($variant->job_match_id)->toBeNull()->and($variant->job_analysis_id)->toBe($s['analysis']->id)->and($variant->resume_profile_synthesis_id)->toBe($synthesis->id)
        ->and($variant->wording_prompt_version)->toBe('resume-wording-profile-job-v1')
        // The job's evidence reaches the resume even though the reference profile does not emphasize it.
        ->and($cited)->toContain('webhooks')->not->toContain('private-card', 'private-secret', 'personal-docker', 'first-person')
        ->and($tailoring['mode'])->toBe('profile_job')->and($tailoring['job_analysis_id'])->toBe($s['analysis']->id)->and($tailoring['profile_version'])->toBe($synthesis->version)
        ->and($tailoring['added_outside_reference'])->not->toBeEmpty()->and($tailoring['dropped_from_reference'])->toBeArray()
        // Composition findings are advice stored with the provenance, never a change to the selection.
        ->and($tailoring['composition'])->toBeArray()
        ->and(array_unique(array_column($tailoring['composition'], 'severity')))->each->toBeIn(['review', 'informational'])
        ->and(array_column($tailoring['gaps'], 'capability'))->toContain('ruby_on_rails')
        ->and($seen->input['job_context']['not_supported'])->toContain('Ruby on Rails')
        ->and($seen->input['reference_context']['kind'])->toBe('job_tailored_resume')
        ->and($variant->summary)->not->toContain('Rails')
        ->and(json_encode($variant->wording_input_snapshot))->not->toContain('SECRET-PROJECT-ZEBRA');

    // Provenance is on the review page: why an experience made it and the evidence behind it.
    $this->get(route('jobs.profile-resumes.show', [$s['posting'], $variant]))->assertOk()->assertInertia(fn ($page) => $page
        ->where('match', null)->where('tailoring.profile_version', $synthesis->version)
        ->where('tailoring.added', fn ($added) => collect($added)->pluck('key')->all() === array_values($tailoring['added_outside_reference'])));
});

it('runs the profile-assisted pipeline as analysis, resume, PDF with no match stage, and refuses an unapproved or stale profile', function () {
    Queue::fake();
    $s = tailoringScenario();
    $synthesis = synthesize($s);
    $pipeline = app(TailoredResumePipeline::class);
    $posting = $s['posting'];

    // The description must be known complete for a one-action run.
    $posting->update(['description_completeness' => DescriptionCompleteness::Complete]);

    expect(fn () => $pipeline->start($posting, synthesis: $synthesis))->toThrow(ValidationException::class, 'Approve the profile');

    app(ReviewProfileSynthesis::class)->approve($synthesis);
    $runId = $pipeline->start($posting, synthesis: $synthesis->fresh());

    // The stored analysis is reused, so the first claimed stage is the resume itself, carrying the approved profile.
    $attempt = GenerationAttempt::query()->where('pipeline_run_id', $runId)->first();
    expect($attempt->generation_type)->toBe(GenerationType::ResumeVariant)->and($attempt->subject_id)->toBe($s['analysis']->id)->and($attempt->resume_profile_synthesis_id)->toBe($synthesis->id);
    Queue::assertPushed(GenerateResumeVariantJob::class);

    // A second click joins the same run; nothing duplicates.
    expect($pipeline->start($posting, synthesis: $synthesis->fresh()))->toBe($runId)->and(GenerationAttempt::query()->count())->toBe(1);

    $status = app(PresentTailoredResume::class)->present($posting);
    expect(array_column($status['stages'], 'key'))->toBe(['analysis', 'resume', 'pdf'])->and($status['state'])->toBe('running')->and($status['current_stage'])->toBe('resume');

    $profiles = app(PresentTailoredResume::class)->profiles($posting);
    expect($profiles[0])->toMatchArray(['id' => $synthesis->id, 'recommended' => true, 'stale' => false]);

    // Evidence changed after approval: refused with an actionable message, never a silent fallback.
    EvidenceRevision::bump($s['career']);
    GenerationAttempt::query()->update(['status' => GenerationStatus::Failed]);
    expect(fn () => $pipeline->start($posting, synthesis: $synthesis->fresh()))->toThrow(ValidationException::class, 'Refresh');
});

/** The wording stand-in for job runs: states every cited fact, and records what the model was shown. */
function jobWordingStub(stdClass $seen): GeneratesResumeWording
{
    return new class($seen) implements GeneratesResumeWording
    {
        public function __construct(private stdClass $seen) {}

        public function generate(string $system, string $user, array $schema): ResumeWordingProviderResponse
        {
            $this->seen->calls = ($this->seen->calls ?? 0) + 1;
            preg_match('/--- Approved selection[^\n]*\n(.*?)\n--- End of approved selection/s', $user, $m);
            $in = json_decode($m[1], true);
            $this->seen->input = $in;
            $all = fn (array $facts) => implode(' ', array_map(fn ($f) => rtrim((string) preg_replace('/\s+/', ' ', $f['statement']), '.').'.', $facts));

            return new ResumeWordingProviderResponse('fake', 'stub', [
                'summary' => ($this->seen->summary ?? 'Full-Stack Developer who owns applications end to end.'),
                'experience' => array_map(fn ($r) => ['role_id' => $r['role_id'], 'bullets' => array_map(fn ($g) => ['bullet_group_index' => $g['bullet_group_index'], 'text' => $all($g['career_facts'])], $r['bullet_groups'])], $in['experience']),
                'selected_projects' => [],
            ]);
        }
    };
}

it('matches paraphrased job requirements to supported capabilities and prefers direct evidence to a loosely related fact', function () {
    $s = tailoringScenario();
    $mk = fn (string $key, string $statement) => CareerFact::factory()->create([
        'career_profile_id' => $s['career']->id, 'key' => $key, 'statement' => $statement, 'visibility' => Visibility::Public, 'verification' => Verification::Verified,
        'fact_type' => CareerFactType::Bullet, 'attributable_type' => 'role', 'attributable_id' => $s['pearson']->id,
    ]);
    // The job says "payment platforms" and "customer onboarding"; the facts say "payment flows" and "new-client onboarding effort".
    $mk('paraphrase-direct', 'Tested payment flows and troubleshot integration issues for new clients during onboarding.');
    $mk('paraphrase-loose', 'Maintained a weekly reporting spreadsheet for the finance team.');
    $s['analysis']->findings()->create(['category' => 'responsibility', 'label' => 'platforms', 'statement' => 'Own integrations with payment platforms and customer onboarding.', 'basis' => 'explicit', 'emphasis' => 'high']);
    $synthesis = synthesize($s);
    $relevance = app(JobRelevance::class)->analyze($s['analysis']->fresh(), $synthesis->content['inventory']['facts'], $s['career']);

    expect($relevance['facts']['paraphrase-direct']['score'])->toBeGreaterThan(($relevance['facts']['paraphrase-loose']['score'] ?? 0.0) + 0.2);
});

it('answers the job\'s strongest requirements, caps non-developer roles, and keeps pins and exclusions', function () {
    $s = tailoringScenario();
    $analyst = Role::factory()->create(['employer_id' => $s['pearson']->employer_id, 'title' => 'Marketing Analyst', 'start_year' => 2010, 'end_year' => 2014]);

    foreach (range(1, 4) as $i) {
        CareerFact::factory()->create([
            'career_profile_id' => $s['career']->id, 'key' => "analyst-{$i}", 'visibility' => Visibility::Public, 'verification' => Verification::Verified, 'fact_type' => CareerFactType::Bullet,
            'statement' => "Built webhook-driven payment reporting number {$i} for recurring billing and payment gateway reconciliation.", 'attributable_type' => 'role', 'attributable_id' => $analyst->id,
        ]);
    }

    $synthesis = synthesize($s);
    $review = app(ReviewProfileSynthesis::class);
    $review->edit($synthesis, 'accomplishment:recurring', ['include' => false]);
    $effective = EffectiveProfile::resolve($synthesis->fresh());
    $relevance = app(JobRelevance::class)->analyze($s['analysis'], $synthesis->content['inventory']['facts'], $s['career']);
    $adapted = app(ProfileJobAdapter::class)->adapt($effective, $relevance);
    $payload = app(ResumeCandidatePayloadBuilder::class)->buildForCareer($s['career'], [], array_column($adapted['accomplishments'], 'fact_key'));
    $selection = app(ReferenceSelection::class)->build($adapted, $payload);
    $byRole = collect($selection['content']['experience'])->mapWithKeys(fn ($r) => [$r['role_id'] => collect($r['bullet_groups'])->flatMap(fn ($g) => $g['career_fact_keys'])->all()]);
    $all = $byRole->flatten()->all();

    // The analyst role has four highly relevant facts and still gets at most two bullets; the developer roles are unaffected.
    expect(count($byRole[$analyst->id] ?? []))->toBeLessThanOrEqual(2)
        // An exclusion the user made holds even though the job asks for it; the requirement is answered by another fact.
        ->and($all)->not->toContain('recurring')->and($all)->toContain('webhooks')
        // The job's top requirement has a bullet, and Private evidence is nowhere.
        ->and($all)->not->toContain('private-card', 'private-secret')
        ->and($adapted['job_priority_findings'])->not->toBeEmpty();
});

it('never publishes a Restricted-only figure through the summary, and cites the fact that holds a public metric', function () {
    $s = tailoringScenario();
    $synthesis = synthesize($s);
    app(ReviewProfileSynthesis::class)->approve($synthesis);
    $seen = new stdClass;
    // The model reaches for the confidential figure of a Restricted fact in the summary.
    $seen->summary = 'Full-Stack Developer who corrected 32,000 merchant records and cut reporting time by 85%.';
    app()->instance(GeneratesResumeWording::class, jobWordingStub($seen));

    $variant = app(GenerateResumeVariant::class)->generateForJob($s['analysis'], $synthesis->fresh());
    $summaryKeys = array_column($seen->input['summary_evidence'], 'key');

    // The summary cites the public fact that contains the 85% it states, no Private evidence is offered, and the
    // Restricted-only figure never reaches the resume.
    expect($summaryKeys)->toContain('nexus-85')->and(array_intersect($summaryKeys, ['private-secret', 'private-card']))->toBe([])
        ->and($variant->summary)->not->toContain('32,000')
        ->and($seen->input['job_context']['lead_with'])->not->toBeEmpty()->and($seen->input['job_context']['not_supported'])->toContain('Ruby on Rails')
        ->and(json_encode($variant->review_report))->not->toContain('SECRET-PROJECT-ZEBRA');
});

it('reads capabilities from statements so an older approved inventory still matches the job', function () {
    $s = tailoringScenario();
    $synthesis = synthesize($s);
    $content = $synthesis->content;
    // An inventory built before capability patterns were extended carries no capabilities for this fact.
    $content['inventory']['facts']['recurring']['capabilities'] = [];
    $relevance = app(JobRelevance::class)->analyze($s['analysis'], $content['inventory']['facts'], $s['career']);

    expect($relevance['facts']['recurring']['score'])->toBeGreaterThan(0.3);
});

/**
 * A profile-assisted resume for a job, written once with a stubbed model, plus a Workflow Intelligence build fact that
 * the written resume does not use.
 *
 * @return array{0: array<string, mixed>, 1: ResumeProfileSynthesis, 2: ResumeVariant, 3: stdClass}
 */
function writtenJobResume(): array
{
    $s = tailoringScenario();
    CareerFact::factory()->create([
        'career_profile_id' => $s['career']->id, 'key' => 'wi-built', 'statement' => 'Built Workflow Intelligence, a Laravel and Vue platform that gives engineering teams visibility into work items and delivery progress.',
        'visibility' => Visibility::Public, 'verification' => Verification::Verified, 'fact_type' => CareerFactType::Bullet, 'attributable_type' => 'project', 'attributable_id' => $s['projects']['flagship']->id,
    ]);
    $synthesis = synthesize($s);
    app(ReviewProfileSynthesis::class)->approve($synthesis);
    $seen = new stdClass;
    app()->instance(GeneratesResumeWording::class, jobWordingStub($seen));
    $variant = app(GenerateResumeVariant::class)->generateForJob($s['analysis'], $synthesis->fresh());

    return [$s, $synthesis, $variant, $seen];
}

/**
 * The draft the editor would send for a variant, unchanged, with an optional edit to each bullet.
 *
 * @return array<string, mixed>
 */
function unchangedDraft(ResumeVariant $variant): array
{
    $variant->load('experienceRoles.bullets', 'skillSelections');

    return [
        'summary' => (string) $variant->summary,
        'roles' => $variant->experienceRoles->sortBy('display_order')->map(fn ($r) => ['role_id' => $r->role_id, 'bullets' => $r->bullets->sortBy('display_order')->map(fn ($b) => ['id' => $b->id, 'text' => $b->text])->values()->all()])->values()->all(),
        'skills' => $variant->skillSelections->sortBy('display_order')->pluck('skill_id')->all(),
    ];
}

it('saves an edited resume as a new current variant without a model call, leaving the original untouched', function () {
    [$s, , $variant, $seen] = writtenJobResume();
    $fingerprint = fn () => md5(json_encode([ResumeVariant::find($variant->id)->only(['summary', 'review_report', 'selection_raw_response']), ResumeVariantExperienceBullet::where('resume_variant_id', $variant->id)->orderBy('id')->get(['id', 'text', 'display_order'])->toArray()]));
    $before = $fingerprint();
    $calls = $seen->calls;
    $draft = unchangedDraft($variant);
    $roleIndex = collect($draft['roles'])->search(fn ($r) => $r['role_id'] === $s['role']->id);
    $removed = $draft['roles'][$roleIndex]['bullets'][0];

    // Replace the first bullet with a Workflow Intelligence build fact, edit the summary, and reorder what is left.
    $draft['roles'][$roleIndex]['bullets'][0] = ['fact_key' => 'wi-built', 'replaces' => $removed['id'], 'text' => CareerFact::where('key', 'wi-built')->value('statement')];
    $draft['summary'] = 'Full-Stack Developer who owns applications end to end, including Workflow Intelligence, and builds payment integrations.';

    $revision = app(ReviseResumeVariant::class)->revise($s['posting'], $variant, $draft);
    $revision->load('experienceRoles.bullets.citations.careerFact');
    $texts = $revision->experienceRoles->flatMap(fn ($r) => $r->bullets->pluck('text'))->all();
    $jobResumes = app(JobResumes::class);

    expect($seen->calls)->toBe($calls)
        ->and($revision->id)->not->toBe($variant->id)->and($revision->parent_variant_id)->toBe($variant->id)
        ->and($revision->wording_generated_by)->toBe('manual-edit')
        ->and($revision->summary)->toContain('including Workflow Intelligence')
        ->and($texts)->toContain(CareerFact::where('key', 'wi-built')->value('statement'))->not->toContain($removed['text'])
        ->and($revision->experienceRoles->flatMap(fn ($r) => $r->bullets->flatMap(fn ($b) => $b->citations->map(fn ($c) => $c->careerFact->key)))->all())->toContain('wi-built')
        ->and(array_column($revision->edit_log['edits'], 'type'))->toContain('bullet_replaced', 'summary_edited')
        // The untouched bullets keep their wording; the original is exactly as it was.
        ->and(count($texts))->toBe($variant->experienceRoles()->withCount('bullets')->get()->sum('bullets_count'))
        ->and($fingerprint())->toBe($before)
        // The revision is the job's working resume; the earlier ones stay, newest first.
        ->and($jobResumes->current($s['posting'])->id)->toBe($revision->id)
        ->and(array_column($jobResumes->history($s['posting']), 'id'))->toBe([$revision->id, $variant->id])
        ->and($revision->edit_log['layout']['pages'])->toBeGreaterThan(0)
        // The page renders from the stored rows and is already stored.
        ->and(app(ResumePdfStore::class)->get(app(GenerateResumeDocument::class)->generate($revision)))->not->toBeNull();

    // Nothing in the revised resume still carries a finding that belonged to the bullet that was replaced.
    expect(collect($revision->review_report['items'])->pluck('label')->all())->toHaveCount(count($texts) + 1);
});

it('judges edited text by the generation rules, refuses what is blocked, and saves nothing', function () {
    [$s, , $variant] = writtenJobResume();
    $roleIndex = fn (array $draft) => collect($draft['roles'])->search(fn ($r) => $r['role_id'] === $s['role']->id);
    $count = ResumeVariant::count();

    // A technology the user has confirmed no experience with.
    $draft = unchangedDraft($variant);
    $draft['roles'][$roleIndex($draft)]['bullets'][0]['text'] = 'Built payment integrations in Ruby on Rails.';
    $blocked = null;

    try {
        app(ReviseResumeVariant::class)->revise($s['posting'], $variant, $draft);
    } catch (ResumeRevisionException $e) {
        $blocked = $e->errors;
    }

    expect($blocked)->toHaveKey("roles.{$s['role']->id}.bullets.0")->and($blocked["roles.{$s['role']->id}.bullets.0"])->toContain('Rails');

    // A fact the profile cannot use here: Private evidence, an unknown key, and a role it does not belong to.
    foreach (['private-secret', 'no-such-fact', 'nexus-built'] as $key) {
        $draft = unchangedDraft($variant);
        $draft['roles'][$roleIndex($draft)]['bullets'][] = ['fact_key' => $key, 'text' => 'Built something.'];
        $error = null;

        try {
            app(ReviseResumeVariant::class)->revise($s['posting'], $variant, $draft);
        } catch (ResumeRevisionException $e) {
            $error = $e->errors;
        }

        expect($error)->not->toBeNull("{$key} should be refused");
    }

    // A role keeps at least one bullet; a claim already on the resume cannot be added twice.
    $draft = unchangedDraft($variant);
    $draft['roles'][$roleIndex($draft)]['bullets'] = [];
    expect(fn () => app(ReviseResumeVariant::class)->revise($s['posting'], $variant, $draft))->toThrow(ResumeRevisionException::class);

    $draft = unchangedDraft($variant);
    $draft['roles'][$roleIndex($draft)]['bullets'][] = ['fact_key' => 'webhooks', 'text' => 'Built webhook handlers for payment events.'];
    expect(fn () => app(ReviseResumeVariant::class)->revise($s['posting'], $variant, $draft))->toThrow(ResumeRevisionException::class);

    expect(ResumeVariant::count())->toBe($count);
});

it('lets an added bullet cite several facts of one project, and refuses one that blends projects or cites a fact it does not state', function () {
    [$s, , $variant] = writtenJobResume();
    $roleId = $s['role']->id;
    $mk = fn (string $key, string $statement, Project $project) => CareerFact::factory()->create([
        'career_profile_id' => $s['career']->id, 'key' => $key, 'statement' => $statement, 'visibility' => Visibility::Public, 'verification' => Verification::Verified,
        'fact_type' => CareerFactType::Bullet, 'attributable_type' => 'project', 'attributable_id' => $project->id,
    ]);
    $mk('wi-delivered', 'Delivered the workflow dashboard to the Product Management team.', $s['projects']['flagship']);
    $mk('wi-normalized', 'Normalized tickets and requests into one unified model.', $s['projects']['flagship']);
    $other = Project::factory()->create(['career_profile_id' => $s['career']->id, 'role_id' => $roleId, 'name' => 'Side tool']);
    $mk('side-tool', 'Built a scheduling utility for the on-call rota.', $other);

    $revise = function (array $entry) use ($s, $variant, $roleId) {
        $draft = unchangedDraft($variant);
        $index = collect($draft['roles'])->search(fn ($r) => $r['role_id'] === $roleId);
        $draft['roles'][$index]['bullets'][] = $entry;

        try {
            return app(ReviseResumeVariant::class)->revise($s['posting'], $variant, $draft);
        } catch (ResumeRevisionException $e) {
            return $e->errors;
        }
    };
    $text = 'Delivered the workflow dashboard to the Product Management team, with tickets and requests normalized into one unified model.';

    // A fact that is not stated by the text is padding, so it is refused.
    $padded = $revise(['fact_keys' => ['wi-delivered', 'wi-normalized'], 'text' => 'Delivered the workflow dashboard to the Product Management team.']);
    expect($padded)->toBeArray()->and(implode(' ', $padded))->toContain('wi-normalized');

    // Facts from two projects would blend two pieces of work.
    $blended = $revise(['fact_keys' => ['wi-delivered', 'side-tool'], 'text' => 'Delivered the workflow dashboard and a scheduling utility for the on-call rota.']);
    expect($blended)->toBeArray()->and(implode(' ', $blended))->toContain('one project only');

    // Several eligible facts of one project, each stated: saved, citing each.
    $saved = $revise(['fact_keys' => ['wi-delivered', 'wi-normalized'], 'text' => $text]);
    expect($saved)->toBeInstanceOf(ResumeVariant::class);

    $bullet = $saved->experienceRoles->firstWhere('role_id', $roleId)->bullets->firstWhere('text', $text);
    expect($bullet->project_id)->toBe($s['projects']['flagship']->id)
        ->and($bullet->citations->map(fn ($c) => $c->careerFact->key)->sort()->values()->all())->toBe(['wi-delivered', 'wi-normalized'])
        ->and($variant->fresh()->experienceRoles->flatMap->bullets->pluck('text'))->not->toContain($text);
});

it('keeps a resume marked final through later generation and revision, and lets an earlier one become current', function () {
    [$s, , $variant] = writtenJobResume();
    $resumes = app(JobResumes::class);
    $resumes->setPreferred($s['posting'], $variant);

    $draft = unchangedDraft($variant);
    $draft['skills'] = array_slice($draft['skills'], 1);
    $revision = app(ReviseResumeVariant::class)->revise($s['posting'], $variant, $draft);
    $posting = $s['posting']->fresh();

    expect($resumes->current($posting)->id)->toBe($revision->id)->and($resumes->preferred($posting)->id)->toBe($variant->id)
        ->and($revision->skillSelections()->count())->toBe($variant->skillSelections()->count() - 1)
        ->and(array_column($revision->edit_log['edits'], 'type'))->toBe(['skill_removed']);

    $resumes->setCurrent($posting, $variant);
    $history = collect($resumes->history($posting->fresh()))->keyBy('id');

    expect($resumes->current($posting->fresh())->id)->toBe($variant->id)->and($history[$variant->id]['preferred'])->toBeTrue()->and($history[$variant->id]['current'])->toBeTrue()
        ->and($history[$revision->id]['kind'])->toBe('revision')->and($history[$revision->id]['summary'])->toBe('Removed 1 skill');
});

it('serves the workspace, suggestions and saving over HTTP, shows the revision on the job, and offers no Private fact', function () {
    [$s, , $variant] = writtenJobResume();
    $posting = $s['posting'];

    $this->get(route('jobs.profile-resumes.show', [$posting, $variant]))->assertOk()->assertInertia(fn ($page) => $page
        ->component('jobs/resumes/workspace')
        ->where('workspace.variant.id', $variant->id)->where('workspace.variant.current', true)->where('workspace.variant.editable', true)
        ->where('workspace.variant.kind', 'generated')->has('workspace.history', 1)->has('resume.experience'));

    $facts = $this->getJson(route('jobs.profile-resumes.replacement-facts', [$posting, $variant, 'role_id' => $s['role']->id]))->assertOk()->json('facts');
    $keys = array_column($facts, 'key');

    expect($keys)->toContain('wi-built')->not->toContain('private-secret', 'private-card', 'first-person', 'webhooks', 'nexus-built');

    $this->getJson(route('jobs.profile-resumes.replacement-facts', [$posting, $variant, 'role_id' => $s['role']->id, 'q' => 'Workflow']))->assertOk()
        ->assertJsonPath('facts.0.key', 'wi-built');

    $draft = unchangedDraft($variant);
    $draft['summary'] = 'Full-Stack Developer who owns applications end to end and ships payment integrations.';
    $response = $this->post(route('jobs.profile-resumes.revisions.store', [$posting, $variant]), $draft);
    $revision = ResumeVariant::where('parent_variant_id', $variant->id)->firstOrFail();

    $response->assertRedirect(route('jobs.profile-resumes.show', [$posting, $revision]));
    $this->get(route('jobs.profile-resumes.show', [$posting, $revision]))->assertInertia(fn ($page) => $page
        ->where('workspace.variant.kind', 'revision')->where('workspace.variant.parent_id', $variant->id)->has('workspace.history', 2));

    // A blocked edit comes back as errors next to the text, not a new variant.
    $draft['roles'][0]['bullets'][0]['text'] = 'Built payment integrations in Ruby on Rails.';
    $this->post(route('jobs.profile-resumes.revisions.store', [$posting, $revision]), $draft)->assertSessionHasErrors();
    expect(ResumeVariant::where('parent_variant_id', $revision->id)->count())->toBe(0);

    // The job's status points at the revision, and designations are set per job.
    $status = app(PresentTailoredResume::class)->present($posting->fresh());

    $this->post(route('jobs.profile-resumes.preferred', [$posting, $variant]), ['preferred' => true])->assertRedirect();
    expect(JobResumeSelection::where('job_posting_id', $posting->id)->value('preferred_resume_variant_id'))->toBe($variant->id);
    $this->post(route('jobs.profile-resumes.current', [$posting, $variant]))->assertRedirect();
    expect(JobResumeSelection::where('job_posting_id', $posting->id)->value('current_resume_variant_id'))->toBe($variant->id);
    expect($status)->toBeArray();

    // Another job's resume cannot be revised through this one.
    $other = JobPosting::factory()->create(['career_profile_id' => $s['career']->id]);
    $this->post(route('jobs.profile-resumes.revisions.store', [$other, $variant]), $draft)->assertNotFound();
});

it('keeps a finished run ready, with the current resume, when its PDF is no longer cached, but not when no render ever finished', function () {
    [$s, $synthesis, $variant] = writtenJobResume();
    $posting = $s['posting'];
    $runId = (string) Str::uuid();
    $attempt = fn (GenerationType $type, int $subject, string $subjectType, GenerationStatus $status) => GenerationAttempt::factory()->create([
        'subject_type' => $subjectType, 'subject_id' => $subject, 'generation_type' => $type, 'status' => $status,
        'pipeline_run_id' => $runId, 'chain' => true, 'resume_profile_synthesis_id' => $synthesis->id,
    ]);
    $attempt(GenerationType::ResumeVariant, $s['analysis']->id, 'job_analysis', GenerationStatus::Succeeded);
    $render = $attempt(GenerationType::ResumePdf, $variant->id, 'resume_variant', GenerationStatus::Succeeded);
    $revision = app(ReviseResumeVariant::class)->revise($posting, $variant, [...unchangedDraft($variant), 'summary' => 'Full-Stack Developer who owns applications end to end.']);

    // Nothing is cached for the generated variant (as after a template change), yet its render was validated.
    Storage::disk('local')->deleteDirectory('resume-pdfs');
    $status = app(PresentTailoredResume::class)->present($posting->fresh());

    expect($status['state'])->toBe('ready')->and($status['label'])->toBe('Ready for review')->and($status['current_stage'])->toBeNull()
        ->and($status['resume_variant_id'])->toBe($revision->id)->and(collect($status['stages'])->pluck('status')->unique()->all())->not->toContain('pending');

    // A render that never finished is still unfinished: this does not paper over a missing PDF.
    $render->update(['status' => GenerationStatus::Failed]);
    Storage::disk('local')->deleteDirectory('resume-pdfs');

    expect(app(PresentTailoredResume::class)->present($posting->fresh())['state'])->toBe('failed');
});

it('shows the revision, not the last generation, as the job\'s resume, and does not let a revision pass as a generation result', function () {
    [$s, $synthesis, $variant] = writtenJobResume();
    $draft = unchangedDraft($variant);
    $draft['summary'] = 'Full-Stack Developer who owns applications end to end and ships payment integrations.';
    $revision = app(ReviseResumeVariant::class)->revise($s['posting'], $variant, $draft);

    // The pipeline still sees the generated resume as its result: a revision is the user's edit of it.
    $state = app(TailoredResumePipeline::class)->inspectProfile($s['posting'], $synthesis);

    expect($state['variant']->id)->toBe($variant->id)
        ->and(app(JobResumes::class)->current($s['posting']->fresh())->id)->toBe($revision->id);
});

/**
 * A readiness-review model that answers from the resume passages it is shown.
 *
 * @param  Closure(array<string, string>, array<string, mixed>): array<string, mixed>  $answer  passage id => text, schema => structured answer
 */
function readinessModel(Closure $answer, ?stdClass $seen = null): GeneratesReadinessReview
{
    $seen ??= new stdClass;

    return new class($answer, $seen) implements GeneratesReadinessReview
    {
        public function __construct(private Closure $answer, private stdClass $seen) {}

        public function generate(string $systemPrompt, string $userPrompt, array $schema): ResumeWordingProviderResponse
        {
            $this->seen->calls = ($this->seen->calls ?? 0) + 1;
            $this->seen->system = $systemPrompt;
            $this->seen->input = json_decode($userPrompt, true);
            $this->seen->schema = $schema;
            $passages = array_column($this->seen->input['resume_passages'], 'text', 'id');

            return new ResumeWordingProviderResponse('fake', 'readiness-stub', ($this->answer)($passages, $schema), ['input_tokens' => 1200, 'output_tokens' => 300, 'reasoning_tokens' => 100]);
        }
    };
}

/** @param array<string, string> $passages */
function passageWith(array $passages, string $needle): string
{
    return array_key_first(array_filter($passages, fn ($t) => stripos($t, $needle) !== false)) ?? throw new RuntimeException("no passage with {$needle}");
}

function requirement(string $id, string $text, string $priority, string $coverage, string $depth, array $passages = [], array $findings = []): array
{
    return ['id' => $id, 'text' => $text, 'priority' => $priority, 'finding_ids' => $findings, 'coverage' => $coverage, 'depth' => $depth, 'passage_ids' => $passages, 'note' => 'Because.'];
}

it('holds the model\'s coverage judgments to the resume, classifies gaps from approved evidence, and cites only real passages', function () {
    [$s, , $variant] = writtenJobResume();
    $seen = new stdClass;
    app()->instance(GeneratesReadinessReview::class, readinessModel(function (array $p) {
        $webhooks = passageWith($p, 'webhook handlers');
        $recurring = passageWith($p, 'recurring billing');

        return [
            'requirements' => [
                requirement('R1', 'Build webhook-driven payment integrations with retries', 'critical', 'strong', 'built', [$webhooks]),
                requirement('R2', 'Own payment gateway and recurring billing integrations', 'critical', 'strong', 'built', [$recurring]),
                requirement('R3', 'Ruby on Rails experience', 'nice', 'missing', 'none'),
                requirement('R4', 'Working proficiency with React and TypeScript', 'important', 'strong', 'built', [$webhooks]),
                requirement('R5', 'Financial account aggregation with Plaid', 'nice', 'strong', 'built', []),
            ],
            'hiring_argument' => ['rating' => 'adequate', 'summary' => 'Payments are clear; engineering is thin.'],
            'emphasis' => ['over' => ['payments support'], 'under' => ['software built']],
            'recommendations' => [
                ['title' => 'Add Ruby on Rails to the skills', 'advice' => 'List Ruby on Rails so the keyword matches.', 'target' => 'skills', 'requirement_ids' => ['R3']],
                ['title' => 'Say what you built', 'advice' => 'Lead the webhook bullet with what you designed.', 'target' => $webhooks, 'requirement_ids' => ['R1']],
            ],
        ];
    }, $seen));

    $review = app(RunReadinessReview::class)->request($variant);
    app(RunReadinessReview::class)->run($review);
    $review->refresh();
    $result = $review->result;
    $byId = collect($result['requirements'])->keyBy('id');

    expect($review->status)->toBe('succeeded')->and($seen->calls)->toBe(1)
        // The model saw the resume as submitted, the job description, and nothing from the wider record.
        ->and(json_encode($seen->input))->toContain('Own payment integrations and the signup funnel')->not->toContain('SECRET-PROJECT-ZEBRA')->not->toContain('private-secret')
        // Built and strongly covered stays; supporting work for a requirement that asks to own it is partial, not strong.
        ->and($byId['R1']['coverage'])->toBe('strong')->and($byId['R1']['depth'])->toBe('built')
        ->and($byId['R2']['coverage'])->toBe('partial')->and($byId['R2']['depth'])->not->toBe('built')
        // A passage that does not state the requirement is not evidence for it; a claim with no citation is not a claim.
        ->and($byId['R4']['coverage'])->toBe('partial')
        ->and($byId['R5']['coverage'])->toBe('missing')->and($byId['R5']['passage_ids'])->toBe([])
        ->and(implode(' ', $result['risks']['adjustments']))->toContain('R2')->toContain('R4')->toContain('R5')
        ->and($result['counts'])->toBe(['strong' => 1, 'partial' => 2, 'missing' => 2])
        // Rails is a confirmed limitation: a qualification gap, and no advice to claim it survives.
        ->and($byId['R3']['gap']['status'])->toBe('not_established')->and($byId['R3']['gap']['qualification_gap'])->toBeTrue()->and($byId['R3']['gap']['candidates'])->toBe([])
        ->and(json_encode($result['improvements']))->not->toContain('Ruby on Rails')
        ->and(implode(' ', $result['risks']['adjustments']))->toContain('Ruby on Rails')
        ->and(array_column($result['gaps'], 'requirement_id'))->toContain('R3', 'R5')
        ->and($review->metrics)->toMatchArray(['model_calls' => 1, 'prompt_tokens' => 1200, 'completion_tokens' => 300])->and($review->metrics['duration_ms'])->toBeGreaterThanOrEqual(0);

    // A gap is never filled with Private evidence, and what is suggested is real, eligible and placeable.
    $suggested = collect($result['requirements'])->flatMap(fn ($r) => array_column($r['gap']['candidates'] ?? [], 'key'))->all();
    expect($suggested)->not->toContain('private-secret', 'private-card', 'first-person');

    // The saved resume is exactly as it was.
    expect(ResumeVariant::find($variant->id)->only(['summary', 'review_report']))->toBe($variant->only(['summary', 'review_report']));
});

it('suggests inserting omitted evidence only when the record states it and it fits a role on the resume', function () {
    [$s, , $variant] = writtenJobResume();
    app()->instance(GeneratesReadinessReview::class, readinessModel(fn (array $p) => [
        'requirements' => [
            requirement('R1', 'Experience building with Laravel and Vue', 'critical', 'missing', 'none'),
            requirement('R2', 'Payment webhook handling', 'important', 'strong', 'built', [passageWith($p, 'webhook handlers')]),
            requirement('R3', 'Kubernetes operations', 'nice', 'missing', 'none'),
        ],
        'hiring_argument' => ['rating' => 'weak', 'summary' => 'No software is shown.'],
        'emphasis' => ['over' => [], 'under' => []],
        'recommendations' => [['title' => 'Show a build', 'advice' => 'Say what you built.', 'target' => 'summary', 'requirement_ids' => ['R1']]],
    ]));
    $review = app(RunReadinessReview::class)->request($variant);
    app(RunReadinessReview::class)->run($review);
    $result = $review->fresh()->result;
    $byId = collect($result['requirements'])->keyBy('id');

    expect($byId['R3']['gap']['status'])->toBe('not_established')->and($byId['R3']['gap']['candidates'])->toBe([])->and($byId['R2']['gap']['status'])->toBe('in_resume');

    $laravel = $byId['R1']['gap'];
    $improvement = collect($result['improvements'])->firstWhere('kind', 'add_evidence');

    expect($laravel['status'])->toBe('omitted_available')->and($laravel['qualification_gap'])->toBeFalse()
        ->and(array_column($laravel['candidates'], 'key'))->toContain('wi-built')->and($laravel['candidates'][0]['role_id'])->toBe($s['role']->id)
        ->and($improvement['target'])->toMatchArray(['type' => 'role_fact', 'role_id' => $s['role']->id, 'fact_key' => 'wi-built'])
        // The improvements put the evidence the record states ahead of generic editing advice.
        ->and($result['improvements'][0]['kind'])->toBe('add_evidence');
});

it('checks the rendered document: structure, contact, dates, readable text, and states defects apart from suggestions', function () {
    [$s, , $variant] = writtenJobResume();
    $artifact = app(ResumeArtifact::class)->read($variant);
    $checks = app(DocumentChecks::class);
    $clean = $checks->run($variant, $artifact['passages'], $artifact['pdf_text'], $artifact['pages']);

    expect($artifact['pages'])->toBeGreaterThan(0)->and($artifact['pdf_text'])->toContain('PROFESSIONAL EXPERIENCE')
        ->and(array_column($clean, 'code'))->not->toContain('text_missing', 'heading_experience', 'pages', 'chronology', 'encoding');

    // Broken documents are defects; a title running into its dates is a suggestion about the text layer.
    $broken = $checks->run($variant, $artifact['passages'], "Nothing readable \u{FB01} \u{2014} here", 4);
    $codes = array_column($broken, 'code');

    expect($codes)->toContain('pages', 'text_layer', 'heading_experience', 'encoding', 'em_dash');
    expect(collect($broken)->firstWhere('code', 'pages')['kind'])->toBe('defect');

    $flow = $checks->run($variant, $artifact['passages'], str_repeat('Filler text for length. ', 30)."Developer Support EngineerAug 2025 – Oct 2026\nTools & Workflow:GitLab\nTechnical Skills Professional Experience Education", 2);
    expect(collect($flow)->firstWhere('code', 'text_flow_dates')['kind'])->toBe('suggestion')->and(collect($flow)->firstWhere('code', 'text_flow_labels')['kind'])->toBe('suggestion');
});

it('queues a review only when asked, shows a finished one only while it still matches, and needs a new one after any change', function () {
    [$s, , $variant] = writtenJobResume();
    Queue::fake();
    $url = route('jobs.profile-resumes.readiness.store', [$s['posting'], $variant]);
    $present = fn ($v = null) => app(PresentReadiness::class)->present($v ?? $variant->fresh());

    expect($present()['state'])->toBe('none');

    // Saving or editing never starts one.
    $draft = unchangedDraft($variant);
    $revision = app(ReviseResumeVariant::class)->revise($s['posting'], $variant, [...$draft, 'summary' => 'Full-Stack Developer who owns applications end to end and ships payment integrations.']);
    Queue::assertNothingPushed();

    $this->post($url)->assertRedirect();
    $this->post($url)->assertRedirect();
    Queue::assertPushed(RunReadinessReviewJob::class, 1);
    expect($present()['state'])->toBe('queued')->and(ResumeReadinessReview::count())->toBe(1);

    app()->instance(GeneratesReadinessReview::class, readinessModel(fn (array $p) => [
        'requirements' => [requirement('R1', 'Webhook handling', 'critical', 'strong', 'built', [passageWith($p, 'webhook handlers')])],
        'hiring_argument' => ['rating' => 'adequate', 'summary' => 'Fine.'], 'emphasis' => ['over' => [], 'under' => []],
        'recommendations' => [['title' => 'Tighten', 'advice' => 'Shorten.', 'target' => 'none', 'requirement_ids' => []]],
    ]));
    app(RunReadinessReview::class)->run(ResumeReadinessReview::first());

    $ready = $present();
    expect($ready['state'])->toBe('ready')->and($ready['result']['counts']['strong'])->toBe(1)->and($ready['fingerprints']['artifact'])->not->toBeEmpty()
        ->and($ready['fingerprints']['prompt'])->toBe(ReadinessPrompt::VERSION)
        // A different version of the resume has no review of its own.
        ->and($present($revision)['state'])->toBe('none');

    // The job description changes: the old review is not shown as current.
    $s['posting']->update(['description' => 'A different description of the same job.']);
    $stale = $present();
    expect($stale['state'])->toBe('stale')->and($stale)->not->toHaveKey('result')->and($stale['stale_reasons'][0])->toContain('description');

    // A failed model call is a failed review, with the reason, and nothing else changes.
    app()->instance(GeneratesReadinessReview::class, new class implements GeneratesReadinessReview
    {
        public function generate(string $systemPrompt, string $userPrompt, array $schema): ResumeWordingProviderResponse
        {
            throw new ResumeGenerationProviderException('Ollama request failed: connection error.');
        }
    });
    $again = app(RunReadinessReview::class)->request($variant->fresh());
    expect(fn () => app(RunReadinessReview::class)->run($again))->toThrow(ResumeGenerationProviderException::class);
    expect($again->fresh()->status)->toBe('failed')->and($present()['state'])->toBe('failed')->and($present()['message'])->toContain('connection error');
});

it('shows the readiness state on the workspace and refuses a review for another job\'s resume', function () {
    [$s, , $variant] = writtenJobResume();
    Queue::fake();

    $this->get(route('jobs.profile-resumes.show', [$s['posting'], $variant]))->assertOk()->assertInertia(fn ($page) => $page->where('readiness.state', 'none'));

    $other = JobPosting::factory()->create(['career_profile_id' => $s['career']->id]);
    $this->post(route('jobs.profile-resumes.readiness.store', [$other, $variant]))->assertNotFound();

    $this->post(route('jobs.profile-resumes.readiness.store', [$s['posting'], $variant]))->assertRedirect();
    $this->get(route('jobs.profile-resumes.show', [$s['posting'], $variant]))->assertInertia(fn ($page) => $page->where('readiness.state', 'queued'));
});

it('does not let a comparable framework stand in for a technology the candidate has no experience with', function () {
    $passages = [
        'skills' => ['id' => 'skills', 'label' => 'Skills', 'text' => 'Laravel, Node.js, PostgreSQL, React'],
        'b1' => ['id' => 'b1', 'label' => 'Role, bullet 1', 'text' => 'Built Workflow Intelligence, a Laravel and Vue platform backed by PostgreSQL.'],
    ];
    $output = [
        'requirements' => [
            requirement('R1', 'Server-side framework and relational database (Rails or comparable)', 'critical', 'strong', 'built', ['b1', 'skills']),
            requirement('R2', 'Relational database experience with PostgreSQL', 'important', 'strong', 'built', ['b1']),
        ],
        'hiring_argument' => ['rating' => 'adequate', 'summary' => 'Fine.'], 'emphasis' => ['over' => [], 'under' => []],
        'recommendations' => [['title' => 'Mention Rails', 'advice' => 'Add Rails to the skills.', 'target' => 'skills', 'requirement_ids' => ['R1']]],
    ];

    $checked = app(ValidateAssessment::class)->validate($output, $passages, [], ['Ruby on Rails', 'Rails']);
    $byId = collect($checked['assessment']['requirements'])->keyBy('id');

    expect($byId['R1']['coverage'])->toBe('partial')->and($byId['R1']['depth'])->toBe('adjacent')
        ->and($byId['R2']['coverage'])->toBe('strong')
        ->and($checked['assessment']['recommendations'])->toBe([])
        ->and(implode(' ', $checked['adjustments']))->toContain('R1')->toContain('Rails');

    // Nothing to assess is a failed review, not an empty one.
    expect(fn () => app(ValidateAssessment::class)->validate(['requirements' => []], $passages, []))->toThrow(ReadinessReviewException::class);
});

it('reports a resume with no concrete build as an engineering risk and offers the build evidence the record has', function () {
    [$s, , $variant] = writtenJobResume();
    $facts = collect(app(ResumeCandidatePayloadBuilder::class)->buildForCareer($s['career'])['career_facts'])->keyBy('key')->all();
    $sizes = app(CompositionChecks::class)->claimGroupSizes($s['career']);
    $isBuild = fn (ResumeVariantExperienceBullet $b) => $b->citations->contains(fn ($c) => app(CompositionChecks::class)->isConcreteBuild($c->careerFact->key, $facts, $sizes));

    // The same resume, minus every bullet that shows something built (each role keeps one bullet).
    $variant->load('experienceRoles.bullets.citations.careerFact');
    $draft = unchangedDraft($variant);

    foreach ($draft['roles'] as $i => $role) {
        $bullets = $variant->experienceRoles->firstWhere('role_id', $role['role_id'])->bullets->keyBy('id');
        $kept = array_values(array_filter($role['bullets'], fn ($b) => ! $isBuild($bullets[$b['id']])));
        // A role cannot be left empty: a role whose only bullet is a build gets a bullet that is not one.
        $draft['roles'][$i]['bullets'] = $kept !== [] ? $kept : [['fact_key' => 'nexus-85', 'replaces' => $role['bullets'][0]['id'], 'text' => CareerFact::where('key', 'nexus-85')->value('statement')]];
    }

    $revision = app(ReviseResumeVariant::class)->revise($s['posting'], $variant, $draft);
    app()->instance(GeneratesReadinessReview::class, readinessModel(fn (array $p) => [
        'requirements' => [requirement('R1', 'Webhook handling', 'critical', 'strong', 'built', [passageWith($p, 'webhook')])],
        'hiring_argument' => ['rating' => 'weak', 'summary' => 'No software is shown.'], 'emphasis' => ['over' => [], 'under' => ['software built']],
        'recommendations' => [['title' => 'Tighten', 'advice' => 'Shorten.', 'target' => 'none', 'requirement_ids' => []]],
    ]));
    $review = app(RunReadinessReview::class)->request($revision);
    app(RunReadinessReview::class)->run($review);
    $result = $review->fresh()->result;
    $engineering = collect($result['improvements'])->firstWhere('kind', 'engineering_evidence');

    expect(array_column($result['risks']['composition'], 'check'))->toContain('engineering_evidence')
        ->and($engineering)->not->toBeNull()->and($engineering['target']['type'])->toBe('role_fact')
        ->and(array_column($engineering['facts'], 'key'))->not->toContain('private-secret', 'first-person', 'ts-wi');
});

/** The Resume Profiles list summarizes each profile from the same state the detail page shows, read-only. */
it('summarizes a profile with no version as not started', function () {
    $s = synthesisScenario();

    $this->get(route('resume-profiles.index'))->assertInertia(fn (Assert $page) => $page
        ->where('profiles.0.slug', $s['profile']->slug)
        ->where('profiles.0.synthesis.approved_version', null)
        ->where('profiles.0.synthesis.draft_version', null)
        ->where('profiles.0.headline', null));
});

it('reports a draft and then an approved current version', function () {
    $s = synthesisScenario();
    $synthesis = synthesize($s);

    $this->get(route('resume-profiles.index'))->assertInertia(fn (Assert $page) => $page
        ->where('profiles.0.synthesis.draft_version', 1)
        ->where('profiles.0.synthesis.approved_version', null)
        ->where('profiles.0.headline', fn ($h) => is_string($h) && $h !== ''));

    $this->post(route('profile-syntheses.approve', $synthesis))->assertRedirect();

    $this->get(route('resume-profiles.index'))->assertInertia(fn (Assert $page) => $page
        ->where('profiles.0.synthesis.approved_version', 1)
        ->where('profiles.0.synthesis.approved_stale', false)
        ->where('profiles.0.synthesis.draft_version', null));
});

it('reports the approved version as stale only when approved evidence has changed', function () {
    $s = synthesisScenario();
    $synthesis = synthesize($s);
    $this->post(route('profile-syntheses.approve', $synthesis))->assertRedirect();

    EvidenceRevision::bump($s['career']);

    $this->get(route('resume-profiles.index'))->assertInertia(fn (Assert $page) => $page
        ->where('profiles.0.synthesis.approved_version', 1)
        ->where('profiles.0.synthesis.approved_stale', true));
});

it('does not change any data while rendering the list', function () {
    $s = synthesisScenario();
    $synthesis = synthesize($s);
    $this->post(route('profile-syntheses.approve', $synthesis))->assertRedirect();
    $before = [ResumeProfileSynthesis::count(), $synthesis->fresh()->updated_at, EvidenceRevision::current($s['career'])];

    $this->get(route('resume-profiles.index'))->assertOk();

    expect([ResumeProfileSynthesis::count(), $synthesis->fresh()->updated_at, EvidenceRevision::current($s['career'])])->toEqual($before);
});

// ---- The job page's Download PDF is the job's current resume, resolved when it is used.

/**
 * A job whose run finished (so the job page shows "ready"), with the generated resume and a revision of it that changes
 * only the summary, so the two PDFs differ in one findable sentence.
 *
 * @return array{0: array<string, mixed>, 1: ResumeVariant, 2: ResumeVariant}
 */
function jobWithGeneratedAndRevisedResume(bool $revise = true): array
{
    [$s, $synthesis, $variant] = writtenJobResume();
    $runId = (string) Str::uuid();
    $attempt = fn (GenerationType $type, int $subject, string $subjectType) => GenerationAttempt::factory()->create([
        'subject_type' => $subjectType, 'subject_id' => $subject, 'generation_type' => $type, 'status' => GenerationStatus::Succeeded,
        'pipeline_run_id' => $runId, 'chain' => true, 'resume_profile_synthesis_id' => $synthesis->id,
    ]);
    $attempt(GenerationType::ResumeVariant, $s['analysis']->id, 'job_analysis');
    $attempt(GenerationType::ResumePdf, $variant->id, 'resume_variant');
    $revision = $revise
        ? app(ReviseResumeVariant::class)->revise($s['posting'], $variant, [...unchangedDraft($variant), 'summary' => 'Platform engineer who ships workflow tooling for delivery teams.'])
        : $variant;

    return [$s, $variant, $revision];
}

/** The text of a downloaded PDF, whitespace collapsed so a sentence can be searched for. */
function pdfText(string $content): string
{
    return preg_replace('/\s+/', ' ', (new Parser)->parseContent($content)->getText());
}

it('serves the generated resume from the job download until a revision exists, and says which variant it served', function () {
    [$s, $generated] = jobWithGeneratedAndRevisedResume(revise: false);
    $posting = $s['posting'];
    $status = app(PresentTailoredResume::class)->present($posting->fresh());
    $response = $this->get($status['download_url']);

    // Generation records the current selection, and the job-level download resolves to it by content, not just status.
    expect(JobResumeSelection::where('job_posting_id', $posting->id)->value('current_resume_variant_id'))->toBe($generated->id)
        ->and($status['state'])->toBe('ready')->and($status['resume_variant_id'])->toBe($generated->id)
        ->and($status['download_url'])->toBe(route('jobs.resume.pdf', $posting));
    $response->assertOk();
    expect($response->headers->get('X-Resume-Variant-Id'))->toBe((string) $generated->id)
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and(pdfText($response->getContent()))->toContain('Full-Stack Developer who owns applications end to end.');
});

it('downloads the revision from the job page after revising, while the original stays available only through its own route', function () {
    [$s, $generated, $revision] = jobWithGeneratedAndRevisedResume();
    $posting = $s['posting'];
    $status = app(PresentTailoredResume::class)->present($posting->fresh());

    // Revising made the revision the job's current resume.
    expect(JobResumeSelection::where('job_posting_id', $posting->id)->value('current_resume_variant_id'))->toBe($revision->id)
        ->and($status['resume_variant_id'])->toBe($revision->id);

    $current = $this->get($status['download_url']);
    $historical = $this->get(route('resume-variants.pdf', $generated));
    $explicit = $this->get(route('resume-variants.pdf', $revision));

    // The job's button gives the revised text; the original's route still gives the original, and nothing mixes them up.
    expect($current->headers->get('X-Resume-Variant-Id'))->toBe((string) $revision->id)
        ->and(pdfText($current->getContent()))->toContain('Platform engineer who ships workflow tooling')->not->toContain('Full-Stack Developer who owns applications end to end.')
        ->and(pdfText($historical->getContent()))->toContain('Full-Stack Developer who owns applications end to end.')->not->toContain('Platform engineer who ships')
        ->and($historical->headers->get('X-Resume-Variant-Id'))->toBeNull()
        // The job download and the revision's own download are the same file.
        ->and(md5($current->getContent()))->toBe(md5($explicit->getContent()))
        ->and(md5($current->getContent()))->not->toBe(md5($historical->getContent()));
});

it('cannot serve a superseded resume from a job page that was loaded before the revision', function () {
    [$s, $generated] = jobWithGeneratedAndRevisedResume(revise: false);
    $posting = $s['posting'];

    // The page as it was loaded, then left open (or restored by Back): its Download PDF link is whatever it carried.
    $staleLink = app(PresentTailoredResume::class)->present($posting->fresh())['download_url'];
    $revision = app(ReviseResumeVariant::class)->revise($posting, $generated, [...unchangedDraft($generated), 'summary' => 'Platform engineer who ships workflow tooling for delivery teams.']);

    $response = $this->get($staleLink);

    // The link names the job, not a variant, so the revision is what it serves.
    expect($staleLink)->toBe(route('jobs.resume.pdf', $posting))->and($staleLink)->not->toContain('resume-variants')
        ->and($response->headers->get('X-Resume-Variant-Id'))->toBe((string) $revision->id)
        ->and(pdfText($response->getContent()))->toContain('Platform engineer who ships workflow tooling');

    // The real page props carry the same link, and the status endpoint a stale page polls says which resume is current.
    $this->get(route('jobs.show', $posting))->assertInertia(fn (Assert $page) => $page
        ->where('tailored_resume.download_url', route('jobs.resume.pdf', $posting))->where('tailored_resume.resume_variant_id', $revision->id));
    $this->getJson(route('jobs.tailored-resume.status', $posting))->assertJsonPath('resume_variant_id', $revision->id)->assertJsonPath('download_url', route('jobs.resume.pdf', $posting));
});

it('keeps the job download on the current resume when another is marked final, and follows an explicit change of the current one', function () {
    [$s, $generated, $revision] = jobWithGeneratedAndRevisedResume();
    $posting = $s['posting'];
    $served = fn () => $this->get(route('jobs.resume.pdf', $posting))->headers->get('X-Resume-Variant-Id');

    // Marking the original final is a designation only: the working copy, and so the download, stays the revision.
    $this->post(route('jobs.profile-resumes.preferred', [$posting, $generated]), ['preferred' => true])->assertRedirect();

    expect(JobResumeSelection::where('job_posting_id', $posting->id)->value('current_resume_variant_id'))->toBe($revision->id)
        ->and(JobResumeSelection::where('job_posting_id', $posting->id)->value('preferred_resume_variant_id'))->toBe($generated->id)
        ->and($served())->toBe((string) $revision->id);

    // Choosing an earlier resume as the current one is what moves the download.
    $this->post(route('jobs.profile-resumes.current', [$posting, $generated]))->assertRedirect();

    expect($served())->toBe((string) $generated->id)->and(JobResumeSelection::where('job_posting_id', $posting->id)->value('preferred_resume_variant_id'))->toBe($generated->id);

    // A later revision of the original becomes current again without touching the final designation.
    $again = app(ReviseResumeVariant::class)->revise($posting, $generated->fresh(), [...unchangedDraft($generated), 'summary' => 'Engineer who ships internal workflow software.']);

    expect($served())->toBe((string) $again->id)->and(JobResumeSelection::where('job_posting_id', $posting->id)->value('preferred_resume_variant_id'))->toBe($generated->id);
});

it('names the job-level download for the job, serves nothing for a job with no resume, and never crosses jobs', function () {
    [$s, , $revision] = jobWithGeneratedAndRevisedResume();
    $posting = $s['posting'];
    $response = $this->get(route('jobs.resume.pdf', $posting));

    // The filename is the job-specific name, identical to the per-variant route's for the same variant.
    expect($response->headers->get('Content-Disposition'))->toBe('attachment; filename="'.ResumePdfFilename::build($revision).'"')
        ->and(ResumePdfFilename::build($revision))->toContain('Resume-Acme-Finance-Full-Stack-Engineer')
        ->and($this->get(route('resume-variants.pdf', $revision))->headers->get('Content-Disposition'))->toBe($response->headers->get('Content-Disposition'));

    // ?inline=1 is the same document shown in the browser.
    expect($this->get(route('jobs.resume.pdf', [$posting, 'inline' => 1]))->headers->get('Content-Disposition'))->toStartWith('inline;');

    // A job with no resume has nothing to download; another job's resume is never served for it.
    $other = JobPosting::factory()->create(['career_profile_id' => $s['career']->id]);
    $this->get(route('jobs.resume.pdf', $other))->assertNotFound();
});

// ---- Page balance: a short last page is judged with the job's own evidence, and shown where the resume is.

it('records the layout judgment with a revision, and offers only relevant, Public approved facts that are not already on the resume', function () {
    [$s, , $variant] = writtenJobResume();
    $revision = app(ReviseResumeVariant::class)->revise($s['posting'], $variant, [...unchangedDraft($variant), 'summary' => 'Platform engineer who ships workflow tooling for delivery teams.']);

    // A small resume is one page: judged, recorded with its measurements, and not a defect.
    expect($revision->edit_log['layout'])->toHaveKeys(['pages', 'mode', 'last_page_fill', 'quality'])
        ->and($revision->edit_log['layout']['quality']['status'])->toBe('one_page')->and($revision->edit_log['layout']['quality']['severity'])->toBeNull();

    // The same resume with a nearly empty second page (a stored measurement) is judged against the job's evidence.
    $quality = app(AssessResumeLayout::class)->forStored($revision, ['pages' => 2, 'mode' => 'standard', 'last_page_fill' => 0.30]);
    $offered = array_column($quality['room']['candidates'], 'key');
    $onResume = array_merge(...$revision->experienceRoles->load('bullets.citations.careerFact')->flatMap(fn ($r) => $r->bullets->map(fn ($b) => $b->citations->map(fn ($c) => $c->careerFact->key)->all()))->all());

    expect($quality['status'])->toBe('underfilled')->and($quality['severity'])->toBe('review')->and($quality['room']['available'])->toBe(count($offered))
        // What is offered is Public and not already on the resume; Restricted and Private evidence is never offered.
        ->and($offered)->not->toContain('private-secret', 'private-card', 'remediation')->and(array_intersect($offered, $onResume))->toBe([])
        ->and(CareerFact::whereIn('key', $offered)->pluck('visibility')->unique()->map(fn ($v) => $v->value)->all())->toBe(['public']);
});

it('shows a mostly empty last page on the job and in the workspace, and nothing for a balanced one', function () {
    [$s, , $revision] = jobWithGeneratedAndRevisedResume();
    $posting = $s['posting'];
    $setLayout = fn (float $fill) => DB::table('resume_variants')->where('id', $revision->id)->update(['edit_log' => json_encode(['edits' => $revision->edit_log['edits'], 'layout' => ['pages' => 2, 'mode' => 'standard', 'last_page_fill' => $fill]])]);

    $setLayout(0.31);
    $status = app(PresentTailoredResume::class)->present($posting->fresh());

    expect($status['layout']['quality']['status'])->toBe('underfilled')->and($status['layout']['quality']['severity'])->toBe('review')
        ->and($status['layout']['pages'])->toBe(2);
    $this->get(route('jobs.profile-resumes.show', [$posting, $revision]))->assertInertia(fn (Assert $page) => $page
        ->where('workspace.variant.layout_quality.status', 'underfilled')->where('workspace.variant.layout_quality.severity', 'review'));

    $setLayout(0.70);

    expect(app(PresentTailoredResume::class)->present($posting->fresh())['layout']['quality']['status'])->toBe('balanced');
    $this->get(route('jobs.profile-resumes.show', [$posting, $revision]))->assertInertia(fn (Assert $page) => $page
        ->where('workspace.variant.layout_quality.status', 'balanced')->where('workspace.variant.layout_quality.severity', null));
});
