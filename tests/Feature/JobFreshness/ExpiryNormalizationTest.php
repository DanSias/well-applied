<?php

use App\Enums\JobDisposition;
use App\Support\JobDiscovery\Providers\HimalayasDiscoveryProvider;
use App\Support\JobFreshness\HimalayasLivenessInterpreter as Himalayas;
use App\Support\JobFreshness\JobFreshness;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/SurfacesTest.php';

$sec = 1795000000;   // inside the seconds range

it('normalises an expiry to integer epoch seconds, and anything ambiguous or malformed to unknown', function (mixed $in, ?int $out) {
    expect(Himalayas::normalizeExpiry($in))->toBe($out);
})->with([
    'seconds' => [1795000000, 1795000000],
    'digit string' => ['1795000000', 1795000000],
    'integral float (JSON 1.795e9)' => [1.795e9, 1795000000],
    'milliseconds, 13 digits' => [1795000000123, 1795000000],
    'milliseconds as a digit string' => ['1795000000123', 1795000000],
    'ISO with Z' => ['2026-11-22T10:00:00Z', 1795341600],
    'ISO with a positive offset' => ['2026-11-22T12:00:00+02:00', 1795341600],
    'ISO with fraction' => ['2026-11-22T10:00:00.250Z', 1795341600],
    'ambiguous: between the seconds and milliseconds ranges' => [17950000000, null],
    'too small to be a date (zero)' => [0, null],
    'negative' => [-1795000000, null],
    'before the range' => [100000, null],
    'after the range, in seconds' => [4200000000, null],
    'a date without time or offset (zone unknown)' => ['2026-12-01', null],
    'a date-time without an offset (zone unknown)' => ['2026-12-01T10:00:00', null],
    'fractional float' => [1795000000.5, null],
    'NaN-like text' => ['soon', null],
    'a number with a sign string' => ['-1795000000', null],
    'boolean' => [true, null],
    'array' => [[1795000000], null],
    'null' => [null, null],
    'empty string' => ['', null],
]);

it('treats an unknown expiry as no expiry: it is never expired', function (mixed $in) {
    expect(Himalayas::parseExpiry($in))->toBeNull();
})->with([[17950000000], ['2026-12-01'], ['soon'], [0], [null]]);

it('reads a STORED expiry only as an integer in seconds: the one form the filters and the badge share', function (mixed $stored, ?int $out) {
    expect(Himalayas::storedSeconds($stored))->toBe($out);
})->with([[1795000000, 1795000000], ['1795000000', null], ['2026-12-01T10:00:00Z', null], [1795000000123, null], [17950000000, null], [null, null]]);

it('stores the normalised expiry at the ingestion boundary and drops what is unknown', function (mixed $feed, mixed $stored) {
    config(['services.himalayas.base_url' => 'https://himalayas.test/jobs/api']);
    Http::fake(['himalayas.test/*' => Http::response(['jobs' => [[
        'title' => 'Engineer', 'companyName' => 'Acme', 'companySlug' => 'acme', 'description' => 'd', 'pubDate' => 1790000000, 'expiryDate' => $feed,
        'applicationLink' => 'https://himalayas.app/companies/acme/jobs/engineer-1', 'guid' => 'g-1',
    ]], 'totalCount' => 1])]);

    $candidate = (new HimalayasDiscoveryProvider)->retrieve()[0];

    expect($candidate->sourceMetadata['expiryDate'] ?? null)->toBe($stored)
        ->and($candidate->applicationUrl)->toBe('https://himalayas.app/companies/acme/jobs/engineer-1');
})->with([
    'seconds' => [1795000000, 1795000000], 'milliseconds' => [1795000000123, 1795000000], 'digit string' => ['1795000000', 1795000000],
    'ISO with offset' => ['2026-11-22T10:00:00Z', 1795341600], 'date only' => ['2026-12-01', null], 'ambiguous' => [17950000000, null], 'garbage' => ['soon', null], 'absent' => [null, null],
]);

it('keeps the Inbox, Home and the badge in agreement for every stored form of the expiry', function (mixed $stored, bool $expired) {
    frOn();
    $job = frJob(['disposition' => JobDisposition::New]);
    $meta = $job->discovery_metadata;
    $meta['discovery']['expiryDate'] = $stored;
    $job->update(['discovery_metadata' => $meta]);

    $inInbox = collect($this->get('/jobs')->viewData('page')['props']['jobs'])->pluck('id')->contains($job->id);
    $badge = JobFreshness::view($job->fresh());
    $home = $this->get('/')->viewData('page')['props']['overview']['jobs'] ?? null;

    expect($inInbox)->toBe(! $expired)
        ->and($badge['state'] === 'expired')->toBe($expired)
        ->and($badge['actionable'])->toBe(! $expired)
        ->and($home)->toBe($expired ? 0 : 1);
})->with(function () {
    $past = now()->subDay()->timestamp;
    $future = now()->addWeek()->timestamp;

    return [
        'integer, passed' => [$past, true],
        'integer, future' => [$future, false],
        'integer, absent (null)' => [null, false],
        'legacy digit string, passed' => [(string) $past, false],
        'legacy ISO string, passed' => [now()->subDay()->toIso8601String(), false],
        'legacy milliseconds, passed' => [$past * 1000, false],
        'ambiguous number' => [17950000000, false],
        'garbage' => ['soon', false],
    ];
});
