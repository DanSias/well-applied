<?php

use App\Support\ResumeVariant\ClaimScopeChecker;
use App\Support\ResumeVariant\RecoverWording;
use App\Support\ResumeVariant\ResumeWordingResponseValidator;
use App\Support\ResumeVariant\WordingCheckContext;

const BOLDIN_BULLET = "Served as technical contact for new-merchant integration, assessing each team's stack, directing them to the appropriate SDK or library, and troubleshooting payment, webhook, and recurring billing flows during onboarding.";

/** The three Boldin facts, each true on its own. */
function boldinFacts(): array
{
    return [
        'webhooks' => 'Worked with merchant integrations involving webhooks for retry schedules, account management, and related functionality.',
        'billing' => 'Helped merchants with both one-time and recurring payment setup.',
        'troubleshot' => 'Tested payment flows and troubleshot merchant integration issues.',
    ];
}

function boldinScopes(): array
{
    return ['webhooks' => ['integrated', 'supported'], 'billing' => ['supported']];
}

function claimFindings(string $text, array $cited, ?array $facts = null, ?array $scopes = null): array
{
    return (new ClaimScopeChecker)->findings($text, $cited, $facts ?? boldinFacts(), $scopes ?? boldinScopes());
}

function scopeContext(array $cited, array $facts, array $scopes): WordingCheckContext
{
    return new WordingCheckContext(
        validRoleIds: [1], expectedBulletGroupIndexesByRole: [1 => [0, 1]], validSelectedProjectIds: [],
        denylistTerms: [], careerFactKeysByLocation: ['1:0' => $cited, '1:1' => ['troubleshot'], 'summary' => []], guardrailByFactKey: [],
        skillIdsByFactKey: [], canonicalSkillsById: [], textRecognizedSkillIdsByFactKey: [], directTargetTermsByLocation: [],
        restrictedFactKeys: [], factTextByKey: $facts, restrictedTerms: [], genericTerms: [],
        declaredScopesByKey: $scopes,
    );
}

it('flags the Boldin composite: each fact is true alone, but "troubleshooting webhook flows" is not established', function () {
    $findings = claimFindings(BOLDIN_BULLET, ['webhooks', 'billing', 'troubleshot']);

    expect($findings)->toHaveCount(2)
        ->and(collect($findings)->pluck('severity')->unique()->all())->toBe(['review'])
        ->and(collect($findings)->pluck('kind')->unique()->all())->toBe(['claim_scope'])
        // The exact claim is named, and the reason points at the two facts that were merged.
        ->and($findings[0]['detail'])->toBe('troubleshooting payment, webhook')
        ->and($findings[0]['message'])->toContain('troubleshooting payment, webhook')
        ->and($findings[0]['message'])->toContain('integrating, supporting')
        ->and($findings[0]['message'])->toContain('Tested payment flows and troubleshot merchant integration issues')
        ->and($findings[1]['detail'])->toContain('recurring billing');
});

it('accepts the same facts when the sentence stays inside what each one establishes', function () {
    $text = 'Integrated and supported merchant webhooks for retry schedules and account management, and helped merchants with recurring payment setup.';

    expect(claimFindings($text, ['webhooks', 'billing', 'troubleshot']))->toBe([]);
});

it('does not reject a verb just because one cited fact has a narrower scope', function () {
    $facts = boldinFacts() + ['webhook-troubleshooting' => 'Troubleshot failed webhook deliveries for merchants.'];

    // A cited fact on the topic establishes troubleshooting, so the broader mix is fine.
    expect(claimFindings('Troubleshot webhook failures and supported recurring billing setup.', ['webhooks', 'billing', 'webhook-troubleshooting'], $facts))->toBe([]);
});

it('treats a sentence about the general topic of a fact the user did not scope as unknown, not as a violation', function () {
    $facts = ['legacy' => 'Webhook retry handling for the payment gateway.'];

    $findings = claimFindings('Supported webhook retry handling.', ['legacy'], $facts, []);

    expect($findings)->toHaveCount(1)->and($findings[0]['severity'])->toBe('review');
});

it('blocks implementation claimed over evidence that only integrated and supported', function () {
    $findings = claimFindings('Built webhook handlers for merchant retry schedules.', ['webhooks', 'billing']);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]['severity'])->toBe('block')
        ->and($findings[0]['detail'])->toBe('Built webhook')
        ->and($findings[0]['message'])->toContain('Ownership or implementation is not established');
});

