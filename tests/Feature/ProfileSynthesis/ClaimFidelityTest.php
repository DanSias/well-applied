<?php

use App\Support\ResumeVariant\ClaimFidelityChecker;

/**
 * The material-claim check, on the exact bullets variant 8 produced and on ordinary persuasive wording that must
 * pass untouched. Evidence is the combined text of the facts a bullet cites.
 */
function fidelity(string $text, array $evidence): array
{
    $checker = new ClaimFidelityChecker;
    $facts = array_combine(array_map(fn ($i) => "f{$i}", array_keys($evidence)), $evidence);
    $found = $checker->findings($text, array_keys($facts), $facts);

    return [array_column($found, 'kind'), array_map(fn (array $f) => $checker->repair($text, $f), $found)];
}

it('flags breadth the evidence never states and removes it', function () {
    [$kinds, $repairs] = fidelity('Delivered a 37% increase in post-purchase conversion rates across managed client engagements.', ['Delivered a 37% increase in post-purchase conversion rates.']);

    expect($kinds)->toBe(['scope_expansion'])->and($repairs[0])->toBe('Delivered a 37% increase in post-purchase conversion rates.');
});

it('accepts breadth the evidence does state', function () {
    [$kinds] = fidelity('Saved approximately 20+ hours per week across teams.', ['Nexus saved approximately 20+ hours per week across teams by replacing spreadsheet-driven workflows.']);
    [$stack] = fidelity('Built the platform across the stack, from the API layer to the dashboards.', ['Built the platform: API layer, dashboards, and data pipeline.']);

    expect($kinds)->toBe([])->and($stack)->toBe([]);
});

it('flags a cause the evidence does not connect to the result, and leaves ordinary causal wording alone', function () {
    [$kinds, $repairs] = fidelity(
        'Reduced average new-merchant onboarding effort from approximately 10 active hours to approximately 3 through improved documentation and knowledge-base content.',
        ['Reduced average new-merchant onboarding effort from approximately 10 active hours to approximately 3 active hours.'],
    );

    expect($kinds)->toBe(['unsupported_cause'])->and($repairs[0])->toBe('Reduced average new-merchant onboarding effort from approximately 10 active hours to approximately 3.');

    // The cause IS in the evidence, so "by replacing" and "through" are ordinary synthesis.
    [$ok] = fidelity('Cut reporting time by replacing spreadsheet-driven workflows with scalable web applications.', ['Nexus reduced reporting time by 85%.', 'Nexus replaced spreadsheet-driven workflows with scalable web applications.']);
    [$ok2] = fidelity('Owned the platform from requirements through architecture, implementation and delivery.', ['Independently owned the platform from business requirements through architecture, implementation, testing and delivery.']);
    [$measure] = fidelity('Cut reporting time by 85%.', ['Nexus reduced reporting time by 85%.']);

    expect($ok)->toBe([])->and($ok2)->toBe([])->and($measure)->toBe([]);
});

it('flags a stronger role than the evidence states and drops or swaps only the unsupported verb', function () {
    [$kinds, $repairs] = fidelity('Designed and implemented the frontend experience alongside the application, shaping the UI.', ['Owned the implementation of the frontend experience alongside the underlying application functionality, rather than working from pre-existing UI specifications.']);
    [$drove, $driveFix] = fidelity('Drove 201.2% growth in lead generation without increasing advertising spend.', ['Delivered 201.2% growth in lead generation without increasing advertising spend.']);

    expect($kinds)->toBe(['ownership_upgrade'])->and($repairs[0])->toStartWith('Implemented the frontend experience')
        ->and($drove)->toBe(['ownership_upgrade'])->and($driveFix[0])->toBe('Delivered 201.2% growth in lead generation without increasing advertising spend.');

    // The evidence uses the verb, so it is not an upgrade; and a verb inside a noun phrase is not an authority claim.
    [$designed] = fidelity('Designed and evolved the architecture, defining component boundaries.', ['Designed and evolved application architectures, defining component boundaries.']);
    [$noun] = fidelity('Delivered a 37% increase across managed accounts.', ['Delivered a 37% increase in conversion across managed accounts.']);

    expect($designed)->toBe([])->and($noun)->toBe([]);
});

it('keeps a figure exactly as stated, qualifier and sign included', function () {
    [$kinds, $repairs] = fidelity('Cut reporting time by 85% and saved 20+ hours per week.', ['Nexus reduced reporting time by 85%.', 'Nexus saved approximately 20+ hours per week across teams.']);
    [$added, $addedFix] = fidelity('Reduced onboarding from over 10 hours to 3 hours.', ['Reduced onboarding effort from 10 active hours to 3 active hours.']);
    [$invented] = fidelity('Cut costs by 40%.', ['Nexus reduced reporting time by 85%.']);
    [$range] = fidelity('Saved approximately 10–15 hours per month.', ['Saved approximately 10–15 hours per month for internal teams.']);

    expect($kinds)->toBe(['figure_precision'])->and($repairs[0])->toContain('approximately 20+ hours')
        ->and($added)->toBe(['figure_precision'])->and($addedFix[0])->not->toContain('over 10')
        ->and($invented)->toBe(['figure_precision'])->and($range)->toBe([]);
});

it('treats a figure also repeated bare as metric metadata, not as a conflicting statement', function () {
    [$kinds, $repairs] = fidelity('Saved 20+ hours per week.', ['Nexus saved approximately 20+ hours per week across teams.', '20']);

    expect($kinds)->toBe(['figure_precision'])->and($repairs[0])->toContain('approximately 20+');
});
