<?php

namespace Tests\Support;

use App\Contracts\GeneratesResumeWording;
use App\Enums\CareerFactType;
use App\Enums\Verification;
use App\Enums\Visibility;
use App\Models\CareerFact;
use App\Models\Employer;
use App\Models\JobAnalysis;
use App\Models\JobAnalysisFinding;
use App\Models\JobPosting;
use App\Models\Project;
use App\Models\ResumeProfileSynthesis;
use App\Models\Role;
use App\Models\Skill;
use App\Support\ProfileSynthesis\ReviewProfileSynthesis;
use App\Support\ProfileSynthesis\SynthesizeProfile;
use App\Support\ResumeProfile\QuestionWriter;
use App\Support\ResumeVariant\ResumeWordingProviderResponse;
use Tests\Support\ResumeProfileFixtures as F;

/**
 * A small invented career with an approved profile and a job analysis, for tests that run the real job-tailoring
 * stages (relevance, adaptation, selection, wording validation) against a database. Every name and statement is
 * invented. Wording is answered by a scripted double, never a model.
 */
final class CompositionScenario
{
    /**
     * @return array<string, mixed>
     */
    public static function create(): array
    {
        $f = F::career();
        $career = $f['career'];

        foreach (['TypeScript', 'React', 'Node.js', 'BigQuery'] as $name) {
            Skill::factory()->create(['career_profile_id' => $career->id, 'name' => $name, 'slug' => strtolower($name)]);
        }

        $fact = function (string $key, string $statement, string $type, int $id, Visibility $v = Visibility::Public, array $skills = [], ?string $group = null) use ($career) {
            $fact = CareerFact::factory()->create([
                'career_profile_id' => $career->id, 'key' => $key, 'statement' => $statement, 'visibility' => $v, 'verification' => Verification::Verified,
                'fact_type' => CareerFactType::Bullet, 'attributable_type' => $type, 'attributable_id' => $id, 'claim_group' => $group,
            ]);
            $fact->skills()->sync(array_map(fn (string $n) => Skill::query()->where('career_profile_id', $career->id)->where('name', $n)->firstOrFail()->id, $skills));

            return $fact;
        };

        $lead = Role::factory()->create(['employer_id' => Employer::factory()->create(['career_profile_id' => $career->id, 'name' => 'Northwind'])->id, 'title' => 'Data Lead Developer', 'start_year' => 2015, 'end_year' => 2024]);
        $flagship = Project::factory()->create(['role_id' => $f['role']->id, 'name' => 'Beacon']);
        $nimbus = Project::factory()->create(['role_id' => $lead->id, 'name' => 'Nimbus']);
        $role = $f['role'];

        $facts = [
            'b-what' => $fact('b-what', 'Built Beacon, a React and TypeScript platform that gives teams visibility into work items and delivery progress.', 'project', $flagship->id, skills: ['React', 'TypeScript']),
            'b-integration' => $fact('b-integration', 'Integrated the Jira and GitLab APIs behind one backend client that feeds every Beacon view.', 'project', $flagship->id),
            'b-deploy' => $fact('b-deploy', 'Beacon was delivered and deployed to the Product Management team to track work items.', 'project', $flagship->id),
            'b-owned' => $fact('b-owned', 'Independently owned Beacon from business requirements through architecture, implementation, testing and delivery.', 'project', $flagship->id),
            'n-what' => $fact('n-what', 'Built Nimbus, a full-stack React and Node.js analytics platform unifying BigQuery and Salesforce data behind one API layer.', 'project', $nimbus->id, skills: ['React', 'Node.js', 'BigQuery']),
            'n-result' => $fact('n-result', 'Nimbus reduced reporting time by 85%.', 'project', $nimbus->id),
            'webhooks' => $fact('webhooks', 'Built webhook handlers for payment events with retry schedules and idempotent processing of duplicate deliveries.', 'role', $role->id),
            'recurring' => $fact('recurring', 'Set up recurring billing for merchants through the payment gateway API.', 'role', $role->id),
            'first-person' => $fact('first-person', "I've used JavaScript extensively throughout my career.", 'role', $role->id),
            'trivia' => $fact('trivia', 'Used a small utility at work.', 'role', $role->id),
            'restricted-figure' => $fact('restricted-figure', 'Corrected approximately 32,000 transaction records for a merchant through idempotent, audited writes.', 'role', $role->id, Visibility::Restricted),
            'private' => $fact('private', 'Handled the SECRET-PROJECT-ZEBRA payment outage.', 'role', $role->id, Visibility::Private),
        ];

        F::sampleWith($f['profile'], array_fill(0, 3, ['required' => ['TypeScript', 'React', 'Node.js'], 'duties' => ['Design and build REST APIs.']]));

        $posting = JobPosting::factory()->create(['career_profile_id' => $career->id, 'title' => 'Full Stack Engineer', 'company' => 'Acme Finance']);
        $analysis = JobAnalysis::factory()->create(['job_posting_id' => $posting->id, 'prompt_version' => 'job-analysis-v4', 'role_summary' => 'Own payment integrations and internal platforms.']);
        $finding = fn (string $category, string $label, string $statement, ?string $emphasis = null) => JobAnalysisFinding::factory()->create(array_filter([
            'job_analysis_id' => $analysis->id, 'category' => $category, 'label' => $label, 'statement' => $statement, 'emphasis' => $emphasis,
        ]));
        $finding('required_qualification', 'webhook_integration', 'Strong experience building webhook-driven payment integrations with retries and idempotency.', 'high');
        $finding('responsibility', 'payments', 'Own payment gateway and recurring billing integrations.');
        $finding('required_qualification', 'frontend', 'Working proficiency with React and TypeScript.');
        $finding('preferred_qualification', 'rails', 'Ruby on Rails experience.');

        return [...$f, 'facts' => $facts, 'projects' => compact('flagship', 'nimbus'), 'lead' => $lead, 'posting' => $posting, 'analysis' => $analysis->fresh()];
    }