it('blocks ownership verbs the evidence does not support, and never infers them from configuration or support', function () {
    foreach (['Led webhook configuration for merchants.', 'Owned the recurring billing setup for merchants.'] as $text) {
        $findings = claimFindings($text, ['webhooks', 'billing']);

        expect($findings)->toHaveCount(1)->and($findings[0]['severity'])->toBe('block');
    }

    $configured = ['webhooks' => 'Configured merchant webhook endpoints.'];
    $implementation = claimFindings('Implemented webhook endpoints.', ['webhooks'], $configured, ['webhooks' => ['configured']]);

    expect($implementation[0]['severity'])->toBe('block');
});

it('lets declared implementation cover integration and configuration, but not ownership', function () {
    $facts = ['w' => 'Built webhook receivers for merchant events.'];
    $scopes = ['w' => ['implemented']];

    expect(claimFindings('Implemented webhook receivers.', ['w'], $facts, $scopes))->toBe([])
        ->and(claimFindings('Integrated webhook receivers.', ['w'], $facts, $scopes))->toBe([])
        ->and(claimFindings('Configured webhook retries.', ['w'], $facts, $scopes))->toBe([])
        ->and(claimFindings('Led webhook delivery for merchants.', ['w'], $facts, $scopes)[0]['severity'])->toBe('block');
});

it('flags a strong claim about a topic that no cited fact mentions', function () {
    $findings = claimFindings('Built webhook handlers.', ['troubleshot'], ['troubleshot' => 'Tested payment flows and troubleshot merchant integration issues.'], []);

    expect($findings)->toHaveCount(1)->and($findings[0]['severity'])->toBe('block');
});

it('does not treat nouns such as "integration" or sentences without a topic as claims', function () {
    expect(claimFindings('Served as technical contact for merchant integration onboarding.', ['webhooks']))->toBe([])
        ->and(claimFindings('Improved checkout speed.', ['webhooks']))->toBe([]);
});

it('keeps an ambiguous composite in the resume and flags exactly that sentence for confirmation', function () {
    $context = scopeContext(['webhooks', 'billing', 'troubleshot'], boldinFacts(), boldinScopes());
    $content = [
        'summary' => 'Support engineer.',
        'experience' => [['role_id' => 1, 'bullets' => [
            ['bullet_group_index' => 0, 'text' => BOLDIN_BULLET],
            ['bullet_group_index' => 1, 'text' => 'Troubleshot merchant integration issues.'],
        ]]],
        'selected_projects' => [],
    ];

    $triage = app(ResumeWordingResponseValidator::class)->triage($content, $context);
    $result = app(RecoverWording::class)->recover($triage, $context);
    $flagged = collect($result['items'])->firstWhere('status', 'review');

    expect($triage->hasBlocked())->toBeFalse()
        ->and($result['content']['experience'][0]['bullets'])->toHaveCount(2)
        ->and($result['content']['experience'][0]['bullets'][0]['text'])->toBe(BOLDIN_BULLET)
        ->and($flagged['text'])->toBe(BOLDIN_BULLET)
        ->and($flagged['findings'][0]['detail'])->toBe('troubleshooting payment, webhook');
});

it('omits only the bullet with a clearly unsupported implementation claim and keeps the rest of the resume', function () {
    $context = scopeContext(['webhooks', 'billing'], boldinFacts(), boldinScopes());
    $content = [
        'summary' => 'Support engineer.',
        'experience' => [['role_id' => 1, 'bullets' => [
            ['bullet_group_index' => 0, 'text' => 'Built webhook handlers for merchant retry schedules.'],
            ['bullet_group_index' => 1, 'text' => 'Troubleshot merchant integration issues.'],
        ]]],
        'selected_projects' => [],
    ];

    $result = app(RecoverWording::class)->recover(app(ResumeWordingResponseValidator::class)->triage($content, $context), $context);
    $omitted = collect($result['items'])->firstWhere('status', 'omitted');

    expect(collect($result['content']['experience'][0]['bullets'])->pluck('text')->all())->toBe(['Troubleshot merchant integration issues.'])
        // A removed sentence is not stored in the report; its finding names the claim.
        ->and($omitted['text'])->toBeNull()
        ->and($omitted['findings'][0]['detail'])->toBe('Built webhook')
        ->and($omitted['findings'][0]['kind'])->toBe('claim_scope');
});
