<?php

use App\Models\JobPosting;
use App\Support\JobDiscovery\JobicyCommercial\JobicyApiClient;
use App\Support\JobDiscovery\JobicyCommercial\SampleRun;
use App\Support\JobDiscovery\JobicyCommercial\SpendBudget;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

require_once __DIR__.'/JobicyFixtures.php';

beforeEach(function () {
    Sleep::fake();
    config(['services.jobicy_commercial.base_url' => 'https://jobicy.test/api/jobs', 'services.jobicy_commercial.api_key' => JBC_KEY]);
});

/** Free page first, Commercial page second; records every request. */
function jbcRunFake(?array $commercial = null, int $commercialStatus = 200, array $headers = [], int $publicStatus = 200): ArrayObject
{
    [$public, $com, $total] = jbcPair();
    $sent = new ArrayObject;
    Http::fake(function (Request $request) use ($sent, $public, $com, $total, $commercial, $commercialStatus, $headers, $publicStatus) {
        $sent[] = $request;
        if (! $request->hasHeader('Authorization')) {
            return Http::response($publicStatus === 200 ? jbcEnvelope($public) : ['error' => 'x'], $publicStatus, $publicStatus === 429 ? ['Retry-After' => '5'] : []);
        }

        return Http::response($commercial ?? jbcEnvelope($com, $total), $commercialStatus, $headers);
    });

    return $sent;
}

function jbcRun(int $count = 24, int $cents = 100, int $requests = 2): array
{
    return (new SampleRun(JobicyApiClient::fromConfig()))->run(new SpendBudget($cents, $requests), $count);
}

// ---- budget ---------------------------------------------------------------------------------------------------------------------------

it('has hard ceilings, and refuses a budget that is empty or above them', function () {
    foreach ([[0, 2], [201, 2], [10, 0], [10, 7]] as [$cents, $requests]) {
        expect(fn () => new SpendBudget($cents, $requests))->toThrow(InvalidArgumentException::class);
    }
    expect((new SpendBudget(200, 6))->maxCostCents)->toBe(200);
});

it('charges the WORST case before a paid request: count cents must fit what is left', function () {
    $b = new SpendBudget(50, 2);
    expect($b->canAffordCommercial(50))->toBeTrue()->and($b->canAffordCommercial(51))->toBeFalse();
    $b->reserve(30);
    expect($b->canAffordCommercial(21))->toBeFalse()->and($b->canAffordCommercial(20))->toBeTrue();
    $b->recordCharge(0.12);
    expect($b->reportedCents())->toBe(12)->and($b->exceeded())->toBeFalse();
    $b->recordCharge(0.50);
    expect($b->exceeded())->toBeTrue();
});

it('stops asking once the request budget is used', function () {
    $b = new SpendBudget(100, 1);
    $b->recordRequest();
    expect($b->canRequest())->toBeFalse()->and($b->canAffordCommercial(1))->toBeFalse();
});

// ---- a run ----------------------------------------------------------------------------------------------------------------------------

it('runs one free request then one paid request, spaced a second apart, and reports the comparison', function () {
    $sent = jbcRunFake();
    $r = jbcRun();

    expect($r['run'])->toMatchArray(['status' => 'completed', 'requests_made' => 2, 'reported_cents' => 15, 'budget_cents' => 100, 'worst_case_cents' => 24, 'retry_after_seconds' => null])
        ->and($sent)->toHaveCount(2)
        ->and($sent[0]->hasHeader('Authorization'))->toBeFalse()
        ->and($sent[1]->header('Authorization'))->toBe(['Bearer '.JBC_KEY])
        ->and($r['totals']['matched'])->toBe(19)->and($r['destinations']['genuine'])->toBe(9)
        ->and($r)->not->toHaveKey('_candidates');
    Sleep::assertSleptTimes(1);
    Sleep::assertSequence([Sleep::for(SampleRun::SPACING_MILLISECONDS)->milliseconds()]);
});

it('refuses before ANY request when there is no key or the worst case does not fit the budget', function () {
    Http::fake();
    config(['services.jobicy_commercial.api_key' => null]);
    expect(jbcRun()['run']['status'])->toBe('not_configured');

    config(['services.jobicy_commercial.api_key' => JBC_KEY]);
    expect(jbcRun(count: 24, cents: 23)['run']['status'])->toBe('budget_too_small')
        ->and(jbcRun(count: 24, cents: 100, requests: 1)['run']['status'])->toBe('budget_too_small');
    Http::assertNothingSent();
});

