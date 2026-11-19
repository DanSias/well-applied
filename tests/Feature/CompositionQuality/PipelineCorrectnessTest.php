<?php

use App\Support\JobTailoring\JobRelevance;
use App\Support\ResumeProfile\CapabilityCoverage;
use App\Support\ResumeVariant\ClaimFidelityChecker;
use App\Support\ResumeVariant\CompositionChecks;

/**
 * Four isolated correctness fixes found by running a real resume (variant 35) through the pipeline. Statements are the actual
 * approved evidence and the wording a model actually wrote; no model is called.
 */
const BUDGET_HUB = 'Built the Marketing Budget & Forecast Hub (referred to in the resume as the Marketing Forecasting Platform), a centralized, multi-user forecasting platform with approval workflows, replacing fragile shared-spreadsheet budget planning.';
const BUDGET_HOURS = 'Saved approximately 10–15 hours per month for internal teams by replacing manual spreadsheet-based budget planning with the Marketing Budget & Forecast Hub.';

function budgetFindings(string $text): array
{
    return (new ClaimFidelityChecker)->findings($text, ['hub', 'hours'], ['hub' => BUDGET_HUB, 'hours' => BUDGET_HOURS]);
}

// ---- 1. Numeric range equivalence ----------------------------------------------------------------------------------

it('accepts the Budget Hub wording the model wrote, which the figure check rejected as "approximately 10" and "15"', function () {
    $text = 'Built a centralized multi-user budget forecasting platform with approval workflows to replace shared-spreadsheet planning, saving internal teams approximately 10 to 15 hours per month.';

    expect(array_column(budgetFindings($text), 'kind'))->not->toContain('figure_precision');
});

it('treats "10–15", "10-15" and "10 to 15" as one range, with or without a qualifier the evidence uses', function () {
    $checker = new ClaimFidelityChecker;
    $flagged = fn (string $evidence, string $text) => in_array('figure_precision', array_column($checker->findings($text, ['e'], ['e' => $evidence]), 'kind'), true);

    expect($flagged('Saved 10–15 hours per month.', 'Saved 10 to 15 hours per month.'))->toBeFalse()
        ->and($flagged('Saved 10-15 hours per month.', 'Saved 10–15 hours per month.'))->toBeFalse()
        ->and($flagged('Saved 10 to 15 hours per month.', 'Saved 10–15 hours per month.'))->toBeFalse()
        ->and($flagged('Saved approximately 10–15 hours per month.', 'Saved approximately 10-15 hours per month.'))->toBeFalse()
        ->and($flagged('Saved approximately 10–15 hours per month.', 'Saved approximately 10 to 15 hours per month.'))->toBeFalse()
        // The two ends stated separately in the evidence are the same range.
        ->and($flagged('Saved approximately 10 hours per month in one phase and approximately 15 in another.', 'Saved approximately 10 to 15 hours per month.'))->toBeFalse();
});

it('still rejects a different number, magnitude or qualifier, and keeps "from 10 to 3" as two figures', function () {
    $checker = new ClaimFidelityChecker;
    $flagged = fn (string $text, string $evidence = BUDGET_HOURS) => in_array('figure_precision', array_column($checker->findings($text, ['e'], ['e' => $evidence]), 'kind'), true);

    expect($flagged('Saved internal teams approximately 10 to 20 hours per month.'))->toBeTrue()
        ->and($flagged('Saved internal teams approximately 11 to 15 hours per month.'))->toBeTrue()
        ->and($flagged('Saved internal teams approximately 100 to 150 hours per month.'))->toBeTrue()
        ->and($flagged('Saved internal teams approximately 10 hours per month.'))->toBeTrue()
        // A qualifier the evidence does not use, or drops, is still a precision change.
        ->and($flagged('Saved internal teams over 10 to 15 hours per month.'))->toBeTrue()
        ->and($flagged('Saved internal teams 10 to 15 hours per month.'))->toBeTrue()
        // A fall written "from 100 to 30" is two figures, not a range, as it was before.
        ->and($flagged('Cut onboarding from approximately 100 to approximately 40 active hours.', 'Cut onboarding from approximately 100 active hours to approximately 30.'))->toBeTrue()
        ->and($flagged('Cut onboarding from approximately 100 to approximately 30 active hours.', 'Cut onboarding from approximately 100 active hours to approximately 30.'))->toBeFalse();
});

// ---- 2. Duplicate-result detection ---------------------------------------------------------------------------------

