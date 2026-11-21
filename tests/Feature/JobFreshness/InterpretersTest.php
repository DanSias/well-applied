<?php

use App\Support\JobFreshness\HimalayasLivenessInterpreter;
use App\Support\JobFreshness\JobicyStatusInterpreter;
use App\Support\JobFreshness\Observation;
use Carbon\CarbonImmutable;

const FR_NOW = '2026-11-22 12:00:00';

function frNow(): CarbonImmutable
{
    return CarbonImmutable::parse(FR_NOW, 'UTC');
}

function frHimalayas(mixed $expiry, ?array $check): array
{
    return (new HimalayasLivenessInterpreter)->interpret($expiry, $check, frNow());
}

// ---- Himalayas -----------------------------------------------------------------------------------------------------------------------------

it('treats a passed expiryDate as known expiry from that one fact, with no request', function () {
    [$o] = frHimalayas(frNow()->subDay()->timestamp, null);

    expect($o)->toBeInstanceOf(Observation::class)
        ->and([$o->provider, $o->source, $o->result, $o->basis])->toBe(['himalayas', 'expiry_date', 'expired', 'expiry_date_passed'])
        ->and($o->expiresAt->timestamp)->toBe(frNow()->subDay()->timestamp);
});

it('draws the expiry boundary at "at or before now": exactly now is expired, a second later is not', function () {
    expect(frHimalayas(frNow()->timestamp, null))->toHaveCount(1)
        ->and(frHimalayas(frNow()->timestamp - 1, null))->toHaveCount(1)
        ->and(frHimalayas(frNow()->timestamp + 1, null))->toBe([]);
});

it('reads the expiry as unix seconds, an ISO date or a digit string, and ignores anything else', function (mixed $value, bool $expired) {
    expect(count(frHimalayas($value, null)) === 1)->toBe($expired);
})->with([
    'unix seconds' => [1795000000, true], 'digit string' => ['1795000000', true], 'ISO date' => ['2026-11-01T00:00:00Z', true], 'future ISO' => ['2027-01-01T00:00:00Z', false],
    'zero' => [0, false], 'negative' => [-5, false], 'milliseconds-sized number' => [1795000000000, false], 'garbage' => ['soon', false], 'array' => [['x'], false], 'null' => [null, false], 'empty' => ['', false],
]);

it('interprets each documented endpoint answer', function (array $check, string $result, string $basis) {
    $o = collect(frHimalayas(null, $check))->firstWhere('source', 'job_endpoint');

    expect([$o->result, $o->basis])->toBe([$result, $basis]);
})->with([
    '200 is active' => [['http_status' => 200], 'active', 'http_200'],
    '410 is explicit closure' => [['http_status' => 410, 'body' => ['expiryDate' => 1790000000]], 'closed', 'http_410'],
    '404 is uncertain, never closure' => [['http_status' => 404], 'uncertain', 'http_404'],
    '429 is uncertain' => [['http_status' => 429, 'retry_after' => 30], 'uncertain', 'rate_limited'],
    '500 is uncertain' => [['http_status' => 500], 'uncertain', 'server_error'],
    '503 is uncertain' => [['http_status' => 503], 'uncertain', 'server_error'],
    '403 is uncertain' => [['http_status' => 403], 'uncertain', 'unexpected_status'],
    '301 is uncertain' => [['http_status' => 301], 'uncertain', 'unexpected_status'],
    'a timeout is uncertain' => [['error' => 'timeout'], 'uncertain', 'network_error'],
    'no status at all is uncertain' => [[], 'uncertain', 'network_error'],
]);

it('records the retry delay, clamped, and the expiry a 410 reports', function () {
    $o = collect(frHimalayas(null, ['http_status' => 429, 'retry_after' => 999999]))->first();
    expect($o->retryAfterSeconds)->toBe(3600);

    $closed = collect(frHimalayas(null, ['http_status' => 410, 'body' => ['expiryDate' => 1790000000]]))->first();
    expect($closed->expiresAt->timestamp)->toBe(1790000000);
});

it('lets a known expiry win, on a stored date or one the endpoint reports, even beside a 200 "open" answer', function () {
    $stored = frHimalayas(frNow()->subDay()->timestamp, ['http_status' => 200]);
    expect(array_map(fn ($o) => $o->result, $stored))->toBe(['expired', 'active']);

    $reported = frHimalayas(null, ['http_status' => 200, 'body' => ['expiryDate' => frNow()->subHour()->timestamp]]);
    expect(array_map(fn ($o) => [$o->source, $o->result, $o->basis], $reported))->toBe([['job_endpoint', 'active', 'http_200'], ['expiry_date', 'expired', 'reported_expiry_passed']]);
});