it('NEVER retries: an authorization failure on the paid request is one attempt, reported, with the charge marked unknown', function (int $status, string $reason) {
    $sent = jbcRunFake(['error' => 'nope'], $status);
    $r = jbcRun();

    expect($r['run'])->toMatchArray(['status' => $reason, 'stopped_at_tier' => 'commercial', 'http_status' => $status, 'charge_unknown' => true, 'requests_made' => 2])
        ->and($sent)->toHaveCount(2)
        ->and($r)->not->toHaveKey('totals');
})->with([[401, 'auth_failed'], [403, 'auth_failed'], [402, 'insufficient_balance'], [500, 'server_error'], [400, 'bad_request']]);

it('honours Retry-After by STOPPING and recording it, never by waiting and trying again', function () {
    $sent = jbcRunFake(['error' => 'slow down'], 429, ['Retry-After' => '45']);
    $r = jbcRun();

    expect($r['run'])->toMatchArray(['status' => 'rate_limited', 'retry_after_seconds' => 45, 'stopped_at_tier' => 'commercial'])->and($sent)->toHaveCount(2);
    Sleep::assertSleptTimes(1);   // only the spacing between the two requests; no retry sleep
});

it('stops at the free tier on a 429 there, before spending anything', function () {
    $sent = jbcRunFake(null, 200, [], 429);
    $free = jbcRun();

    expect($free['run'])->toMatchArray(['status' => 'rate_limited', 'stopped_at_tier' => 'public', 'retry_after_seconds' => 5, 'requests_made' => 1, 'charge_unknown' => false, 'reported_cents' => 0])->and($sent)->toHaveCount(1);
});

it('clamps an absurd Retry-After', function () {
    jbcRunFake(['error' => 'x'], 503, ['Retry-After' => '86400']);
    expect(jbcRun()['run']['retry_after_seconds'])->toBe(3600);
});

it('flags a paid response that reports more than the budget allowed', function () {
    [$public, $com] = jbcPair();
    jbcRunFake(jbcEnvelope($com, 9.99));
    $r = jbcRun(count: 24, cents: 30);

    expect($r['run']['status'])->toBe('budget_exceeded')->and($r['run']['reported_cents'])->toBe(999);
});

it('flags a Commercial answer that carries no cost field: the key may have had no effect', function () {
    [$public] = jbcPair();
    jbcRunFake(jbcEnvelope($public));          // what a free-tier answer to a bad key might look like
    $r = jbcRun();

    expect($r['charges']['commercial_tier_observed'])->toBeFalse()->and($r['warnings'][0])->toStartWith('commercial_tier_not_observed')->and($r['run']['reported_cents'])->toBe(0);
});

it('handles a malformed page without charging or crashing', function () {
    jbcRunFake(['jobs' => 'not a list']);
    $r = jbcRun();

    expect($r['run']['status'])->toBe('malformed_response')->and($r['run']['charge_unknown'])->toBeTrue();
});

it('never writes the report, a log line or an exception with the key, and never touches the database', function () {
    Log::spy();
    $queries = [];
    DB::listen(function ($q) use (&$queries) {
        $queries[] = $q->sql;
    });
    $before = JobPosting::count();
    $queries = [];

    foreach ([jbcRunFake(), jbcRunFake(['error' => 'Bearer '.JBC_KEY], 401), jbcRunFake(['error' => JBC_KEY], 429, ['Retry-After' => '2'])] as $_) {
        $json = json_encode(jbcRun());
        expect($json)->not->toContain(JBC_KEY)->not->toContain('SECRET')->not->toContain('Bearer');
    }

    expect($queries)->toBe([])->and(JobPosting::count())->toBe($before);
    Log::shouldNotHaveReceived('warning');
    Log::shouldNotHaveReceived('error');
});

it('does not follow the cursor: one page per tier, however much more is available', function () {
    $sent = jbcRunFake();
    jbcRun();

    expect($sent)->toHaveCount(2);
    foreach ($sent as $request) {
        expect($request->url())->not->toContain('cursor');
    }
});
