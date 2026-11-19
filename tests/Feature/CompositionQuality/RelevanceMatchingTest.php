<?php

use App\Models\CareerProfile;
use App\Models\JobAnalysis;
use App\Models\JobAnalysisFinding;
use App\Models\JobPosting;
use App\Support\JobTailoring\JobRelevance;
use App\Support\ProfileSynthesis\StoryDimensions;
use App\Support\ResumeProfile\CapabilityCoverage;

/**
 * How a posting's wording is matched to a fact's. Relevance ranks evidence; it authorizes nothing. Everything here is
 * deterministic and built by hand (no profile, no model), and nothing is keyed to an employer, a fact id or a posting: each case
 * is a general property of the matching, shown with realistic statements.
 *
 * The properties: a word shared by chance is not alignment ("execute" and "executive", "planning stack" and "project planning");
 * one shared word counts for less than two; a word half the record uses says nothing; a statement with nothing concrete in it
 * counts for less when only words tie it to the posting; and a capability a posting asks for (project delivery, stakeholder
 * work, requirements) credits the facts that state it, and only then.
 */
function matched(array $findings, array $facts, string $title = 'Senior Software Engineer'): array
{
    $career = CareerProfile::factory()->create();
    $posting = JobPosting::factory()->create(['career_profile_id' => $career->id, 'title' => $title]);
    $analysis = JobAnalysis::factory()->create(['job_posting_id' => $posting->id, 'prompt_version' => 'job-analysis-v4']);

    foreach ($findings as $i => [$category, $statement]) {
        JobAnalysisFinding::factory()->create(['job_analysis_id' => $analysis->id, 'category' => $category, 'label' => "f{$i}", 'statement' => $statement]);
    }

    $inventory = [];

    foreach ($facts as $key => $fact) {
        [$statement, $project] = is_array($fact) ? $fact : [$fact, null];
        $inventory[$key] = ['statement' => $statement, 'visibility' => 'public', 'capabilities' => [], 'role_id' => 1, 'project_id' => $project, 'support_only' => false];
    }

    $relevance = app(JobRelevance::class)->analyze($analysis->fresh(), $inventory, $career);

    return array_map(fn (array $f) => $f['score'], $relevance['facts']);
}

/** Unrelated facts that make a pool, so a word's frequency across the record means something. */
function filler(int $n = 12): array
{
    $topics = ['Migrated the nightly billing export to a new scheduler.', 'Wrote runbooks for the on-call rotation and incident handoff.', 'Reduced container image size by trimming base layers.',
        'Added feature flags to roll out the new settings page gradually.', 'Documented the public API with worked examples for each endpoint.', 'Replaced a hand-rolled cache with a managed Redis cluster.',
        'Introduced contract tests between the mobile client and the gateway.', 'Cleaned up flaky browser tests and added retry diagnostics.', 'Moved secrets from environment files to a vault.',
        'Standardized logging fields across the checkout services.', 'Profiled slow queries and added covering indexes to the orders table.', 'Automated certificate renewal for the edge proxies.'];

    return array_slice($topics, 0, $n, true);
}

it('stems inflections together and leaves words that merely start alike apart', function () {
    $same = [['develop', 'developer'], ['developer', 'development'], ['developing', 'developed'], ['planning', 'planned'], ['manager', 'management'], ['requirements', 'required'],
        ['integration', 'integrate'], ['execute', 'executed'], ['idempotent', 'idempotency'], ['scalable', 'scalability'], ['authorize', 'authorization'], ['delivery', 'deliver']];
    $apart = [['execute', 'executive'], ['internal', 'international'], ['architecture', 'architect']];
    $stem = fn (string $w) => JobRelevance::stem($w);

    expect(array_values(array_filter($same, fn ($p) => $stem($p[0]) !== $stem($p[1]))))->toBe([])
        ->and(array_values(array_filter($apart, fn ($p) => $stem($p[0]) === $stem($p[1]))))->toBe([]);
});

it('does not match "execute" to "executive"', function () {
    $scores = matched(
        [['responsibility', 'Execute test cases aligned to release milestones.']],
        [
            'dashboard' => ['Built the Executive Insights Dashboard for the leadership group, consolidating departmental reporting.', 1],
            'tests' => ['Executed regression test cases against each release build and tracked failures.', 2],
            ...array_map(fn ($s) => $s, filler(6)),
        ],
    );

    expect($scores['tests'])->toBeGreaterThan(0.25)->and($scores['dashboard'])->toBeLessThan($scores['tests'] / 2);
});

