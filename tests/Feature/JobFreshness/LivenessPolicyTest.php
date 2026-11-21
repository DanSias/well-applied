<?php

use App\Support\JobFreshness\FreshnessState;
use App\Support\JobFreshness\LivenessDecision;
use App\Support\JobFreshness\LivenessPolicy;
use App\Support\JobFreshness\LivenessRecord;
use App\Support\JobFreshness\Observation;
use Carbon\CarbonImmutable;

function polNow(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-11-22 12:00:00', 'UTC');
}

function polObs(string $result, string $basis, int $hoursAgo, string $provider = 'himalayas', string $source = 'job_endpoint', ?int $expiresAgoHours = null): Observation
{
    return new Observation($provider, $source, $result, $basis, polNow()->subHours($hoursAgo), $expiresAgoHours === null ? null : polNow()->subHours($expiresAgoHours));
}

const POL_ACTIVE = ['active', 'http_200'];
const POL_CLOSED = ['closed', 'http_410'];
const POL_UNSURE = ['uncertain', 'http_404'];

function frDecide(array $observations, bool $closed = false, bool $protected = false): LivenessDecision
{
    return (new LivenessPolicy(2, 6, 2))->decide($observations, $closed, $protected, polNow());
}

it('has no opinion without evidence', function () {
    $d = frDecide([]);
    expect([$d->state, $d->action, $d->reason, $d->actionable()])->toBe([FreshnessState::Unchecked, 'none', 'no_observation', true]);
});

it('makes a job non-actionable at once on known expiry, from a single observation', function () {
    $d = frDecide([polObs('expired', 'expiry_date_passed', 0, source: 'expiry_date', expiresAgoHours: 1)]);

    expect([$d->state, $d->actionable(), $d->action, $d->reason, $d->conflict])->toBe([FreshnessState::Expired, false, 'set_closed', 'expired_known', false]);
});

it('lets a known expiry win over a LATER "open" answer, and records the conflict', function () {
    $d = frDecide([polObs('expired', 'expiry_date_passed', 3, source: 'expiry_date', expiresAgoHours: 5), polObs(...[...POL_ACTIVE, 1])]);

    expect([$d->state, $d->reason, $d->conflict])->toBe([FreshnessState::Expired, 'expired_known_conflicting_open_answer', true]);
});

it('ignores an "expired" observation whose date is still in the future', function () {
    $o = new Observation('himalayas', 'expiry_date', 'expired', 'expiry_date_passed', polNow(), polNow()->addHour());

    $d = frDecide([$o]);

    expect($d->actionable())->toBeTrue('a future date is not an expiry')->and($d->state)->toBe(FreshnessState::Uncertain, 'an inconsistent observation is uncertain, never expiry');
});

it('treats ONE explicit closure as evidence, not proof: uncertain and still actionable', function () {
    $d = frDecide([polObs(...[...POL_CLOSED, 1])]);

    expect([$d->state, $d->action, $d->reason, $d->actionable()])->toBe([FreshnessState::Uncertain, 'none', 'closed_once_awaiting_confirmation', true]);
});

it('confirms a closure only after two SEPARATE explicit closures, at least the minimum gap apart', function () {
    $sameRun = frDecide([polObs(...[...POL_CLOSED, 1]), polObs(...[...POL_CLOSED, 0])]);
    expect([$sameRun->state, $sameRun->reason])->toBe([FreshnessState::Uncertain, 'closed_once_awaiting_confirmation'], 'two observations an hour apart are one observation');

    $separate = frDecide([polObs(...[...POL_CLOSED, 30]), polObs(...[...POL_CLOSED, 1])]);
    expect([$separate->state, $separate->action, $separate->reason, $separate->actionable()])->toBe([FreshnessState::Closed, 'set_closed', 'closure_confirmed', false]);

    $exactGap = frDecide([polObs(...[...POL_CLOSED, 7]), polObs(...[...POL_CLOSED, 1])]);
    expect($exactGap->state)->toBe(FreshnessState::Closed);
    $almost = frDecide([polObs(...[...POL_CLOSED, 6]), polObs(...[...POL_CLOSED, 1])]);
    expect($almost->state)->toBe(FreshnessState::Uncertain, 'five hours apart is not enough');
});

it('NEVER treats 404, unknown, a timeout, a rate limit or any transient error as closure, however many there are', function () {
    $many = array_map(fn ($h) => polObs('uncertain', 'http_404', $h), range(1, 40));
    $d = frDecide($many);

    expect([$d->state, $d->action, $d->actionable(), $d->reason])->toBe([FreshnessState::Uncertain, 'none', true, 'only_uncertain_observations']);
});

it('lets uncertain observations neither extend nor break a closure streak', function () {
    $d = frDecide([polObs(...[...POL_CLOSED, 40]), polObs('uncertain', 'network_error', 30), polObs('uncertain', 'rate_limited', 20), polObs(...[...POL_CLOSED, 1])]);

    expect($d->state)->toBe(FreshnessState::Closed);
});

