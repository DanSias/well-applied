<?php

namespace Tests\Support;

use App\Models\Role;

/**
 * Synthetic regression cases for first-draft resume composition. They reproduce the SHAPE of the problems found in real
 * generated resumes (the Lockheed Martin applications: an AI portfolio architect role, an enterprise IT software engineer
 * role and a full-stack senior staff role) without a single real fact, employer, client or figure: every name, statement
 * and number here is invented, so nothing confidential is committed and nothing depends on a saved variant.
 *
 * The career has four roles and five projects:
 *
 *   current   an engineering role with three projects (a workflow platform, a Q&A system, a content pipeline), a
 *             generic leadership claim, documentation work with an outcome, and an observability fact
 *   lead      a long analytics lead developer role: a flagship platform with two measured results and a generic
 *             "designed architectures" claim, a migration, a stakeholder claim, and a second small platform
 *   search    an analyst role (not an engineering role) with marketing evidence
 *   founder   an earlier "Full Stack Developer" role, mostly marketing work; `earlierEngineering` gives it web-engineering
 *             evidence a software job speaks to
 *
 * Cases (each is a job relevance profile over the same career, as the real jobs were):
 *   infrastructure   marketing evidence is irrelevant; a long earlier role has little to say (job 171's shape)
 *   architect        engineering evidence is relevant across the career, including the earlier role (jobs 157/172)
 */
final class CompositionCases
{
    public const BEACON = 1;

    public const LOOKUP = 2;

    public const EXPORTER = 3;

    public const NIMBUS = 4;

    public const FORECAST = 5;