it('does not read "planning stack" as "project planning"', function () {
    $scores = matched(
        [['responsibility', 'Contribute to the autonomy planning stack.']],
        [
            'project' => 'Responsible for project planning, status reporting and delivery of internal initiatives.',
            'stack' => 'Built the planning stack for the vehicle, including cost functions and trajectory scoring.',
            ...filler(6),
        ],
    );

    // One shared word ("planning") is a coincidence; the same two words side by side ("planning stack") is the requirement.
    expect($scores['project'])->toBeLessThan(0.06)->and($scores['stack'])->toBeGreaterThan(4 * $scores['project']);
});

it('counts one shared word for less than two', function () {
    $scores = matched(
        [['responsibility', 'Maintain the telemetry ingestion pipeline and its alerting thresholds.']],
        ['one' => 'Maintained a reporting spreadsheet for the finance group.', 'two' => 'Maintained the ingestion pipeline for event data and tuned thresholds.', ...filler(6)],
    );

    expect($scores['two'])->toBeGreaterThan($scores['one'] * 1.5);
});

it('rewards the same two words side by side', function () {
    $scores = matched(
        [['required_qualification', 'Experience with webhook retry handling in payment systems.']],
        ['phrase' => 'Built webhook retry handling for payment events.', 'scattered' => 'Retry logic for the payment ledger, plus webhook documentation.', ...filler(6)],
    );

    expect($scores['phrase'])->toBeGreaterThan($scores['scattered']);
});

it('does not let a word half the record uses outweigh a rare one', function () {
    $common = array_fill(0, 8, 'Wrote application development guides for the internal platform.');
    $facts = ['common' => 'Application development for the internal tools group.', 'rare' => 'Operated the Kubernetes clusters for the internal tools group.'];
    foreach ($common as $i => $c) {
        $facts["c{$i}"] = $c;
    }

    $scores = matched([['required_qualification', 'Application development experience and Kubernetes operations.']], $facts);

    expect($scores['rare'])->toBeGreaterThan($scores['common']);
});

it('still credits genuine multi-term technical alignment', function () {
    $scores = matched(
        [['required_qualification', 'Strong experience building webhook handlers with retry schedules and idempotent processing of payment events.']],
        ['aligned' => 'Built webhook handlers for payment events with retry schedules and idempotency for duplicate deliveries.', 'unrelated' => 'Organized the quarterly offsite and managed the venue contract.', ...filler(6)],
        'Backend Engineer',
    );

    expect($scores['aligned'])->toBeGreaterThanOrEqual((float) config('resume.composition.allocation.relevant_at'))
        ->and($scores['aligned'])->toBeGreaterThan(3 * $scores['unrelated']);
});

it('counts a statement with nothing concrete in it for less when only words tie it to the posting', function () {
    // The same words, once as a general claim and once as work in a named project: only the second is anchored.
    $statement = 'Handled planning and delivery of reporting projects for several internal groups.';
    $scores = matched(
        [['responsibility', 'Plan and deliver reporting projects with clear technical ownership.']],
        ['general' => $statement, 'anchored' => [$statement, 4], ...filler(6)],
        'Software Engineer',
    );

    expect($scores['anchored'])->toBeGreaterThan($scores['general']);
});

it('credits project delivery and stakeholder work only when the posting asks for it', function () {
    $facts = [
        'delivery' => 'Managed multiple client projects from requirements gathering and solution design through development, launch, and ongoing support.',
        'collab' => 'Worked directly with clients and stakeholders to translate business objectives into technical requirements, coordinate priorities, and deliver practical solutions.',
        'build' => ['Built a React and Node.js analytics platform unifying BigQuery and Salesforce data behind one API layer.', 6],
        ...filler(6),
    ];
    $asks = matched([
        ['responsibility', 'Own projects from requirements gathering through launch and ongoing support.'],
        ['responsibility', 'Partner with business stakeholders and executives to translate needs into technical requirements.'],
        ['required_qualification', 'Experience with React and Node.js.'],
    ], $facts, 'Technical Project Lead');
    $quiet = matched([
        ['responsibility', 'Execute test cases aligned to release milestones on the test vehicle.'],
        ['required_qualification', 'Experience debugging Linux system behavior and writing Bash scripts.'],
    ], $facts, 'Test Engineer');

    // A fact the posting shares nothing with is not scored at all.
    $quietScore = fn (string $key) => $quiet[$key] ?? 0.0;

    expect($asks['delivery'])->toBeGreaterThan(0.5)->and($asks['collab'])->toBeGreaterThan(0.5)
        ->and($asks['delivery'])->toBeGreaterThan($quietScore('delivery') + 0.3)->and($asks['collab'])->toBeGreaterThan($quietScore('collab') + 0.3)
        // For the posting that asks for none of it, the general statements do not outrank the concrete build.
        ->and($quietScore('build'))->toBeGreaterThanOrEqual(max($quietScore('delivery'), $quietScore('collab')));
});