it('resets a closure streak on an "open" answer, and reports the job open', function () {
    $d = frDecide([polObs(...[...POL_CLOSED, 40]), polObs(...[...POL_ACTIVE, 20]), polObs(...[...POL_CLOSED, 1])]);
    expect([$d->state, $d->reason])->toBe([FreshnessState::Uncertain, 'closed_once_awaiting_confirmation']);

    $open = frDecide([polObs(...[...POL_CLOSED, 40]), polObs(...[...POL_ACTIVE, 1])]);
    expect([$open->state, $open->action, $open->reason])->toBe([FreshnessState::Active, 'none', 'provider_reports_open']);
});

it('is idempotent: repeating the same observations, in any order, decides the same', function () {
    $obs = [polObs(...[...POL_CLOSED, 30]), polObs(...[...POL_CLOSED, 1]), polObs('uncertain', 'http_404', 10)];
    $a = frDecide($obs);
    $b = frDecide(array_reverse($obs));
    $c = frDecide([...$obs, ...$obs]);

    expect($b->toArray())->toBe($a->toArray())->and($c->state)->toBe($a->state);
});

it('leaves a confirmed-closed job closed: with no new provider evidence nothing reopens it', function () {
    $d = frDecide([], closed: true);
    expect([$d->state, $d->action, $d->reason, $d->actionable()])->toBe([FreshnessState::Closed, 'none', 'closed_stays_closed', false]);

    $one = frDecide([polObs(...[...POL_ACTIVE, 1])], closed: true);
    expect([$one->state, $one->action])->toBe([FreshnessState::Closed, 'none'], 'one "open" answer is not enough to reopen');

    $sameRun = frDecide([polObs(...[...POL_ACTIVE, 2]), polObs(...[...POL_ACTIVE, 0])], closed: true);
    expect($sameRun->state)->toBe(FreshnessState::Closed);
});

it('reopens a closed job only on two separate "open" answers from the provider', function () {
    $d = frDecide([polObs(...[...POL_ACTIVE, 30]), polObs(...[...POL_ACTIVE, 1])], closed: true);

    expect([$d->state, $d->action, $d->reason, $d->actionable()])->toBe([FreshnessState::Active, 'reopen', 'reopen_confirmed_by_provider', true]);
});

it('never reopens a known expiry', function () {
    $d = frDecide([polObs('expired', 'expiry_date_passed', 1, source: 'expiry_date', expiresAgoHours: 100), polObs(...[...POL_ACTIVE, 30]), polObs(...[...POL_ACTIVE, 1])], closed: true);

    expect([$d->state, $d->action])->toBe([FreshnessState::Expired, 'none']);
});

it('labels a protected job but never gives it a status-changing action: it gets review', function (Closure $observations, bool $closed, FreshnessState $state) {
    $d = frDecide($observations(), $closed, protected: true);

    expect([$d->state, $d->action, $d->protected])->toBe([$state, 'review', true]);
})->with([
    'expired' => [fn () => [polObs('expired', 'expiry_date_passed', 1, 'himalayas', 'expiry_date', 2)], false, FreshnessState::Expired],
    'confirmed closure' => [fn () => [polObs(...[...POL_CLOSED, 30]), polObs(...[...POL_CLOSED, 1])], false, FreshnessState::Closed],
    'reopen' => [fn () => [polObs(...[...POL_ACTIVE, 30]), polObs(...[...POL_ACTIVE, 1])], true, FreshnessState::Active],
]);

it('gives a protected job no action at all when nothing would change', function () {
    expect(frDecide([polObs(...[...POL_ACTIVE, 1])], protected: true)->action)->toBe('none')->and(frDecide([], true, true)->action)->toBe('none');
});

// ---- the stored record ---------------------------------------------------------------------------------------------------------------------------

it('stores observations beside discovery and canonical without touching them, capped and de-duplicated', function () {
    $metadata = ['discovery' => ['expiryDate' => 1], 'canonical' => ['x' => 1]];
    $obs = array_map(fn ($h) => polObs('uncertain', 'http_404', $h), range(1, 14));
    $decision = frDecide($obs);

    $stored = LivenessRecord::with($metadata, $obs, $decision, 10);
    $again = LivenessRecord::with($stored, $obs, $decision, 10);

    expect($stored['discovery'])->toBe(['expiryDate' => 1])->and($stored['canonical'])->toBe(['x' => 1])
        ->and($stored['liveness']['observations'])->toHaveCount(10)->and($stored['liveness']['schema'])->toBe(1)
        ->and($stored['liveness']['decision'])->toBe(['state' => 'uncertain', 'action' => 'none', 'reason' => 'only_uncertain_observations'])
        ->and($again['liveness']['observations'])->toBe($stored['liveness']['observations'], 'recording the same observations again changes nothing');
    expect(count(LivenessRecord::observations($stored)))->toBe(10);
});

it('reads stored observations tolerantly: malformed entries are dropped, never guessed', function () {
    $good = polObs(...[...POL_CLOSED, 2])->toArray();

    expect(LivenessRecord::observations(['liveness' => ['observations' => [$good, 'garbage', ['result' => 'closed'], null]]]))->toHaveCount(1)
        ->and(LivenessRecord::observations(null))->toBe([])->and(LivenessRecord::observations(['liveness' => 'nope']))->toBe([]);
});