    /** @var array<int, array{0: string, 1: string, 2: string, 3: int|null, 4: string, 5: float, 6: array<string, float>}> */
    private const FACTS = [
        // key, statement, role, project, kind, profile score, relevance by case [infrastructure, architect]
        ['b-what', 'Built Beacon, a Laravel and Vue platform that gives engineering teams visibility into work items and delivery progress.', 'current', 1, 'implementation', 0.35, ['infrastructure' => 0.45, 'architect' => 0.6]],
        ['b-unify', 'Normalized Jira work items and GitLab deployment activity into a single workflow model read by every dashboard.', 'current', 1, 'implementation', 0.15, ['infrastructure' => 0.4, 'architect' => 0.55]],
        ['b-deploy', 'Beacon was delivered and deployed to the Product Management team to track work items.', 'current', 1, 'implementation', 0.0, ['infrastructure' => 0.3, 'architect' => 0.45]],
        ['b-own', 'Independently owned Beacon from business requirements through architecture, implementation, testing and delivery.', 'current', 1, 'ownership', 0.45, ['infrastructure' => 0.7, 'architect' => 0.75]],
        ['b-own2', "Owned Beacon's frontend and backend implementation from requirements to delivery.", 'current', 1, 'ownership', 0.2, ['infrastructure' => 0.5, 'architect' => 0.6]],
        ['l-what', 'Built Lookup, a documentation Q&A system with deterministic retrieval and confidence scoring that limits the LLM to synthesis over pre-selected passages.', 'current', 2, 'implementation', 0.4, ['infrastructure' => 0.35, 'architect' => 0.8]],
        ['l-deploy', 'Lookup was delivered and deployed to the merchant support team for first-pass knowledge-base lookup.', 'current', 2, 'implementation', 0.0, ['infrastructure' => 0.3, 'architect' => 0.55]],
        ['l-reuse', "Extended Lookup's retrieval layer to draft support replies with citation-mapped grounding.", 'current', 2, 'implementation', 0.05, ['infrastructure' => 0.3, 'architect' => 0.6]],
        ['e-what', 'Built Exporter, a provider-abstracted pipeline that converts wiki content to deterministic Markdown with an auditable change report.', 'current', 3, 'implementation', 0.3, ['infrastructure' => 0.6, 'architect' => 0.55]],
        ['c-lead', 'Provided technical leadership by owning implementations and making architecture and technology decisions.', 'current', null, 'implementation', 0.67, ['infrastructure' => 0.66, 'architect' => 0.6]],
        ['c-docs', 'Created searchable developer documentation with SDK examples for merchant payment-gateway integration and onboarding.', 'current', null, 'ownership', 0.46, ['infrastructure' => 0.65, 'architect' => 0.4]],
        ['c-docs-result', 'Reduced new-merchant onboarding effort from about 10 to about 3 active hours.', 'current', null, 'outcome', 0.23, ['infrastructure' => 0.25, 'architect' => 0.3]],
        ['c-contact', 'Served as the technical point of contact for new merchants, meeting their technical teams and directing them to the right integration library for their application.', 'current', null, 'implementation', 0.19, ['infrastructure' => 0.7, 'architect' => 0.6]],
        ['c-obs', 'Used Datadog to investigate application and integration issues in a production payment-processing environment.', 'current', null, 'implementation', 0.3, ['infrastructure' => 0.6, 'architect' => 0.3]],

        ['n-what', 'Built Nimbus, a full-stack React and Node.js analytics platform that unifies BigQuery and Salesforce data behind one API layer.', 'lead', 4, 'ownership', 0.62, ['infrastructure' => 0.47, 'architect' => 0.75]],
        ['n-design', 'Designed and evolved Nimbus from the ground up, defining component boundaries and integrating third-party APIs.', 'lead', 4, 'ownership', 0.64, ['infrastructure' => 0.8, 'architect' => 0.8]],
        ['n-result-1', 'Nimbus reduced reporting time by 85%.', 'lead', 4, 'outcome', 0.2, ['infrastructure' => 0.44, 'architect' => 0.5]],
        ['n-result-2', 'Nimbus saved teams approximately 20 hours per week.', 'lead', 4, 'outcome', 0.2, ['infrastructure' => 0.44, 'architect' => 0.5]],
        ['l-migration', 'Collaborated on the Salesforce migration and BigQuery data warehouse implementation, moving legacy SQL Server reporting.', 'lead', null, 'implementation', 0.15, ['infrastructure' => 0.72, 'architect' => 0.55]],
        ['l-stakeholders', 'Partnered with marketing, finance and engineering stakeholders to turn business requirements into scalable applications.', 'lead', null, 'implementation', 0.0, ['infrastructure' => 0.61, 'architect' => 0.5]],
        ['f-what', 'Built Forecast, a multi-user budgeting platform with approval workflows that replaced spreadsheet planning.', 'lead', 5, 'ownership', 0.27, ['infrastructure' => 0.27, 'architect' => 0.5]],
        ['f-result', 'Forecast saved teams about 10 to 15 hours per month.', 'lead', 5, 'outcome', 0.2, ['infrastructure' => 0.25, 'architect' => 0.35]],

        ['s-lead', 'Led data-driven SEO for 34 university programs, combining technical SEO, analytics and automation.', 'search', null, 'outcome', 0.24, ['infrastructure' => 0.58, 'architect' => 0.3]],
        ['s-monitor', 'Built automated keyword and performance monitoring and used Google Analytics across the program portfolio.', 'search', null, 'implementation', 0.12, ['infrastructure' => 0.51, 'architect' => 0.35]],

        ['g-what', 'Founded a marketing technology consultancy that built custom web applications and CRM integrations for clients.', 'founder', null, 'implementation', 0.0, ['infrastructure' => 0.45, 'architect' => 0.6]],
        ['g-cro', 'Increased lead generation by 201% without additional ad spend by building and optimizing a direct-response landing page.', 'founder', null, 'outcome', 0.23, ['infrastructure' => 0.22, 'architect' => 0.22]],
        ['g-commerce', 'Implemented ecommerce functionality for clients, including cart integrations, checkout and payment flows.', 'founder', null, 'implementation', 0.35, ['infrastructure' => 0.35, 'architect' => 0.55]],
        ['g-cms', 'Built CMS and CRM integrations that connected landing pages to marketing automation.', 'founder', null, 'implementation', 0.15, ['infrastructure' => 0.28, 'architect' => 0.5]],
        ['g-testing', 'Contributed A/B testing and landing-page work to a paid-acquisition engagement as part of the team.', 'founder', null, 'implementation', 0.13, ['infrastructure' => 0.26, 'architect' => 0.2]],
    ];

    private const ROLES = [
        'current' => ['title' => 'Platform Support Engineer', 'start' => 2025, 'end' => null],
        'lead' => ['title' => 'Analytics Lead Developer', 'start' => 2015, 'end' => 2024],
        'search' => ['title' => 'Search Analyst', 'start' => 2013, 'end' => 2015],
        'founder' => ['title' => 'Founder & Full Stack Developer', 'start' => 2005, 'end' => 2013],
    ];