it('never carries a URL, a header, a body or a credential in an observation', function () {
    foreach ([['http_status' => 200, 'body' => ['applicationLink' => 'https://himalayas.app/x?token=SECRET', 'expiryDate' => 1790000000]], ['http_status' => 410]] as $check) {
        foreach (frHimalayas(frNow()->subDay()->timestamp, $check) as $o) {
            expect(json_encode($o->toArray()))->not->toContain('://')->not->toContain('SECRET')->not->toContain('applicationLink');
        }
    }
});

// ---- Jobicy --------------------------------------------------------------------------------------------------------------------------------

function frJobicy(string|int $id, ?array $check): Observation
{
    return (new JobicyStatusInterpreter)->interpret($id, $check, frNow());
}

it('interprets the documented statuses in either plausible body shape', function (array $body, string $id, string $result, string $basis) {
    $o = frJobicy($id, ['http_status' => 200, 'body' => $body]);

    expect([$o->provider, $o->source, $o->result, $o->basis])->toBe(['jobicy', 'status_endpoint', $result, $basis]);
})->with([
    'map: closed' => [['statuses' => ['101' => 'closed']], '101', 'closed', 'status_closed'],
    'map: active' => [['statuses' => ['101' => 'active']], '101', 'active', 'status_active'],
    'map: unknown is uncertain' => [['statuses' => ['101' => 'unknown']], '101', 'uncertain', 'status_unknown'],
    'map: case-insensitive' => [['statuses' => ['101' => 'CLOSED']], '101', 'closed', 'status_closed'],
    'map: an unrecognised word is unknown' => [['statuses' => ['101' => 'maybe']], '101', 'uncertain', 'status_unknown'],
    'list: closed' => [['jobs' => [['id' => 101, 'status' => 'closed']]], '101', 'closed', 'status_closed'],
    'list: active' => [['results' => [['id' => '101', 'status' => 'active']]], '101', 'active', 'status_active'],
    'id absent from the answer' => [['statuses' => ['999' => 'closed']], '101', 'uncertain', 'not_in_response'],
    'empty list' => [['jobs' => []], '101', 'uncertain', 'not_in_response'],
    'a shape it does not know' => [['something' => 'else'], '101', 'uncertain', 'unrecognised_shape'],
    'a string where an object belongs' => [['statuses' => 'closed'], '101', 'uncertain', 'unrecognised_shape'],
]);

it('treats every non-200 answer and every failure as uncertain, never closure', function (?array $check, string $basis) {
    $o = frJobicy(101, $check);

    expect([$o->result, $o->basis])->toBe(['uncertain', $basis]);
})->with([
    '429' => [['http_status' => 429, 'retry_after' => 1], 'rate_limited'], '503' => [['http_status' => 503, 'retry_after' => 5], 'server_error'], '500' => [['http_status' => 500], 'server_error'],
    '400' => [['http_status' => 400], 'bad_request'], '404' => [['http_status' => 404], 'unexpected_status'], '405' => [['http_status' => 405], 'unexpected_status'],
    'a timeout' => [null, 'network_error'], 'no status' => [[], 'network_error'],
]);

it('records Retry-After on a rate limit, clamped', function () {
    expect(frJobicy(1, ['http_status' => 429, 'retry_after' => 7])->retryAfterSeconds)->toBe(7)->and(frJobicy(1, ['http_status' => 429, 'retry_after' => 99999])->retryAfterSeconds)->toBe(3600);
});

// ---- the stored form -------------------------------------------------------------------------------------------------------------------

it('round-trips an observation and rejects anything malformed from stored JSON', function () {
    $o = frHimalayas(frNow()->subDay()->timestamp, ['http_status' => 410])[0];
    $back = Observation::fromArray($o->toArray());
    expect($back->toArray())->toBe($o->toArray());

    foreach ([null, 'x', [], ['provider' => 'adzuna'] + $o->toArray(), ['result' => 'maybe'] + $o->toArray(), ['source' => 'scrape'] + $o->toArray(), ['basis' => 'Has Spaces'] + $o->toArray(), ['observed_at' => 'never'] + $o->toArray()] as $bad) {
        expect(Observation::fromArray($bad))->toBeNull();
    }
});