const EXPORTER = 'Developed a provider-abstracted, read-only knowledge pipeline that converts Freshdesk and Confluence content to deterministic Markdown using SHA-256 comparison, so repeat runs create, update, or skip files and produce an auditable change report.';
const TOOLKIT = 'Designed a local-first transaction investigation tool with AES-256-GCM credential encryption, scrypt-derived keys held only in process memory, and deterministic reports layered with optional AI explanation over PII-excluding pre-aggregated metrics.';

function resultDuplicates(string $first, string $second): array
{
    $facts = ['a' => ['attribution' => ['project_id' => null]], 'b' => ['attribution' => ['project_id' => null]]];

    return array_values(array_filter(
        app(CompositionChecks::class)->duplicates([
            ['role_id' => 1, 'index' => 2, 'text' => $first, 'fact_keys' => ['a']],
            ['role_id' => 1, 'index' => 4, 'text' => $second, 'fact_keys' => ['b']],
        ], $facts),
        fn (array $f) => ($f['reason'] ?? '') === 'repeated_result',
    ));
}

it('does not read SHA-256 and AES-256 as two bullets claiming the same result', function () {
    expect(resultDuplicates(EXPORTER, TOOLKIT))->toBe([]);
});

it('does not read other technical identifiers as results', function () {
    expect(resultDuplicates('Encrypted exports with 256-bit keys on x86 hosts.', 'Signed releases with RSA-256 and ES2015 bundles.'))->toBe([]);
});

it('still flags two bullets that claim the same measured result', function () {
    $findings = resultDuplicates('Built Nimbus, unifying BigQuery and Salesforce data and reducing reporting time by 85%.', 'Moved the finance exports onto dashboards, cutting reporting time by 85% for the budgeting group.');

    expect($findings)->toHaveCount(1)->and($findings[0]['message'])->toContain('85%');
});

it('still counts figures written with a comma, a decimal or a currency sign', function () {
    $findings = resultDuplicates('Grew qualified leads by 201.2% without added ad spend.', 'Landing-page work lifted lead volume 201.2% over the prior year.');

    expect($findings)->toHaveCount(1)->and($findings[0]['message'])->toContain('201.2%');
});

// ---- 3. marketing_only ----------------------------------------------------------------------------------------------

function marketingAdjustments(string $statement): array
{
    $fact = ['statement' => $statement, 'visibility' => 'public', 'capabilities' => [], 'role_id' => 1, 'project_id' => null, 'support_only' => false];

    // specificity() judges a statement for an engineering job (second argument), which is the case that discounts marketing.
    return (new ReflectionMethod(JobRelevance::class, 'specificity'))->invoke(app(JobRelevance::class), $fact, true)[1];
}

it('does not call a stakeholder-partnership fact marketing-only because marketing is one of the partners', function () {
    $adjustments = marketingAdjustments('Partnered with marketing, finance, and engineering stakeholders to translate business requirements into scalable full-stack applications supporting analytics, forecasting, and operational reporting.');

    expect($adjustments)->not->toContain('marketing_only');
});

it('still treats statements about marketing itself as marketing-only for an engineering job', function () {
    $missed = array_values(array_filter([
        'Increased lead generation by 201.2% without additional advertising spend by developing a direct-response landing page.',
        'Combined technical SEO and content strategy work across 34 university programs to improve organic visibility.',
        'Ran A/B testing and conversion-funnel optimization for the marketing campaigns of paid-acquisition clients.',
        'Partnered with marketing to plan campaign attribution and keyword strategy.',
    ], fn (string $statement) => ! in_array('marketing_only', marketingAdjustments($statement), true)));

    expect($missed)->toBe([]);
});

// ---- 4. customer_facing capability ---------------------------------------------------------------------------------

it('recognizes working directly with clients in the present, continuous and past tense', function () {
    $coverage = app(CapabilityCoverage::class);

    $missed = [];

    foreach ([
        'Works directly with clients to scope integrations.',
        'Working directly with customers to resolve integration issues.',
        'Worked directly with clients and stakeholders to translate business objectives into technical requirements.',
        'Work directly with merchants on onboarding.',
    ] as $statement) {
        if (! in_array('customer_facing', $coverage->capabilitiesIn($statement), true)) {
            $missed[] = $statement;
        }
    }

    expect($missed)->toBe([]);
});

it('does not read unrelated statements as customer-facing work', function () {
    $coverage = app(CapabilityCoverage::class);
    $wrong = [];

    foreach ([
        'Worked directly on the billing service migration.',
        'Worked with clients on landing pages.',
        'Worked directly with the platform team on deployment tooling.',
        'Managed multiple client projects from requirements through launch.',
    ] as $statement) {
        if (in_array('customer_facing', $coverage->capabilitiesIn($statement), true)) {
            $wrong[] = $statement;
        }
    }

    expect($wrong)->toBe([]);
});