it('does not rank a general responsibility above a concrete deliverable for a posting that asks for neither', function () {
    $scores = matched(
        [['responsibility', 'Build and maintain internal tooling to improve operational efficiency.'], ['required_qualification', 'Experience with Python and Bash scripting.']],
        [
            'general' => 'Balanced hands-on development with project planning, client relationships, and delivery responsibilities as an independent founder and developer.',
            'concrete' => ['Built CMS and CRM integrations and marketing-automation tooling as part of client web-application work.', 3],
            ...filler(6),
        ],
    );

    expect($scores['concrete'])->toBeGreaterThan($scores['general']);
});

it('keeps a bounded, deterministic ranking for a posting that names few or no recognized capabilities', function () {
    $findings = [['responsibility', 'Create V&V plans and develop test matrices derived from product requirements.'], ['required_qualification', 'Ability to identify software failures caused by hardware sensors.'], ['domain_knowledge', 'Focus of testing work is the autonomy planning stack.']];
    $facts = [
        'ownership' => 'Served as the primary technical owner of the reporting initiatives, responsible for technical planning, architecture, implementation, and delivery.',
        'dashboard' => ['Built the Executive Insights Dashboard, consolidating departmental reporting into one interactive view.', 5],
        ...filler(8),
    ];
    $first = matched($findings, $facts);
    $second = matched($findings, $facts);

    expect($second)->toEqual($first);

    foreach ($first as $score) {
        // Nothing here is about this posting: no fact reads as strongly relevant on shared words alone.
        expect($score)->toBeGreaterThan(0.0)->toBeLessThan(0.5);
    }
});

it('recognizes the capabilities a posting can ask for, and only those', function () {
    $coverage = app(CapabilityCoverage::class);
    $has = fn (string $text, string $capability) => in_array($capability, $coverage->capabilitiesIn($text), true);

    expect($has('Served as the primary technical owner of the reporting platform.', 'end_to_end_ownership'))->toBeTrue()
        ->and($has('Managed multiple client projects from requirements gathering and solution design through development, launch, and ongoing support.', 'end_to_end_ownership'))->toBeTrue()
        ->and($has('Own the delivery of projects from requirements through launch.', 'end_to_end_ownership'))->toBeTrue()
        ->and($has('Partner with business stakeholders and executives on priorities.', 'stakeholder_collaboration'))->toBeTrue()
        ->and($has('Worked with finance and marketing leadership on reporting needs.', 'stakeholder_collaboration'))->toBeTrue()
        ->and($has('Translate business objectives into technical requirements.', 'requirements_translation'))->toBeTrue()
        ->and($has('Gather requirements from product and support teams.', 'requirements_translation'))->toBeTrue()
        // Using requirements, working with engineers, or one word alone is not any of these.
        ->and($has('Create test matrices derived from product requirements.', 'requirements_translation'))->toBeFalse()
        ->and($has('Collaborate with engineering and operations teams to run closed-course testing programs.', 'stakeholder_collaboration'))->toBeFalse()
        ->and($has('Work with engineers to determine testing strategy and report failures to management.', 'stakeholder_collaboration'))->toBeFalse()
        ->and($has('Execute test cases aligned to release milestones.', 'end_to_end_ownership'))->toBeFalse()
        ->and($has('Managed the on-call rotation and the release calendar.', 'end_to_end_ownership'))->toBeFalse()
        ->and($has('Owner of the Terraform modules repository.', 'end_to_end_ownership'))->toBeFalse();
});

it('reads the "owner" noun and distant stakeholders as the responsibilities they state', function () {
    expect(StoryDimensions::responsibilities('Served as the primary technical owner of Nexus, Budget Hub, and Executive Insights, responsible for technical planning and delivery.'))->toContain('ownership')
        ->and(StoryDimensions::responsibilities('Partnered with marketing, finance, engineering, executive leadership, and external vendors to define reporting requirements, plan technical solutions, and demonstrate reporting systems to internal business stakeholders and leadership.'))->toContain('client')
        // A build is still a build, and a result states no responsibility.
        ->and(StoryDimensions::responsibilities('Built the reporting platform.'))->toBe(['build'])
        ->and(StoryDimensions::responsibilities('Increased lead generation by 201% without additional ad spend.'))->toBe([]);
});

