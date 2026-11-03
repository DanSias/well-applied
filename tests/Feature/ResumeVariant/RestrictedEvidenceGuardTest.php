<?php

use App\Support\ResumeVariant\RestrictedEvidenceGuard;

const GUARD_TERMS = ['merchant', 'UDF1', 'Brute Strength'];

function guardFacts(): array
{
    return [
        'restricted-fact' => 'Corrected approximately 32,000+ transaction records for a RocketGate merchant. 63% fewer errors.',
        'public-fact' => 'Built resumable multi-stage processing with 63% fewer errors.',
    ];
}

it('does not inspect text that cites no Restricted fact', function () {
    $violations = (new RestrictedEvidenceGuard)->violations(
        'Onboarded new merchants and corrected 32,000 records at api.example.com',
        ['public-fact'], guardFacts(), ['restricted-fact'], GUARD_TERMS,
    );

    expect($violations)->toBe([]);
});

it('rejects a configured confidential term in text citing a Restricted fact, case-insensitively and on word boundaries', function () {
    $guard = new RestrictedEvidenceGuard;

    expect($guard->violations('Corrected data for a Merchant account.', ['restricted-fact'], guardFacts(), ['restricted-fact'], GUARD_TERMS))
        ->toContain('mentions the confidential term [merchant]')
        ->and($guard->violations('Built merchandising tools.', ['restricted-fact'], guardFacts(), ['restricted-fact'], GUARD_TERMS))
        ->toBe([]);
});

it('allows a confidential term that a cited non-Restricted fact itself states', function () {
    $facts = guardFacts() + ['onboarding-fact' => 'Served as technical point of contact for new merchants.'];

    $violations = (new RestrictedEvidenceGuard)->violations(
        'Supported new merchants through onboarding and built safe correction tooling.',
        ['restricted-fact', 'onboarding-fact'], $facts, ['restricted-fact'], GUARD_TERMS,
    );

    expect($violations)->toBe([]);
});

it('rejects a hostname in text citing a Restricted fact', function () {
    $violations = (new RestrictedEvidenceGuard)->violations(
        'Wrote safe tooling against gateway.rocketgate.com live.',
        ['restricted-fact'], guardFacts(), ['restricted-fact'], GUARD_TERMS,
    );

    expect($violations)->toHaveCount(1)->and($violations[0])->toContain('hostname');
});

it('rejects a figure that appears only in Restricted evidence, in any numeric formatting', function () {
    $guard = new RestrictedEvidenceGuard;

    expect($guard->violations('Corrected 32,000 records with idempotent writes.', ['restricted-fact'], guardFacts(), ['restricted-fact'], GUARD_TERMS))
        ->toContain('states the figure [32000], which appears only in Restricted evidence')
        ->and($guard->violations('Corrected 32000+ records.', ['restricted-fact'], guardFacts(), ['restricted-fact'], GUARD_TERMS))
        ->not->toBe([]);
});

it('allows a figure that a cited non-Restricted fact also states', function () {
    $violations = (new RestrictedEvidenceGuard)->violations(
        'Cut errors by 63% with resumable multi-stage processing.',
        ['restricted-fact', 'public-fact'], guardFacts(), ['restricted-fact'], GUARD_TERMS,
    );

    expect($violations)->toBe([]);
});

it('accepts a faithful generalized claim', function () {
    $violations = (new RestrictedEvidenceGuard)->violations(
        'Built safe, reviewable data-correction tooling with idempotent writes and post-write verification.',
        ['restricted-fact'], guardFacts(), ['restricted-fact'], GUARD_TERMS,
    );

    expect($violations)->toBe([]);
});

it('ships a reviewable default policy: client and field names are confidential, generic industry terms are review-only', function () {
    $confidential = array_map('strtolower', config('resume.restricted_terms'));
    $generic = array_map('strtolower', config('resume.generic_terms'));

    expect($confidential)->toContain('brute strength')->toContain('udf1')->not->toContain('merchant')
        ->and($generic)->toContain('merchant')->toContain('donor')
        ->and(array_intersect($confidential, $generic))->toBe([]);
});

it('reports a generic industry term as a review finding and a confidential term or restricted-only figure as a block', function () {
    $facts = ['public' => 'Built the importer.', 'secret' => 'Updated 32,000 merchant accounts for Brute Strength.'];
    $findings = (new RestrictedEvidenceGuard)->findings(
        'Updated 32,000 merchant accounts for Brute Strength.', ['public', 'secret'], $facts, ['secret'], ['Brute Strength'], ['merchant'],
    );
    $bySeverity = fn (string $severity) => collect($findings)->where('severity', $severity)->pluck('kind')->sort()->values()->all();

    expect($bySeverity('block'))->toBe(['confidential_term', 'restricted_figure'])
        ->and($bySeverity('review'))->toBe(['generic_term']);

    // violations() keeps returning only the blocking ones.
    expect((new RestrictedEvidenceGuard)->violations('Updated merchant accounts.', ['secret'], $facts, ['secret'], ['Brute Strength']))->toBe([]);
});

it('does not flag a generic term that a cited Public fact itself states', function () {
    $facts = ['public' => 'Supported merchant onboarding.', 'secret' => 'Fixed merchant records.'];

    expect((new RestrictedEvidenceGuard)->findings('Supported merchant onboarding.', ['public', 'secret'], $facts, ['secret'], [], ['merchant']))->toBe([]);
});
