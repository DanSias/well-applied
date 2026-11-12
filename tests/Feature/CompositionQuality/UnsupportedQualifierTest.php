<?php

use App\Contracts\GeneratesResumeWording;
use App\Support\ResumeVariant\ClaimFidelityChecker;
use App\Support\ResumeVariant\GenerateResumeVariant;
use Tests\Support\CompositionScenario;

/**
 * The two claims the live test let through: "a custom frontend" for "the frontend experience", and "through testing to
 * production" for "testing and delivery". The checker finds a qualifier or a delivery stage the evidence does not state,
 * repairs by removing it, and leaves ordinary paraphrase alone.
 */
function uqFindings(string $text, string $evidence): array
{
    return (new ClaimFidelityChecker)->findings($text, ['e'], ['e' => $evidence]);
}

function uqKinds(array $findings): array
{
    return array_values(array_unique(array_column($findings, 'kind')));
}

function uqRepaired(string $text, string $evidence, string $kind): ?string
{
    $checker = new ClaimFidelityChecker;
    $finding = collect($checker->findings($text, ['e'], ['e' => $evidence]))->firstWhere('kind', $kind);

    return $finding === null ? null : $checker->repair($text, $finding);
}

const OWNED_FRONTEND = 'Owned the implementation of the frontend experience alongside the underlying application functionality, rather than working from pre-existing UI specifications.';
const OWNED_DELIVERY = 'Independently owned Flow from initial business requirements through architecture, implementation, testing and delivery. Owned Flow across product design, UX, architecture, backend, frontend, analytics, and AI integration.';

it('flags a qualifier the evidence does not state, and repairs by removing it', function () {
    $text = 'Developed Toolkit in TypeScript with a custom frontend, then deployed it to the Analytics team.';

    expect(uqKinds(uqFindings($text, OWNED_FRONTEND)))->toBe(['unsupported_qualifier'])
        ->and(uqFindings($text, OWNED_FRONTEND)[0]['detail'])->toBe('custom')
        ->and(uqRepaired($text, OWNED_FRONTEND, 'unsupported_qualifier'))->toBe('Developed Toolkit in TypeScript with a frontend, then deployed it to the Analytics team.');
});

it('flags a delivery stage beyond what the evidence states, even when a word shares a stem with the evidence', function () {
    // "product design" in the evidence shares its first letters with "production"; it does not state production.
    $text = 'Independently owned Flow across product design, UX, backend and frontend, delivering the full scope from requirements through testing to production.';
    $findings = uqFindings($text, OWNED_DELIVERY);

    expect(uqKinds($findings))->toBe(['lifecycle_upgrade'])->and($findings[0]['severity'])->toBe('block')->and($findings[0]['detail'])->toBe('to production')
        ->and(uqRepaired($text, OWNED_DELIVERY, 'lifecycle_upgrade'))->toBe('Independently owned Flow across product design, UX, backend and frontend, delivering the full scope from requirements through testing.');
});

it('flags a later stage with no clean repair for a person to look at, instead of removing it', function () {
    $launched = uqFindings('Built Flow and launched it for the support team.', 'Built Flow and delivered it to the support team.');
    $maintained = uqFindings('Built Flow and maintained it for the support team.', 'Built Flow and delivered it to the support team.');

    expect($launched[0])->toMatchArray(['kind' => 'lifecycle_upgrade', 'severity' => 'review'])->not->toHaveKey('repair')
        ->and($maintained[0])->toMatchArray(['kind' => 'lifecycle_upgrade', 'severity' => 'review']);
});

it('accepts a qualifier or stage the evidence itself uses, or an equivalent of it', function (string $text, string $evidence) {
    expect(uqFindings($text, $evidence))->toBe([]);
})->with([
    'qualifier stated' => ['Built a custom reporting layer for finance.', 'Built a custom reporting layer on top of the warehouse.'],
    'equivalent qualifier' => ['Built a custom reporting layer for finance.', 'Built a bespoke reporting layer on top of the warehouse.'],
    'hyphen or space' => ['Built real-time dashboards for support.', 'Built real time dashboards for support.'],
    'scalable stated' => ['Built scalable full-stack applications for finance.', 'Partnered with finance to translate business requirements into scalable full-stack applications.'],
    'production stated' => ['Used Datadog to investigate issues in a production payment-processing environment.', 'Used Datadog at Acme to investigate application issues, supporting diagnostics in a production payment-processing environment.'],
    'deployed for delivered' => ['Built Toolkit and deployed it to the Analytics team.', 'Toolkit was delivered and deployed to the Analytics team.'],
]);

it('leaves ordinary, defensible paraphrase alone', function (string $text, string $evidence) {
    expect(uqFindings($text, $evidence))->toBe([]);
})->with([
    'frontend implementation' => ['Implemented the frontend alongside the application functionality.', OWNED_FRONTEND],
    'delivered as shipped' => ['Delivered the full scope from business requirements through testing and delivery.', OWNED_DELIVERY],
    'automated tests' => ['Wrote TypeScript with typed interfaces and automated tests.', 'Developed TypeScript-based applications with typed interfaces and automated tests across full-stack workflows.'],
    'product wording' => ['Worked on product design and UX for the platform.', OWNED_DELIVERY],
    'plain verbs' => ['Built and integrated the Jira and GitLab APIs behind one backend client.', 'Integrated the Jira and GitLab APIs behind one backend client that feeds every view.'],
]);

it('keeps the sentence grammatical when it removes a qualifier', function () {
    $checker = new ClaimFidelityChecker;
    $fix = fn (string $text) => $checker->repair($text, collect($checker->findings($text, ['e'], ['e' => 'Built reporting for finance.']))->firstWhere('kind', 'unsupported_qualifier'));

    expect($fix('Built a sophisticated API layer for finance.'))->toBe('Built an API layer for finance.')
        ->and($fix('Built an enterprise-grade reporting service for finance.'))->toBe('Built a reporting service for finance.')
        ->and($fix('Built robust, reporting dashboards for finance.'))->toBe('Built reporting dashboards for finance.');
});

it('repairs an unsupported qualifier in a generated resume instead of omitting the bullet, and says so in the report', function () {
    $s = CompositionScenario::create();
    $synthesis = CompositionScenario::approvedProfile($s);
    $seen = new stdClass;
    // The Beacon fact says "a React and TypeScript platform"; the model calls it custom.
    $seen->bullet = fn (array $group) => in_array('b-what', array_column($group['career_facts'], 'key'), true)
        ? 'Built Beacon, a custom React and TypeScript platform that gives teams visibility into work items and delivery progress.'
        : null;
    app()->instance(GeneratesResumeWording::class, CompositionScenario::wording($seen));

    $variant = app(GenerateResumeVariant::class)->generateForJob($s['analysis'], $synthesis);
    $variant->load('experienceRoles.bullets');
    $text = $variant->experienceRoles->flatMap(fn ($r) => $r->bullets->pluck('text'))->first(fn ($t) => str_contains($t, 'Beacon'));
    $item = collect($variant->review_report['items'])->first(fn ($i) => str_contains((string) ($i['text'] ?? ''), 'Beacon'));

    expect($text)->toBe('Built Beacon, a React and TypeScript platform that gives teams visibility into work items and delivery progress.')
        ->and($item['status'])->toBe('generalized')->and($item['action'])->toContain('emoved the qualifier [custom]');
});
