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

/** Before the user confirmed troubleshooting. */
function boldinScopes(): array
{
    return ['webhooks' => ['integrated', 'supported'], 'billing' => ['supported']];
}

/** After the user confirmed they troubleshot both (revision 2 of each fact). */
function confirmedScopes(): array
{
    return ['webhooks' => ['integrated', 'supported', 'troubleshot'], 'billing' => ['supported', 'troubleshot']];
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

it('passes the Boldin sentence once the user has confirmed troubleshooting webhook and recurring billing flows', function () {
    $facts = boldinFacts();
    $facts['webhooks'] .= ' Troubleshot webhook issues.';
    $facts['billing'] .= ' Troubleshot recurring billing flows.';

    expect(claimFindings(BOLDIN_BULLET, ['webhooks', 'billing', 'troubleshot'], $facts, confirmedScopes()))->toBe([]);
});

it('treats the same sentence as reasonable synthesis even before that confirmation: supported integrations plus documented troubleshooting', function () {
    // Each fact is true; together they defend "troubleshooting integration flows". No warning.
    expect(claimFindings(BOLDIN_BULLET, ['webhooks', 'billing', 'troubleshot']))->toBe([]);
});

it('does not warn about ordinary professional wording over hands-on evidence', function () {
    $texts = [
        'Integrated and supported merchant webhooks for retry schedules and account management.',
        'Advised merchants on webhook retry schedules and recurring billing setup.',
        'Configured recurring payment setup and supported merchants through onboarding.',
        'Enabled merchants to adopt webhooks and recurring billing during integration.',
    ];

    foreach ($texts as $text) {
        expect(claimFindings($text, ['webhooks', 'billing', 'troubleshot']))->toBe([], $text);
    }
});

it('does not infer that supporting or configuring a technology means architecting it', function () {
    $block = claimFindings('Built webhook handlers for merchant retry schedules.', ['webhooks', 'billing']);
    $architect = claimFindings('Architected the recurring billing platform for merchants.', ['webhooks', 'billing']);

    expect($block)->toHaveCount(1)
        ->and($block[0]['severity'])->toBe('block')
        ->and($block[0]['detail'])->toBe('Built webhook')
        ->and($block[0]['message'])->toContain('Implementation is not established')
        ->and($architect[0]['severity'])->toBe('block');
});

it('sends ownership wording to review rather than blocking it, because scope can be genuinely ambiguous', function () {
    foreach (['Led webhook configuration for merchants.', 'Owned the recurring billing setup for merchants.'] as $text) {
        $findings = claimFindings($text, ['webhooks', 'billing']);

        expect($findings)->toHaveCount(1)
            ->and($findings[0]['severity'])->toBe('review')
            ->and($findings[0]['message'])->toContain('Confirm you owned or built this');
    }
});

it('lets declared implementation back building and integrating, and still reviews ownership', function () {
    $facts = ['w' => 'Built webhook receivers for merchant events.'];
    $scopes = ['w' => ['implemented']];

    expect(claimFindings('Implemented webhook receivers.', ['w'], $facts, $scopes))->toBe([])
        ->and(claimFindings('Integrated webhook receivers.', ['w'], $facts, $scopes))->toBe([])
        ->and(claimFindings('Led webhook delivery for merchants.', ['w'], $facts, $scopes)[0]['severity'])->toBe('review');
});

it('reviews, not blocks, building claimed over a fact on the topic when a different fact shows building elsewhere', function () {
    $facts = ['webhooks' => boldinFacts()['webhooks'], 'service' => 'Built a Laravel backend service for payment data.'];

    $findings = claimFindings('Built webhook delivery for merchants.', ['webhooks', 'service'], $facts, ['webhooks' => ['integrated']]);

    expect($findings)->toHaveCount(1)->and($findings[0]['severity'])->toBe('review');
});

it('blocks building a topic that no cited fact mentions, and reviews it when building is shown elsewhere', function () {
    $facts = ['t' => 'Tested payment flows and troubleshot merchant integration issues.', 'b' => 'Built a Laravel backend service.'];

    expect(claimFindings('Built webhook handlers.', ['t'], $facts, [])[0]['severity'])->toBe('block')
        ->and(claimFindings('Built webhook handlers.', ['t', 'b'], $facts, [])[0]['severity'])->toBe('review');
});

it('blocks naming Rails without cited Rails evidence, whatever the verb, but allows transferable framework wording', function () {
    $facts = ['laravel' => 'Built a Laravel backend service handling payment data.'];

    $rails = claimFindings('Worked with Ruby on Rails services for merchants.', ['laravel'], $facts, []);
    $transferable = claimFindings('Built backend services in Laravel, with MVC patterns that transfer to Rails-style frameworks.', ['laravel'], $facts, []);

    expect($rails)->toHaveCount(1)
        ->and($rails[0]['severity'])->toBe('block')
        ->and($rails[0]['message'])->toContain('no cited evidence mentions it');
    // Naming Rails even as a comparison is not allowed without evidence; the honest wording avoids the name.
    expect($transferable[0]['severity'])->toBe('block');
    expect(claimFindings('Built backend services in Laravel using MVC patterns common to modern web frameworks.', ['laravel'], $facts, []))->toBe([]);
});

it('keeps the confirmed-limitation block on Rails independent of the claim checker', function () {
    $context = scopeContext(['laravel'], ['laravel' => 'Built a Laravel backend service.'], []);
    $context = new WordingCheckContext(
        $context->validRoleIds, $context->expectedBulletGroupIndexesByRole, $context->validSelectedProjectIds, $context->denylistTerms,
        $context->careerFactKeysByLocation, $context->guardrailByFactKey, $context->skillIdsByFactKey, $context->canonicalSkillsById,
        $context->textRecognizedSkillIdsByFactKey, $context->directTargetTermsByLocation, $context->restrictedFactKeys, $context->factTextByKey,
        $context->restrictedTerms, $context->genericTerms, ['Rails'], [],
    );
    $content = ['summary' => 'Engineer.', 'experience' => [['role_id' => 1, 'bullets' => [
        ['bullet_group_index' => 0, 'text' => 'Built Rails services.'], ['bullet_group_index' => 1, 'text' => 'Built Laravel services.'],
    ]]], 'selected_projects' => []];

    $result = app(RecoverWording::class)->recover(app(ResumeWordingResponseValidator::class)->triage($content, $context), $context);

    expect(collect($result['content']['experience'][0]['bullets'])->pluck('text')->all())->toBe(['Built Laravel services.']);
});

it('does not treat nouns such as "integration" or sentences without a topic as claims', function () {
    expect(claimFindings('Served as technical contact for merchant integration onboarding.', ['webhooks']))->toBe([])
        ->and(claimFindings('Improved checkout speed.', ['webhooks']))->toBe([]);
});

it('keeps a flagged ownership sentence in the resume and names exactly that sentence', function () {
    $context = scopeContext(['webhooks', 'billing'], boldinFacts(), boldinScopes());
    $text = 'Led webhook configuration for merchants.';
    $content = [
        'summary' => 'Support engineer.',
        'experience' => [['role_id' => 1, 'bullets' => [
            ['bullet_group_index' => 0, 'text' => $text],
            ['bullet_group_index' => 1, 'text' => 'Troubleshot merchant integration issues.'],
        ]]],
        'selected_projects' => [],
    ];

    $triage = app(ResumeWordingResponseValidator::class)->triage($content, $context);
    $result = app(RecoverWording::class)->recover($triage, $context);
    $flagged = collect($result['items'])->firstWhere('status', 'review');

    expect($triage->hasBlocked())->toBeFalse()
        ->and($result['content']['experience'][0]['bullets'])->toHaveCount(2)
        ->and($flagged['text'])->toBe($text)
        ->and($flagged['findings'][0]['detail'])->toBe('Led webhook');
});

it('omits only the bullet with a clearly contradicted implementation claim and keeps the rest of the resume', function () {
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

it('stays quiet on the stored Boldin variant #7 wording: no finding for the sentence that used to be flagged', function () {
    $findings = claimFindings(BOLDIN_BULLET, ['webhooks', 'billing', 'troubleshot'], boldinFacts(), confirmedScopes());

    expect($findings)->toBe([]);
});