it('ranks a primary-ownership statement below a concrete platform build unless the posting asks for ownership', function () {
    $facts = [
        'ownership' => 'Served as the primary technical owner of Nexus, Marketing Budget & Forecast Hub, and Executive Insights, responsible for technical planning, architecture, implementation, and delivery of these reporting and analytics initiatives.',
        'hub' => ['Built the Marketing Budget & Forecast Hub, a centralized, multi-user forecasting platform with approval workflows, replacing fragile shared-spreadsheet budget planning.', 7],
        ...filler(8),
    ];
    $quiet = matched([
        ['responsibility', 'Support development work on the planning stack team or the embedded safety MCU team.'],
        ['responsibility', 'Generate documentation and back-end tooling to improve operational efficiency.'],
        ['required_qualification', 'Experience debugging Linux system behavior and writing Bash scripts.'],
    ], $facts);
    $asks = matched([
        ['responsibility', 'Serve as the technical owner of reporting initiatives, responsible for technical planning, architecture, and delivery.'],
        ['required_qualification', 'Experience building internal reporting and analytics platforms.'],
    ], $facts, 'Senior Data Engineer');

    // No ownership asked: the platform build is not displaced by a statement that shares planning and delivery words with the posting.
    expect($quiet['hub'] ?? 0.0)->toBeGreaterThanOrEqual($quiet['ownership'] ?? 0.0)
        // Ownership asked: the statement that says exactly that is credited for it.
        ->and($asks['ownership'])->toBeGreaterThan($quiet['ownership'] ?? 0.0)->and($asks['ownership'])->toBeGreaterThanOrEqual(0.4);
});

it('reads longer requirements translation, business priorities, direct customer communication and client relationships', function () {
    $coverage = app(CapabilityCoverage::class);
    $has = fn (string $text, string $capability) => in_array($capability, $coverage->capabilitiesIn($text), true);

    $recognized = array_values(array_filter([
        ['Translate ambiguous product opportunities into clear technical requirements, architecture, and executable plans.', 'requirements_translation'],
        ['Partner with engineering users and platform teams to translate business priorities into deployable solutions.', 'requirements_translation'],
        ['Develop and maintain features while communicating directly with enterprise customers.', 'customer_facing'],
        ['Excellent written and oral communication skills for direct customer interaction.', 'customer_facing'],
        ['Balanced hands-on development with project planning, client relationships, and delivery responsibilities.', 'stakeholder_collaboration'],
        ['Built trust through strong stakeholder relationships across finance and operations.', 'stakeholder_collaboration'],
    ], fn (array $case) => ! $has($case[0], $case[1])));

    $wronglyRecognized = array_values(array_filter([
        // Using requirements, talking to a system, or a relationship that is not with a client or customer is none of these.
        ['Create test matrices derived from product requirements to test autonomy software.', 'requirements_translation'],
        ['Convert the legacy export to Parquet and then load it into the warehouse tables on a nightly schedule.', 'requirements_translation'],
        ['Communicated directly with the build system about failures.', 'customer_facing'],
        ['Maintained relationships between the orders and customers tables in the schema.', 'stakeholder_collaboration'],
        ['Collaborate with engineering and operations teams to run closed-course testing programs.', 'stakeholder_collaboration'],
    ], fn (array $case) => $has($case[0], $case[1])));

    expect($recognized)->toBe([])->and($wronglyRecognized)->toBe([]);
});

it('credits a general statement of client relationships when a posting asks for customer communication', function () {
    $fact = 'Balanced hands-on development with project planning, client relationships, and delivery responsibilities as an independent founder and developer.';
    $filler = filler(8);
    $asks = matched([['responsibility', 'Communicate directly with enterprise customers and maintain strong client relationships.']], ['general' => $fact, ...$filler]);
    $quiet = matched([['responsibility', 'Build and maintain internal tooling to improve operational efficiency.']], ['general' => $fact, ...$filler]);

    expect($asks['general'] ?? 0.0)->toBeGreaterThan(($quiet['general'] ?? 0.0) + 0.3);
});

it('keeps weak wording weak: a posting naming no capability gives no fact a high score on words alone', function () {
    $scores = matched(
        [['responsibility', 'Create V&V plans and develop test matrices derived from product requirements to test autonomy software on-vehicle.'], ['responsibility', 'Support development work on the planning stack team.'], ['responsibility', 'Generate documentation and back-end tooling to improve operational efficiency.']],
        [
            'app' => ['Built a full-stack TypeScript application with typed interfaces and automated tests, then supported its rollout across the analytics group.', 3],
            'ownership' => 'Served as the primary technical owner of the reporting initiatives, responsible for technical planning, architecture, implementation, and delivery.',
            ...filler(10),
        ],
        'Test Engineer',
    );

    foreach ($scores as $score) {
        expect($score)->toBeLessThan(0.5);
    }
});

it('keeps a resume full for a posting that names almost nothing the record states', function () {
    // The allocation thresholds are set for honest scores: a weak posting still leaves enough above `filler_relevance` for a full page.
    expect((float) config('resume.composition.allocation.filler_relevance'))->toBeLessThanOrEqual(0.2)
        ->and((float) config('resume.composition.allocation.relevant_at'))->toBeLessThanOrEqual(0.3)
        ->and((float) config('resume.composition.matching.scale'))->toBe(1.0);
});
