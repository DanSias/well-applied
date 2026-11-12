<?php

use App\Support\ResumeVariant\ClaimFidelityChecker;

/**
 * The repairs that take an unsupported claim out must leave a sentence that still reads, still says what the evidence says,
 * and never widens it. Variant 32 shipped "translate business requirements software" and "Reused Verbatim" from two repairs.
 */
function rsRepair(string $text, array $facts, string $kind): array
{
    $checker = new ClaimFidelityChecker;
    $finding = collect($checker->findings($text, array_keys($facts), $facts))->firstWhere('kind', $kind);

    return [$finding, $finding === null ? null : $checker->repair($text, $finding)];
}

const RS_PLAIN = ['e' => 'Translated business requirements into software for the Analytics team.'];

it('removes only the word production and keeps the preposition and grammar', function (string $text, string $expected) {
    [$finding, $repaired] = rsRepair($text, RS_PLAIN, 'lifecycle_upgrade');

    expect($finding['severity'])->toBe('block')->and($repaired)->toBe($expected);
})->with([
    'into production software' => ['Engineer who can translate business requirements into production software.', 'Engineer who can translate business requirements into software.'],
    'in production environments' => ['Monitored services in production environments for the Analytics team.', 'Monitored services in environments for the Analytics team.'],
    'production deployment' => ['Production deployment of the reporting service for the Analytics team.', 'Deployment of the reporting service for the Analytics team.'],
    'production-ready modifier' => ['Delivered production-ready software for the Analytics team.', 'Delivered software for the Analytics team.'],
    'stage phrase at the end' => ['Delivered the reporting service for the Analytics team to production.', 'Delivered the reporting service for the Analytics team.'],
    'stage phrase before a conjunction' => ['Shipped the reporting service to production and monitored it for the Analytics team.', 'Shipped the reporting service and monitored it for the Analytics team.'],
    'stage phrase before an adverb' => ['Shipped the reporting service to production daily for the Analytics team.', 'Shipped the reporting service daily for the Analytics team.'],
]);

it('does not touch production when the evidence states it', function () {
    expect((new ClaimFidelityChecker)->findings('Built the service into production software for payments.', ['e'], ['e' => 'Built a service that ran in production for payments.']))->toBe([]);
});

it('offers no repair when production is the object and cannot be removed safely', function () {
    [$finding, $repaired] = rsRepair('Supported production for the Analytics reporting service.', RS_PLAIN, 'lifecycle_upgrade');

    expect($finding['severity'])->toBe('block')->and($finding)->not->toHaveKey('repair')->and($repaired)->toBeNull();
});

it('never picks a replacement verb from the fact order', function (array $facts) {
    [, $repaired] = rsRepair('Engineered Verbatim, a documentation Q&A system, reusing the retrieval layer.', $facts, 'ownership_upgrade');

    expect($repaired)->toBe('Built Verbatim, a documentation Q&A system, reusing the retrieval layer.');
})->with([
    'reuse fact first' => [['a' => 'Reused the retrieval layer from Verbatim in the support docs search.', 'b' => 'Built Verbatim, a documentation question-answering system.']],
    'build fact first' => [['b' => 'Built Verbatim, a documentation question-answering system.', 'a' => 'Reused the retrieval layer from Verbatim in the support docs search.']],
]);

it('uses reuse only when it is the only thing the evidence says about the subject', function () {
    [, $repaired] = rsRepair('Engineered Verbatim, a documentation Q&A system.', ['a' => 'Reused the Verbatim retrieval layer for docs search.'], 'ownership_upgrade');

    expect($repaired)->toBe('Reused Verbatim, a documentation Q&A system.');
});

it('offers no verb when no fact describes the subject, or the facts disagree without an order', function (array $facts) {
    [$finding, $repaired] = rsRepair('Engineered Verbatim, a documentation Q&A system.', $facts, 'ownership_upgrade');

    expect($finding['severity'])->toBe('block')->and($repaired)->toBeNull();
})->with([
    'unrelated fact' => [['a' => 'Reused the retrieval layer in Nexus for search.']],
    'no verb to take' => [['a' => 'I worked on Verbatim, a documentation system.']],
    'disagreeing verbs' => [['a' => 'Cut Verbatim answer latency in half.', 'b' => 'Wrote Verbatim documentation.']],
]);

it('keeps the main accomplishment, the metric scope and the lifecycle claim when it repairs', function () {
    $facts = ['a' => 'Built a docs search that cut integration-support ticket volume by 30%.'];
    $text = 'Engineered a docs search that cut integration-support ticket volume by 30% for production users.';
    $checker = new ClaimFidelityChecker;
    $repaired = $text;

    foreach ($checker->findings($text, ['a'], $facts) as $finding) {
        $repaired = $checker->repair($repaired, $finding) ?? $repaired;
    }

    expect($repaired)->toBe('Built a docs search that cut integration-support ticket volume by 30% for users.');
});