    /**
     * An approved profile for the scenario, written by a scripted writer.
     *
     * @param  array<string, mixed>  $s
     */
    public static function approvedProfile(array $s): ResumeProfileSynthesis
    {
        $synthesis = self::draftProfile($s);
        app(ReviewProfileSynthesis::class)->approve($synthesis);

        return $synthesis->fresh();
    }

    /**
     * The same profile as a draft, which the user can still edit (include, exclude, pin, reword).
     *
     * @param  array<string, mixed>  $s
     */
    public static function draftProfile(array $s): ResumeProfileSynthesis
    {
        $presentation = [
            'headline' => 'Full-Stack Developer',
            'summary' => 'Full-Stack Developer who independently owned Beacon from business requirements through delivery. Built Nimbus, a full-stack React and Node.js analytics platform unifying BigQuery and Salesforce data, reducing reporting time by 85%.',
            'summary_fact_keys' => ['b-owned', 'n-what', 'n-result'],
            'differentiators' => [
                ['statement' => 'Owned Beacon end to end, from business requirements to delivery.', 'fact_keys' => ['b-owned']],
                ['statement' => 'Built a full-stack analytics platform that reduced reporting time by 85%.', 'fact_keys' => ['n-what', 'n-result']],
                ['statement' => 'Builds payment webhook handlers with retry schedules and idempotent processing of duplicate deliveries.', 'fact_keys' => ['webhooks']],
            ],
            'project_notes' => [['project_id' => $s['projects']['flagship']->id, 'note' => 'Owned end to end.', 'fact_keys' => ['b-owned']]],
        ];
        app()->instance(QuestionWriter::class, new FakeSequenceWriter([$presentation], false));

        return app(SynthesizeProfile::class)->synthesize($s['profile']);
    }

    /**
     * A wording double. Each call is recorded on `$seen` (calls, corrections, input); a bullet is the statements of its
     * facts, the summary is `$seen->summary`, and a summary correction answers `$seen->correction` (empty by default, as
     * a model that produced nothing usable). `$seen->bullet` may replace the text of one bullet: fn (array $group): ?string.
     */
    public static function wording(\stdClass $seen): GeneratesResumeWording
    {
        return new class($seen) implements GeneratesResumeWording
        {
            public function __construct(private \stdClass $seen) {}

            public function generate(string $system, string $user, array $schema): ResumeWordingProviderResponse
            {
                $this->seen->calls = ($this->seen->calls ?? 0) + 1;
                $this->seen->system = $system;

                if (str_contains($user, '--- Summary correction input ---')) {
                    $this->seen->corrections = ($this->seen->corrections ?? 0) + 1;
                    preg_match('/--- Summary correction input ---\n(.*?)\n--- End of summary correction input ---/s', $user, $c);
                    $this->seen->correctionInput = json_decode($c[1], true);

                    return new ResumeWordingProviderResponse('fake', 'stub', ['summary' => $this->seen->correction ?? '']);
                }

                if (str_contains($user, '--- Bullet correction input ---')) {
                    $this->seen->bulletCorrections = ($this->seen->bulletCorrections ?? 0) + 1;
                    preg_match('/--- Bullet correction input ---\n(.*?)\n--- End of bullet correction input ---/s', $user, $c);
                    $this->seen->bulletCorrectionInput = json_decode($c[1], true);

                    return new ResumeWordingProviderResponse('fake', 'stub', ['text' => $this->seen->bulletCorrection ?? '']);
                }

                preg_match('/--- Approved selection[^\n]*\n(.*?)\n--- End of approved selection/s', $user, $m);
                $in = json_decode($m[1], true);
                $this->seen->input = $in;
                $all = fn (array $facts) => implode(' ', array_map(fn ($f) => rtrim((string) preg_replace('/\s+/', ' ', $f['statement']), '.').'.', $facts));

                return new ResumeWordingProviderResponse('fake', 'stub', [
                    'summary' => $this->seen->summary ?? 'Full-Stack Developer who builds internal platforms and payment integrations for engineering and product teams. Builds React and TypeScript applications, integrates third-party APIs, and delivers them to the teams that use them, with reliable handling of payment events and a focus on clear, maintainable code.',
                    'experience' => array_map(fn ($r) => ['role_id' => $r['role_id'], 'bullets' => array_map(fn ($g) => ['bullet_group_index' => $g['bullet_group_index'], 'text' => (isset($this->seen->bullet) ? ($this->seen->bullet)($g) : null) ?? $all($g['career_facts'])], $r['bullet_groups'])], $in['experience']),
                    'selected_projects' => [],
                ]);
            }
        };
    }
}