    /**
     * The two inputs ReferenceSelection::build() reads, shaped as a resume written for a job reads them (the profile
     * adapted to the job: each accomplishment carries the job's relevance).
     *
     * @param  array<string, mixed>  $factOverrides  key => replacement fields (statement, visibility, included, pinned, job, findings, general, bare_tag); `_priority` => the requirement ids the job guarantees a bullet
     * @return array{0: array<string, mixed>, 1: array{career_facts: array<int, array<string, mixed>>}, 2: array<string, int>}
     */
    public static function forJob(string $case, array $factOverrides = []): array
    {
        $roleIds = [];

        foreach (self::ROLES as $name => $role) {
            $roleIds[$name] = Role::factory()->create(['title' => $role['title'], 'start_year' => $role['start'], 'end_year' => $role['end']])->id;
        }

        $accomplishments = [];
        $facts = [];
        $offered = [];

        foreach (self::FACTS as $i => [$key, $statement, $role, $project, $kind, $profile, $relevance]) {
            $o = $factOverrides[$key] ?? [];
            $job = $o['job'] ?? $relevance[$case];
            $accomplishments[] = [
                'id' => "accomplishment:{$key}", 'fact_key' => $key, 'project_id' => $project, 'role_id' => $roleIds[$role], 'kind' => $kind,
                'profile_score' => $profile, 'score' => round(0.5 * $profile + $job, 3), 'job_relevance' => $job, 'job_findings' => $o['findings'] ?? [],
                'general' => $o['general'] ?? false, 'bare_tag' => $o['bare_tag'] ?? false,
                'included' => $o['included'] ?? true, 'pinned' => $o['pinned'] ?? false, 'rank' => $i + 1,
            ];
            $facts[$key] = ['type' => 'bullet', 'statement' => $o['statement'] ?? $statement, 'capabilities' => []];
            $offered[] = ['key' => $key, 'statement' => $o['statement'] ?? $statement, 'visibility' => $o['visibility'] ?? 'public', 'skills' => [], 'attribution' => ['role_id' => $roleIds[$role], 'project_id' => $project, 'project' => $project === null ? null : ['', 'Beacon', 'Lookup', 'Exporter', 'Nimbus', 'Forecast'][$project]]];
        }

        usort($accomplishments, fn (array $a, array $b) => $b['score'] <=> $a['score']);

        foreach ($accomplishments as $n => &$a) {
            $a['rank'] = $n + 1;
        }
        unset($a);

        $effective = [
            'projects' => array_map(fn (int $pid) => ['id' => "project:{$pid}", 'project_id' => $pid, 'personal' => false, 'included' => true, 'pinned' => false, 'rank' => $pid, 'fact_keys' => []], [1, 2, 3, 4, 5]),
            'accomplishments' => $accomplishments, 'facts' => $facts,
            'presentation' => ['summary_fact_keys' => [], 'differentiators' => [], 'headline' => 'Full-Stack Developer', 'summary' => 'x'],
            'capabilities' => ['direct' => []],
            'job_priority_findings' => $factOverrides['_priority'] ?? [],
        ];

        return [$effective, ['career_facts' => $offered], $roleIds];
    }

    /**
     * Keys chosen per role name.
     *
     * @param  array{content: array<string, mixed>}  $result
     * @param  array<string, int>  $roleIds
     * @return array<string, array<int, string>>
     */
    public static function chosenByRole(array $result, array $roleIds): array
    {
        $byId = [];

        foreach ($result['content']['experience'] as $role) {
            $byId[$role['role_id']] = array_merge([], ...array_column($role['bullet_groups'], 'career_fact_keys'));
        }

        return array_map(fn (int $id) => $byId[$id] ?? [], $roleIds);
    }

    /**
     * Bullet groups (fact-key lists) per role name.
     *
     * @param  array{content: array<string, mixed>}  $result
     * @param  array<string, int>  $roleIds
     * @return array<string, array<int, array<int, string>>>
     */
    public static function groupsByRole(array $result, array $roleIds): array
    {
        $byId = [];

        foreach ($result['content']['experience'] as $role) {
            $byId[$role['role_id']] = array_column($role['bullet_groups'], 'career_fact_keys');
        }

        return array_map(fn (int $id) => $byId[$id] ?? [], $roleIds);
    }
}
