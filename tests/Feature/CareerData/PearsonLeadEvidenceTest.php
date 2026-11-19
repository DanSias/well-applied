<?php

use App\Support\ResumeVariant\ClaimFidelityChecker;

/**
 * The Pearson Data & Analytics Lead evidence added on 2026-11-18, read from the canonical dataset itself: exact wording,
 * provenance kept, and the limits the user set (primary ownership is not exclusive ownership or people management, and the
 * Salesforce migration stays a collaboration). No database and no model.
 */
function pearsonFact(string $key): array
{
    $facts = json_decode((string) file_get_contents(base_path('data/canonical-career-data.proposed.json')), true, flags: JSON_THROW_ON_ERROR)['career_facts'];

    return collect($facts)->firstWhere('key', $key) ?? throw new RuntimeException("Missing {$key}");
}

const PEARSON_OWNERSHIP = 'pearson-lead-technical-project-ownership';
const PEARSON_STAKEHOLDERS = 'pearson-data-analytics-lead-stakeholder-partnership';
const PEARSON_INSIGHTS = 'pearson-executive-insights-what-it-is';
const PEARSON_AUDIENCE = 'pearson-executive-insights-audience-purpose';

it('states the four approved facts, all verified and public', function () {
    $expected = [
        PEARSON_OWNERSHIP => 'Served as the primary technical owner of Nexus, Marketing Budget & Forecast Hub, and Executive Insights, responsible for technical planning, architecture, implementation, and delivery of these reporting and analytics initiatives.',
        PEARSON_STAKEHOLDERS => 'Partnered with marketing, finance, engineering, executive leadership, and external vendors to define reporting requirements, plan technical solutions, and demonstrate reporting systems to internal business stakeholders and leadership.',
        PEARSON_INSIGHTS => 'Developed an interactive, real-time Executive Insights Dashboard that consolidated departmental reporting, replacing separate spreadsheets and manual reporting processes with a shared, automated view of business performance.',
        PEARSON_AUDIENCE => 'Delivered the Executive Insights Dashboard to the executive leadership team and marketing directors, providing visibility into key performance indicators and sales funnel progression across departments.',
    ];

    foreach ($expected as $key => $statement) {
        $fact = pearsonFact($key);

        expect($fact['statement'])->toBe($statement)
            ->and($fact['verification'])->toBe('verified')
            ->and($fact['visibility'])->toBe('public')
            ->and(collect($fact['evidence'])->contains('source', 'user_confirmed'))->toBeTrue();
    }
});

it('keeps the original provenance of the two enriched facts and adds the confirmation beside it', function () {
    $stakeholders = pearsonFact(PEARSON_STAKEHOLDERS);
    $insights = pearsonFact(PEARSON_INSIGHTS);

    expect(array_column($stakeholders['evidence'], 'source'))->toBe(['resume', 'user_confirmed'])
        ->and($stakeholders['evidence'][0]['quoted_text'])->toContain('Partnered with marketing, finance, and engineering stakeholders')
        ->and(array_column($insights['evidence'], 'source'))->toBe(['portfolio', 'user_confirmed'])
        ->and($insights['evidence'][0]['locator'])->toBe('Executive Insights Dashboard entry')
        // Skills and the project they belong to are untouched.
        ->and($insights['skills'])->toBe(['react', 'node-js', 'bigquery', 'postgresql', 'google-cloud-functions', 'analytics-systems'])
        ->and($insights['attributable'])->toBe(['type' => 'project', 'key' => 'executive-insights-dashboard']);
});

it('attributes the two new facts to the Pearson lead role and the Executive Insights project', function () {
    expect(pearsonFact(PEARSON_OWNERSHIP)['attributable'])->toBe(['type' => 'role', 'key' => 'pearson-data-analytics-lead'])
        ->and(pearsonFact(PEARSON_AUDIENCE)['attributable'])->toBe(['type' => 'project', 'key' => 'executive-insights-dashboard']);
});

it('keeps ownership from implying exclusivity, people management or the Salesforce migration', function () {
    $fact = pearsonFact(PEARSON_OWNERSHIP);

    // The statement can carry no authority a validator could read as people management or the migration.
    expect($fact['statement'])->not->toMatch('/\b(?:led|lead|leading|managed|supervis\w+|directed|team|reports|sole|exclusive\w*|Salesforce|migration)\b/i')
        // The notes record the limits for the next person to touch it.
        ->and($fact['notes'])->toContain('does not mean exclusive ownership')->toContain('no direct reports')->toContain('Salesforce migration')->toContain('collaborative');
});

it('keeps the audience fact qualitative', function () {
    $fact = pearsonFact(PEARSON_AUDIENCE);

    expect($fact['statement'])->not->toMatch('/\d|%|\bboard\b|\bexternal\b|\badoption\b|company-wide/i')
        ->and($fact['notes'])->toContain('no adoption metric')->toContain('board presentation')->toContain('external-client');
});

it('supports the bullets a writer would draw from them, and blocks the claims they do not make', function () {
    $keys = [PEARSON_OWNERSHIP, PEARSON_STAKEHOLDERS, PEARSON_INSIGHTS, PEARSON_AUDIENCE];
    $facts = array_combine($keys, array_map(fn (string $k) => pearsonFact($k)['statement'], $keys));
    $checker = new ClaimFidelityChecker;
    $blocked = fn (string $text, array $cited) => array_column(array_filter($checker->findings($text, $cited, $facts), fn ($f) => $f['severity'] === 'block'), 'kind');

    expect($blocked('Owned the technical planning, architecture, implementation, and delivery of Nexus, Marketing Budget & Forecast Hub, and Executive Insights.', [PEARSON_OWNERSHIP]))->toBe([])
        ->and($blocked('Partnered with marketing, finance, engineering, executive leadership, and external vendors to define reporting requirements and plan technical solutions.', [PEARSON_STAKEHOLDERS]))->toBe([])
        ->and($blocked('Developed an interactive, real-time Executive Insights Dashboard, replacing manual reporting with a shared, automated view.', [PEARSON_INSIGHTS]))->toBe([])
        ->and($blocked('Delivered the Executive Insights Dashboard to the executive leadership team and marketing directors for KPI and sales funnel visibility.', [PEARSON_AUDIENCE]))->toBe([])
        ->and($blocked('Led a team of developers delivering Nexus and Executive Insights.', [PEARSON_OWNERSHIP]))->toContain('team_claim')
        ->and($blocked('Managed the technical delivery of Nexus across the analytics organization.', [PEARSON_OWNERSHIP]))->toContain('ownership_upgrade')
        ->and($blocked('Delivered the Executive Insights Dashboard to the executive leadership team, increasing adoption by 40%.', [PEARSON_AUDIENCE]))->toContain('figure_precision');
});
