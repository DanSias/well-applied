<?php

use App\Enums\DiscoveryStatus;
use App\Enums\JobDiscoverySource;
use App\Enums\JobRemoteStatus;
use App\Models\CareerProfile;
use App\Models\DiscoveryProviderAttempt;
use App\Models\JobPosting;
use App\Support\JobDiscovery\DiscoveryScheduleIsDue;
use App\Support\JobDiscovery\Providers\DiscoveryProviderException;
use App\Support\JobDiscovery\Providers\Eightfold\EightfoldTenant;
use App\Support\JobDiscovery\Providers\EightfoldDiscoveryProvider;
use App\Support\JobDiscovery\Providers\ProviderDiscoveryContext;
use App\Support\JobDiscovery\RunJobDiscovery;
use App\Support\JobDiscovery\SearchProfile;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/*
 * All responses come from tests/Fixtures/eightfold/ — recorded from the
 * live public endpoints (positions verbatim; bulky unused fields removed;
 * the Starbucks description truncated). No test touches the network:
 * TestCase::createApplication() calls Http::preventStrayRequests().
 */

const LOCKHEED_HOST = 'lockheedmartin.eightfold.ai';
const NVIDIA_HOST = 'nvidia.eightfold.ai';
const STARBUCKS_HOST = 'starbucks.eightfold.ai';

function fx(string $name): array
{
    return json_decode(file_get_contents(base_path("tests/Fixtures/eightfold/{$name}.json")), true, flags: JSON_THROW_ON_ERROR);
}

function efProfile(array $overrides = []): SearchProfile
{
    return new SearchProfile(...array_merge([
        'identifier' => 'test',
        'label' => 'Test',
        'includeTerms' => ['software engineer'],
        'excludeTerms' => ['intern'],
        'preferredSeniority' => null,
        'remotePreference' => 'any',
        'allowedLocations' => [],
        'employmentType' => null,
        'description' => null,
    ], $overrides));
}

function efContext(array $profile = [], ?int $max = null): ProviderDiscoveryContext
{
    return new ProviderDiscoveryContext(efProfile($profile), $max);
}

/** Search response containing the given rows. */
function efSearch(array $rows, ?int $count = null): array
{
    return ['status' => 200, 'error' => ['message' => '', 'body' => ''], 'data' => ['positions' => array_values($rows), 'count' => $count ?? count($rows)]];
}

function efRow(array $template, int $id, array $overrides = []): array
{
    return array_merge($template, ['id' => $id, 'positionUrl' => "/careers/job/{$id}"], $overrides);
}

/** A detail response for $id, derived from a recorded one. */
function efDetail(array $recorded, int $id, array $overrides = []): array
{
    $recorded['data'] = array_merge($recorded['data'], ['id' => $id], $overrides);

    return $recorded;
}

/**
 * Route fake responses by host. A handler is a callable (Request $r, array $query): mixed
 * returning a PromiseInterface/Response, an array (JSON 200) or an int (bare status).
 */
function efFake(array $hosts): void
{
    Http::fake(function (Request $request) use ($hosts) {
        $parts = parse_url($request->url());
        parse_str($parts['query'] ?? '', $query);
        $handler = $hosts[$parts['host']] ?? null;
        if ($handler === null) {
            return Http::response('unexpected host', 599);
        }
        $result = $handler($request, $query, $parts['path']);

        return match (true) {
            is_array($result) => Http::response($result),
            is_int($result) => Http::response('', $result),
            default => $result,
        };
    });
}

/** Handler serving fixed search rows for any query and details by id. */
function efTenant(array $searchRows, array $detailsById = [], ?Closure $onDetail = null): Closure
{
    return function (Request $r, array $q, string $path) use ($searchRows, $detailsById, $onDetail) {
        if (str_ends_with($path, '/search')) {
            return efSearch($searchRows);
        }
        $id = (int) $q['position_id'];
        if ($onDetail !== null && ($override = $onDetail($id, $q)) !== null) {
            return $override;
        }

        return $detailsById[$id] ?? Http::response('', 404);
    };
}

function efOnly(string ...$slugs): void
{
    foreach (['lockheed', 'nvidia', 'starbucks', 'microsoft'] as $slug) {
        config(["job_discovery.eightfold.tenants.{$slug}.enabled" => in_array($slug, $slugs, true)]);
    }
}

function efRequests(?string $host = null, ?string $pathSuffix = null): array
{
    return Http::recorded()
        ->map(fn ($pair) => $pair[0])
        ->filter(fn (Request $r) => ($host === null || parse_url($r->url(), PHP_URL_HOST) === $host)
            && ($pathSuffix === null || str_ends_with(parse_url($r->url(), PHP_URL_PATH), $pathSuffix)))
        ->values()->all();
}

beforeEach(function () {
    Sleep::fake();
    Cache::flush();
    config(['job_discovery.eightfold.enabled' => true]);
});

// ---------------------------------------------------------------------------
// Normalization
// ---------------------------------------------------------------------------

it('normalizes an on-site Lockheed position with a single-location salary', function () {
    efOnly('lockheed');
    $search = fx('lockheed_search');
    $row = $search['data']['positions'][0]; // Full Stack Engineer Sr Stf - Orlando, on-site
    efFake([LOCKHEED_HOST => efTenant([$row], [996476208128 => fx('lockheed_detail_996476208128')])]);

    $provider = new EightfoldDiscoveryProvider;
    $candidates = $provider->retrieve(efContext(['includeTerms' => ['full stack']]));

    expect($candidates)->toHaveCount(1);
    $c = $candidates[0];
    expect($c->source)->toBe(JobDiscoverySource::Eightfold)
        ->and($c->sourceJobId)->toBe('lockheed:996476208128')
        ->and($c->company)->toBe('Lockheed Martin')
        ->and($c->title)->toBe('Full Stack Engineer Sr Stf - E5')
        ->and($c->remoteStatus)->toBe(JobRemoteStatus::Onsite)
        ->and($c->location)->toBe('Orlando, FL, US')
        ->and($c->applicationUrl)->toBe('https://lockheedmartin.eightfold.ai/careers/job/996476208128')
        ->and($c->description)->toContain('Basic Qualifications')->not->toContain('<div')
        ->and($c->compensationMin)->toBe(148300)
        ->and($c->compensationMax)->toBe(275500)
        ->and($c->compensationCurrency)->toBe('USD')
        ->and($c->compensationInterval)->toBe('year')
        ->and($c->postedAt?->getTimestamp())->toBe(1786687200)
        ->and($c->sourceMetadata['tenant'])->toBe('lockheed')
        ->and($c->sourceMetadata['positionId'])->toBe('996476208128')
        ->and($c->sourceMetadata['salaryNormalization']['status'])->toBe('normalized')
        ->and($c->sourceMetadata['customFields']['efcustomTextFinalclearancefortherole'])->toBe('Secret')
        ->and($c->sourceMetadata)->not->toHaveKey('remoteEligibility');
});

it('uses the API-returned public URL, which can be on a different host', function () {
    efOnly('starbucks');
    $row = fx('starbucks_search')['data']['positions'][0];
    efFake([STARBUCKS_HOST => efTenant([$row], [481078371802 => fx('starbucks_detail')])]);

    $c = (new EightfoldDiscoveryProvider)->retrieve(efContext())[0];

    expect($c->applicationUrl)->toBe('https://apply.starbucks.com/careers/job/481078371802')
        ->and($c->sourceJobId)->toBe('starbucks:481078371802');
});

it('leaves the URL empty rather than constructing one when the API gives none', function () {
    efOnly('starbucks');
    $row = fx('starbucks_search')['data']['positions'][0];
    $detail = fx('starbucks_detail');
    unset($detail['data']['publicUrl']);
    efFake([STARBUCKS_HOST => efTenant([$row], [481078371802 => $detail])]);

    expect((new EightfoldDiscoveryProvider)->retrieve(efContext())[0]->applicationUrl)->toBeNull();
});

// ---------------------------------------------------------------------------
// Remote / location handling
// ---------------------------------------------------------------------------

it('treats a multi-location remote listing as remote but never as a Florida restriction', function () {
    efOnly('lockheed');
    $row = fx('lockheed_search')['data']['positions'][1]; // Software Engineer Stf - Enterprise IT, remote_local
    efFake([LOCKHEED_HOST => efTenant([$row], [996476391868 => fx('lockheed_detail_996476391868')])]);

    $c = (new EightfoldDiscoveryProvider)->retrieve(efContext())[0];

    expect($c->remoteStatus)->toBe(JobRemoteStatus::Remote)
        ->and($c->sourceMetadata['remoteEligibility'])->toBe('listed_states_not_confirmed_as_restriction')
        ->and($c->sourceMetadata['floridaListed'])->toBeTrue()
        ->and($c->sourceMetadata['listedStates'])->toContain('FL', 'MD', 'CA');
});

it('keeps a US remote listing that does not list Florida, preserving the uncertainty', function () {
    efOnly('nvidia');
    $row = collect(fx('nvidia_search')['data']['positions'])->firstWhere('id', 893397676085); // CA, TX, NC
    efFake([NVIDIA_HOST => efTenant([$row], [893397676085 => fx('nvidia_detail_us')])]);

    $c = (new EightfoldDiscoveryProvider)->retrieve(efContext(['remotePreference' => 'remote_us']))[0];

    expect($c->remoteStatus)->toBe(JobRemoteStatus::Remote)
        ->and($c->sourceMetadata['floridaListed'])->toBeFalse()
        ->and($c->sourceMetadata['remoteEligibility'])->toBe('listed_states_not_confirmed_as_restriction')
        ->and($c->sourceMetadata['listedStates'])->toContain('CA', 'NC', 'TX')->not->toContain('FL');
});

it('renders state-scoped virtual slots as Remote (ST) and bounds a very long location list', function () {
    efOnly('lockheed');
    $row = fx('lockheed_search')['data']['positions'][2]; // Agentic Ontology: ~150 virtual locations
    efFake([LOCKHEED_HOST => efTenant([$row], [996476482699 => fx('lockheed_detail_996476482699')])]);

    $c = (new EightfoldDiscoveryProvider)->retrieve(efContext(['includeTerms' => ['software engineer']]))[0];

    expect($c->remoteStatus)->toBe(JobRemoteStatus::Remote)
        ->and($c->location)->toContain('Remote (AK)')->toContain('more)')
        ->and(mb_strlen($c->location))->toBeLessThan(200)
        ->and($c->sourceMetadata['floridaListed'])->toBeTrue()
        ->and(count($c->sourceMetadata['locations']))->toBeLessThanOrEqual(20);
});

it('leaves remote status unknown when a remote_local listing names no location at all', function () {
    efOnly('nvidia');
    $row = collect(fx('nvidia_search')['data']['positions'])->firstWhere('id', 893397676085);
    $row['standardizedLocations'] = [];
    $row['locations'] = [];
    $detail = efDetail(fx('nvidia_detail_us'), 893397676085, ['standardizedLocations' => [], 'locations' => []]);
    efFake([NVIDIA_HOST => efTenant([$row], [893397676085 => $detail])]);

    $c = (new EightfoldDiscoveryProvider)->retrieve(efContext())[0];

    expect($c->remoteStatus)->toBeNull()
        ->and($c->location)->toBeNull()
        ->and($c->sourceMetadata['remoteEligibility'])->toBe('unspecified');
});

it('does not call a remote_local listing remote when every listed location is outside the US', function () {
    efOnly('nvidia');
    $row = collect(fx('nvidia_search')['data']['positions'])->firstWhere('id', 893397661414); // Bengaluru / IN
    efFake([NVIDIA_HOST => efTenant([$row], [893397661414 => fx('nvidia_detail_in')])]);

    // With no US requirement the row is fetched, but still never mapped as Remote.
    $c = (new EightfoldDiscoveryProvider)->retrieve(efContext(['remotePreference' => 'any', 'allowedLocations' => []]))[0];

    expect($c->remoteStatus)->toBeNull()
        ->and($c->sourceMetadata['remoteEligibility'])->toBe('unspecified');
});

// ---------------------------------------------------------------------------
// Salary
// ---------------------------------------------------------------------------

it('does not collapse a multi-location, multi-geo-zone listing into one salary', function () {
    efOnly('lockheed');
    $row = fx('lockheed_search')['data']['positions'][1];
    efFake([LOCKHEED_HOST => efTenant([$row], [996476391868 => fx('lockheed_detail_996476391868')])]);

    $c = (new EightfoldDiscoveryProvider)->retrieve(efContext())[0];

    expect($c->compensationMin)->toBeNull()
        ->and($c->compensationMax)->toBeNull()
        ->and($c->compensationCurrency)->toBeNull()
        ->and($c->sourceMetadata['salaryNormalization']['status'])->toBe('multiple_locations')
        ->and($c->sourceMetadata['salaryNormalization']['raw'])->toBe(['$135,700.00 - $251,900.00'])
        ->and($c->sourceMetadata['customFields']['efcustomTextCustpayrange'])->toBe('$135,700.00 - $251,900.00');
});

it('does not normalize a single-location listing whose description carries several geo-zone ranges', function () {
    efOnly('lockheed');
    $row = fx('lockheed_search')['data']['positions'][1];
    $row['standardizedLocations'] = ['Orlando, FL, US'];
    $detail = efDetail(fx('lockheed_detail_996476391868'), 996476391868, ['standardizedLocations' => ['Orlando, FL, US']]);
    efFake([LOCKHEED_HOST => efTenant([$row], [996476391868 => $detail])]);

    $c = (new EightfoldDiscoveryProvider)->retrieve(efContext())[0];

    expect($c->compensationMin)->toBeNull()
        ->and($c->sourceMetadata['salaryNormalization']['status'])->toBe('multiple_geographic_ranges');
});

it('does not trust a non-dollar or non-annual Lockheed figure', function (string $raw, string $status) {
    efOnly('lockheed');
    $row = fx('lockheed_search')['data']['positions'][0];
    $detail = efDetail(fx('lockheed_detail_996476208128'), 996476208128, ['efcustomTextCustpayrange' => [$raw]]);
    efFake([LOCKHEED_HOST => efTenant([$row], [996476208128 => $detail])]);

    $c = (new EightfoldDiscoveryProvider)->retrieve(efContext(['includeTerms' => ['full stack']]))[0];

    expect($c->compensationMin)->toBeNull()
        ->and($c->sourceMetadata['salaryNormalization']['status'])->toBe($status)
        ->and($c->sourceMetadata['salaryNormalization']['raw'])->toBe([$raw]);
})->with([
    'pounds' => ['£58,000 - £71,500', 'unparseable'],
    'hourly' => ['$45.00 - $62.00', 'implausible_for_declared_interval'],
    'free text' => ['Competitive', 'unparseable'],
]);

it('records no normalization state when a Lockheed listing has no pay field', function () {
    efOnly('lockheed');
    $row = fx('lockheed_search')['data']['positions'][0];
    $detail = efDetail(fx('lockheed_detail_996476208128'), 996476208128, ['efcustomTextCustpayrange' => []]);
    efFake([LOCKHEED_HOST => efTenant([$row], [996476208128 => $detail])]);

    $c = (new EightfoldDiscoveryProvider)->retrieve(efContext(['includeTerms' => ['full stack']]))[0];

    expect($c->compensationMin)->toBeNull()
        ->and($c->sourceMetadata['salaryNormalization'])->toBe(['status' => 'no_value']);
});

it('preserves but never normalizes pay fields on tenants whose pay semantics are unknown', function () {
    efOnly('starbucks');
    $row = fx('starbucks_search')['data']['positions'][0];
    efFake([STARBUCKS_HOST => efTenant([$row], [481078371802 => fx('starbucks_detail')])]);

    $c = (new EightfoldDiscoveryProvider)->retrieve(efContext())[0];

    expect($c->compensationMin)->toBeNull()
        ->and($c->compensationMax)->toBeNull()
        ->and($c->sourceMetadata['customFields']['efcustomTextPayRangenonretail'])->toBe('127000-211000')
        ->and($c->sourceMetadata)->not->toHaveKey('salaryNormalization');
});

// ---------------------------------------------------------------------------
// Filtering before detail retrieval
// ---------------------------------------------------------------------------

it('fetches descriptions only for positions that survive the search-row filters', function () {
    efOnly('lockheed');
    $rows = fx('lockheed_search')['data']['positions'];
    // [0] Full Stack on-site Orlando  [1] Software Engineer remote  [2] Agentic Ontology remote
    // [3] Data Analyst Sr (title mismatch)  [4] Senior Software Engineer, Gloucester GB (non-US)
    efFake([LOCKHEED_HOST => efTenant($rows, [
        996476391868 => fx('lockheed_detail_996476391868'),
        996476482699 => fx('lockheed_detail_996476482699'),
    ])]);

    $provider = new EightfoldDiscoveryProvider;
    $candidates = $provider->retrieve(efContext(['includeTerms' => ['software engineer', 'full stack'], 'remotePreference' => 'remote_us', 'allowedLocations' => ['United States', 'US']]));

    $fetched = collect(efRequests(LOCKHEED_HOST, '/position_details'))->map(fn ($r) => $r->data()['position_id'] ?? null)->sort()->values()->all();
    expect($fetched)->toBe(['996476391868', '996476482699'])
        ->and(collect($candidates)->pluck('sourceJobId')->sort()->values()->all())->toBe(['lockheed:996476391868', 'lockheed:996476482699']);

    $d = $provider->diagnostics()['lockheed'];
    expect($d['dropped_before_details'])->toBe(['work_arrangement' => 1, 'title_not_matching' => 1, 'non_us' => 1])
        ->and($d['plausible'])->toBe(2)
        ->and($d['detail_requests'])->toBe(2)
        ->and($d['unique_positions'])->toBe(5);
});

it('applies exclude terms to the title before fetching details', function () {
    efOnly('lockheed');
    $row = efRow(fx('lockheed_search')['data']['positions'][1], 1, ['name' => 'Software Engineer Intern']);
    efFake([LOCKHEED_HOST => efTenant([$row])]);

    $provider = new EightfoldDiscoveryProvider;

    expect($provider->retrieve(efContext()))->toBe([])
        ->and(efRequests(LOCKHEED_HOST, '/position_details'))->toBe([])
        ->and($provider->diagnostics()['lockheed']['dropped_before_details'])->toBe(['excluded_term' => 1]);
});

it('does not drop a row just because the search data is missing information', function () {
    efOnly('lockheed');
    $row = efRow(fx('lockheed_search')['data']['positions'][1], 7);
    unset($row['standardizedLocations'], $row['locations'], $row['workLocationOption']);
    efFake([LOCKHEED_HOST => efTenant([$row], [7 => efDetail(fx('lockheed_detail_996476391868'), 7, ['standardizedLocations' => [], 'locations' => []])])]);

    $provider = new EightfoldDiscoveryProvider;

    expect($provider->retrieve(efContext(['remotePreference' => 'remote_us', 'allowedLocations' => ['US']])))->toHaveCount(1);
});

it('skips malformed rows without failing the tenant', function () {
    efOnly('lockheed');
    $good = efRow(fx('lockheed_search')['data']['positions'][1], 9);
    efFake([LOCKHEED_HOST => efTenant([['name' => 'No id'], ['id' => 5], $good], [9 => efDetail(fx('lockheed_detail_996476391868'), 9)])]);

    $provider = new EightfoldDiscoveryProvider;

    expect($provider->retrieve(efContext()))->toHaveCount(1)
        ->and($provider->diagnostics()['lockheed']['dropped_before_details']['malformed_row'])->toBe(2)
        ->and($provider->partialFailureSummary())->toBeNull();
});

// ---------------------------------------------------------------------------
// Search request shape, pagination and caps
// ---------------------------------------------------------------------------

it('sends the tenant domain, query, offset and a descriptive User-Agent', function () {
    efOnly('starbucks');
    efFake([STARBUCKS_HOST => efTenant([])]);

    (new EightfoldDiscoveryProvider)->retrieve(efContext(['includeTerms' => ['full stack']]));

    $request = efRequests(STARBUCKS_HOST, '/search')[0];
    expect($request->data())->toMatchArray(['domain' => 'starbucks.com', 'query' => 'full stack', 'start' => 0, 'sort_by' => 'relevance'])
        ->and($request->header('User-Agent')[0])->toContain('career-toolkit')
        ->and(parse_url($request->url(), PHP_URL_HOST))->toBe(STARBUCKS_HOST);
});

it('falls back to the software_engineering profile when no profile is given', function () {
    efOnly('starbucks');
    efFake([STARBUCKS_HOST => efTenant([])]);

    (new EightfoldDiscoveryProvider)->retrieve();

    expect(efRequests(STARBUCKS_HOST, '/search')[0]->data()['query'])->toBe('software engineer');
});

it('pages through results within the page cap and stops on a short page', function () {
    efOnly('nvidia');
    config(['job_discovery.eightfold.tenants.nvidia.budget' => ['max_pages_per_query' => 3, 'max_search_requests' => 10]]);
    $template = fx('nvidia_search')['data']['positions'][2];
    $pages = [
        0 => array_map(fn ($i) => efRow($template, 1000 + $i), range(0, 9)),
        10 => array_map(fn ($i) => efRow($template, 2000 + $i), range(0, 3)), // short page: stop
    ];
    efFake([NVIDIA_HOST => function (Request $r, array $q, string $path) use ($pages) {
        return str_ends_with($path, '/search') ? efSearch($pages[(int) $q['start']] ?? [], 14) : efDetail(fx('nvidia_detail_us'), (int) $q['position_id']);
    }]);

    $provider = new EightfoldDiscoveryProvider;
    $candidates = $provider->retrieve(efContext(['includeTerms' => ['software engineer']]));

    expect(efRequests(NVIDIA_HOST, '/search'))->toHaveCount(2)
        ->and($candidates)->toHaveCount(14)
        ->and($provider->diagnostics()['nvidia']['search_rows'])->toBe(14);
});

it('never exceeds the per-query page cap even when more results exist', function () {
    efOnly('nvidia');
    config(['job_discovery.eightfold.tenants.nvidia.budget' => ['max_pages_per_query' => 2, 'max_search_requests' => 50]]);
    $template = fx('nvidia_search')['data']['positions'][2];
    efFake([NVIDIA_HOST => function (Request $r, array $q, string $path) use ($template) {
        $start = (int) ($q['start'] ?? 0);

        return str_ends_with($path, '/search')
            ? efSearch(array_map(fn ($i) => efRow($template, 3000 + $start + $i), range(0, 9)), 2000)
            : efDetail(fx('nvidia_detail_us'), (int) $q['position_id']);
    }]);

    (new EightfoldDiscoveryProvider)->retrieve(efContext(['includeTerms' => ['software engineer']]));

    expect(efRequests(NVIDIA_HOST, '/search'))->toHaveCount(2);
});

it('stops querying when the per-tenant search budget is spent and reports the skipped queries', function () {
    efOnly('nvidia');
    config(['job_discovery.eightfold.tenants.nvidia.budget' => ['max_pages_per_query' => 1, 'max_search_requests' => 2]]);
    efFake([NVIDIA_HOST => efTenant([])]);

    $provider = new EightfoldDiscoveryProvider;
    $provider->retrieve(efContext(['includeTerms' => ['a engineer', 'b engineer', 'c engineer', 'd engineer']]));

    $d = $provider->diagnostics()['nvidia'];
    expect(efRequests(NVIDIA_HOST, '/search'))->toHaveCount(2)
        ->and($d['queries_run'])->toBe(2)
        ->and($d['queries_skipped_by_budget'])->toBe(2);
});

it('caps results by maxResults, shared across tenants', function () {
    efOnly('nvidia', 'starbucks');
    $template = fx('nvidia_search')['data']['positions'][2];
    $rows = array_map(fn ($i) => efRow($template, 4000 + $i), range(0, 7));
    $detail = fn (int $id) => efDetail(fx('nvidia_detail_us'), $id);
    $handler = fn () => function (Request $r, array $q, string $path) use ($rows, $detail) {
        return str_ends_with($path, '/search') ? efSearch($rows) : $detail((int) $q['position_id']);
    };
    efFake([NVIDIA_HOST => $handler(), STARBUCKS_HOST => $handler()]);

    $candidates = (new EightfoldDiscoveryProvider)->retrieve(efContext(max: 6));

    expect($candidates)->toHaveCount(6)
        ->and(collect($candidates)->groupBy(fn ($c) => $c->sourceMetadata['tenant'])->map->count()->all())->toBe(['nvidia' => 3, 'starbucks' => 3])
        ->and(efRequests(NVIDIA_HOST, '/position_details'))->toHaveCount(3);
});

it('caps description fetches per tenant and reports what the budget skipped', function () {
    efOnly('nvidia');
    config(['job_discovery.eightfold.tenants.nvidia.budget' => ['max_detail_requests' => 2]]);
    $template = fx('nvidia_search')['data']['positions'][2];
    $rows = array_map(fn ($i) => efRow($template, 5000 + $i), range(0, 4));
    efFake([NVIDIA_HOST => function (Request $r, array $q, string $path) use ($rows) {
        return str_ends_with($path, '/search') ? efSearch($rows) : efDetail(fx('nvidia_detail_us'), (int) $q['position_id']);
    }]);

    $provider = new EightfoldDiscoveryProvider;
    $candidates = $provider->retrieve(efContext());

    expect($candidates)->toHaveCount(2)
        ->and($provider->diagnostics()['nvidia']['details_skipped_by_budget'])->toBe(3)
        ->and(efRequests(NVIDIA_HOST, '/position_details'))->toHaveCount(2);
});

// ---------------------------------------------------------------------------
// Deduplication and caching
// ---------------------------------------------------------------------------

it('scopes source IDs by tenant so equal position IDs never collide', function () {
    efOnly('nvidia', 'starbucks');
    $nvRow = efRow(fx('nvidia_search')['data']['positions'][2], 424242);
    $sbRow = efRow(fx('starbucks_search')['data']['positions'][0], 424242);
    efFake([
        NVIDIA_HOST => efTenant([$nvRow], [424242 => efDetail(fx('nvidia_detail_us'), 424242)]),
        STARBUCKS_HOST => efTenant([$sbRow], [424242 => efDetail(fx('starbucks_detail'), 424242)]),
    ]);

    $ids = collect((new EightfoldDiscoveryProvider)->retrieve(efContext()))->pluck('sourceJobId')->sort()->values()->all();

    expect($ids)->toBe(['nvidia:424242', 'starbucks:424242']);
});

it('returns a position once even when several queries surface it', function () {
    efOnly('nvidia');
    $row = efRow(fx('nvidia_search')['data']['positions'][2], 8);
    efFake([NVIDIA_HOST => efTenant([$row], [8 => efDetail(fx('nvidia_detail_us'), 8)])]);

    $provider = new EightfoldDiscoveryProvider;
    $candidates = $provider->retrieve(efContext(['includeTerms' => ['software engineer', 'system software engineer', 'senior']]));

    expect($candidates)->toHaveCount(1)
        ->and(efRequests(NVIDIA_HOST, '/position_details'))->toHaveCount(1)
        ->and($provider->diagnostics()['nvidia']['search_rows'])->toBe(3)
        ->and($provider->diagnostics()['nvidia']['unique_positions'])->toBe(1);
});

it('caches details per tenant, position and posting version', function () {
    efOnly('nvidia');
    $posted = 1000;
    efFake([NVIDIA_HOST => function (Request $r, array $q, string $path) use (&$posted) {
        return str_ends_with($path, '/search')
            ? efSearch([efRow(fx('nvidia_search')['data']['positions'][2], 8, ['postedTs' => $posted])])
            : efDetail(fx('nvidia_detail_us'), 8);
    }]);
    $provider = new EightfoldDiscoveryProvider;

    $provider->retrieve(efContext());
    $second = $provider->retrieve(efContext());

    expect(efRequests(NVIDIA_HOST, '/position_details'))->toHaveCount(1)
        ->and($second)->toHaveCount(1)
        ->and($provider->diagnostics()['nvidia']['details_from_cache'])->toBe(1)
        ->and($provider->diagnostics()['nvidia']['detail_requests'])->toBe(0);

    // A changed posted timestamp is a new version: fetched again.
    $posted = 2000;
    $provider->retrieve(efContext());

    expect(efRequests(NVIDIA_HOST, '/position_details'))->toHaveCount(2);
});

// ---------------------------------------------------------------------------
// Errors, retries and rate limits
// ---------------------------------------------------------------------------

it('retries a 429 and honours Retry-After', function () {
    efOnly('starbucks');
    $row = fx('starbucks_search')['data']['positions'][0];
    $calls = 0;
    efFake([STARBUCKS_HOST => function (Request $r, array $q, string $path) use ($row, &$calls) {
        if (str_ends_with($path, '/search') && $calls++ === 0) {
            return Http::response('Please try again later', 429, ['Retry-After' => '7']);
        }

        return str_ends_with($path, '/search') ? efSearch([$row]) : fx('starbucks_detail');
    }]);

    $provider = new EightfoldDiscoveryProvider;
    $candidates = $provider->retrieve(efContext());

    expect($candidates)->toHaveCount(1);
    Sleep::assertSlept(fn ($duration) => (int) $duration->totalSeconds === 7, 1);
    $d = $provider->diagnostics()['starbucks'];
    expect($d['retries'])->toBe(1)->and($d['rate_limited_responses'])->toBe(1)->and($d['status'])->toBe('ok');
});

it('backs off exponentially without Retry-After and gives up after the attempt budget', function () {
    efOnly('starbucks');
    efFake([STARBUCKS_HOST => fn () => Http::response('Please try again later', 429)]);

    $provider = new EightfoldDiscoveryProvider;

    expect(fn () => $provider->retrieve(efContext()))->toThrow(DiscoveryProviderException::class);
    $d = $provider->diagnostics()['starbucks'];
    expect(efRequests(STARBUCKS_HOST))->toHaveCount(3)
        ->and($d['status'])->toBe('failed')
        ->and($d['failure_category'])->toBe('rate_limited')
        ->and($d['rate_limited_responses'])->toBe(3);
    Sleep::assertSlept(fn ($duration) => (int) $duration->totalSeconds === 2, 1);
    Sleep::assertSlept(fn ($duration) => (int) $duration->totalSeconds === 4, 1);
});

it('does not wait out a Retry-After beyond the ceiling', function () {
    efOnly('starbucks');
    efFake([STARBUCKS_HOST => fn () => Http::response('Please try again later', 429, ['Retry-After' => '3600'])]);

    $provider = new EightfoldDiscoveryProvider;

    expect(fn () => $provider->retrieve(efContext()))->toThrow(DiscoveryProviderException::class, 'exceeds');
    expect(efRequests(STARBUCKS_HOST))->toHaveCount(1);
    Sleep::assertSlept(fn ($duration) => $duration->totalSeconds >= 60, 0);
});

it('retries transient 5xx errors and recovers', function () {
    efOnly('starbucks');
    $row = fx('starbucks_search')['data']['positions'][0];
    $calls = 0;
    efFake([STARBUCKS_HOST => function (Request $r, array $q, string $path) use ($row, &$calls) {
        if (str_ends_with($path, '/search') && $calls++ < 2) {
            return 503;
        }

        return str_ends_with($path, '/search') ? efSearch([$row]) : fx('starbucks_detail');
    }]);

    $provider = new EightfoldDiscoveryProvider;

    expect($provider->retrieve(efContext()))->toHaveCount(1)
        ->and($provider->diagnostics()['starbucks']['retries'])->toBe(2);
});

it('classifies a persistent 5xx as a server error after bounded retries', function () {
    efOnly('starbucks');
    efFake([STARBUCKS_HOST => fn () => 500]);
    $provider = new EightfoldDiscoveryProvider;

    expect(fn () => $provider->retrieve(efContext()))->toThrow(DiscoveryProviderException::class);
    expect($provider->diagnostics()['starbucks']['failure_category'])->toBe('server_error')
        ->and(efRequests(STARBUCKS_HOST))->toHaveCount(3);
});

it('does not retry a tenant that has switched the public API off', function () {
    efOnly('nvidia');
    efFake([NVIDIA_HOST => fn () => Http::response('{"message": "PCSX is not enabled for this user."}', 403)]);
    $provider = new EightfoldDiscoveryProvider;

    expect(fn () => $provider->retrieve(efContext()))->toThrow(DiscoveryProviderException::class, 'tenant_disabled');
    expect(efRequests(NVIDIA_HOST))->toHaveCount(1)
        ->and($provider->diagnostics()['nvidia']['failure_category'])->toBe('tenant_disabled');
    Sleep::assertInsomniac();
});

it('does not retry a plain 403', function () {
    efOnly('nvidia');
    efFake([NVIDIA_HOST => fn () => 403]);
    $provider = new EightfoldDiscoveryProvider;

    expect(fn () => $provider->retrieve(efContext()))->toThrow(DiscoveryProviderException::class);
    expect(efRequests(NVIDIA_HOST))->toHaveCount(1)
        ->and($provider->diagnostics()['nvidia']['failure_category'])->toBe('forbidden');
});

it('reports a missing domain (422) as a configuration problem without retrying', function () {
    efOnly('nvidia');
    efFake([NVIDIA_HOST => fn () => Http::response(['messages' => ['domain' => ['Missing data for required field.']]], 422)]);
    $provider = new EightfoldDiscoveryProvider;

    expect(fn () => $provider->retrieve(efContext()))->toThrow(DiscoveryProviderException::class);
    expect($provider->diagnostics()['nvidia']['failure_category'])->toBe('domain_rejected')
        ->and(efRequests(NVIDIA_HOST))->toHaveCount(1);
});

it('rejects the HTML app shell Eightfold returns for an unknown domain', function () {
    efOnly('nvidia');
    efFake([NVIDIA_HOST => fn () => Http::response('<!DOCTYPE html><html></html>', 200, ['Content-Type' => 'text/html; charset=utf-8'])]);
    $provider = new EightfoldDiscoveryProvider;

    expect(fn () => $provider->retrieve(efContext()))->toThrow(DiscoveryProviderException::class, 'invalid_response');
    expect($provider->diagnostics()['nvidia']['failure_message'])->toContain('text/html')->toContain('domain')
        ->and(efRequests(NVIDIA_HOST))->toHaveCount(1);
});

it('rejects an incorrect content type even when the body happens to be JSON', function () {
    efOnly('nvidia');
    efFake([NVIDIA_HOST => fn () => Http::response(json_encode(efSearch([])), 200, ['Content-Type' => 'text/plain'])]);
    $provider = new EightfoldDiscoveryProvider;

    expect(fn () => $provider->retrieve(efContext()))->toThrow(DiscoveryProviderException::class);
    expect($provider->diagnostics()['nvidia']['failure_category'])->toBe('invalid_response');
});

it('rejects JSON of an unexpected shape', function (mixed $body) {
    efOnly('nvidia');
    efFake([NVIDIA_HOST => fn () => $body]);
    $provider = new EightfoldDiscoveryProvider;

    expect(fn () => $provider->retrieve(efContext()))->toThrow(DiscoveryProviderException::class);
    expect($provider->diagnostics()['nvidia']['failure_category'])->toBe('invalid_response');
})->with([
    'no data' => [['status' => 200]],
    'no positions' => [['data' => ['count' => 3]]],
    'positions not a list' => [['data' => ['positions' => ['a' => 1]]]],
]);

// ---------------------------------------------------------------------------
// Tenant isolation, partial success and reporting
// ---------------------------------------------------------------------------

it('keeps one tenant failing from costing the others their results, and says so', function () {
    efOnly('lockheed', 'nvidia', 'starbucks');
    efFake([
        LOCKHEED_HOST => efTenant([fx('lockheed_search')['data']['positions'][1]], [996476391868 => fx('lockheed_detail_996476391868')]),
        NVIDIA_HOST => fn () => Http::response('Please try again later', 429),
        STARBUCKS_HOST => efTenant([fx('starbucks_search')['data']['positions'][0]], [481078371802 => fx('starbucks_detail')]),
    ]);

    $provider = new EightfoldDiscoveryProvider;
    $candidates = $provider->retrieve(efContext());

    expect(collect($candidates)->pluck('sourceJobId')->sort()->values()->all())->toBe(['lockheed:996476391868', 'starbucks:481078371802']);
    $d = $provider->diagnostics();
    expect($d['lockheed']['status'])->toBe('ok')
        ->and($d['nvidia']['status'])->toBe('failed')
        ->and($d['starbucks']['status'])->toBe('ok');
    expect($provider->partialFailureSummary())->toContain('nvidia failed')->toContain('rate_limited')
        ->not->toContain('lockheed')->not->toContain('starbucks');
});

it('reports a fully healthy run as healthy', function () {
    efOnly('starbucks');
    efFake([STARBUCKS_HOST => efTenant([fx('starbucks_search')['data']['positions'][0]], [481078371802 => fx('starbucks_detail')])]);
    $provider = new EightfoldDiscoveryProvider;

    $provider->retrieve(efContext());

    expect($provider->partialFailureSummary())->toBeNull();
});

it('keeps results gathered before a tenant failed mid-run, and flags it', function () {
    efOnly('nvidia');
    $template = fx('nvidia_search')['data']['positions'][2];
    $rows = array_map(fn ($i) => efRow($template, 6000 + $i), range(0, 3));
    efFake([NVIDIA_HOST => efTenant($rows, [], function (int $id) {
        return $id === 6000 ? efDetail(fx('nvidia_detail_us'), $id) : Http::response('Please try again later', 429);
    })]);

    $provider = new EightfoldDiscoveryProvider;
    $candidates = $provider->retrieve(efContext());

    expect($candidates)->toHaveCount(1)
        ->and($provider->diagnostics()['nvidia']['status'])->toBe('failed')
        ->and($provider->partialFailureSummary())->toContain('nvidia failed');
});

it('throws only when every tenant failed and nothing was retrieved', function () {
    efOnly('lockheed', 'nvidia');
    efFake([
        LOCKHEED_HOST => fn () => 500,
        NVIDIA_HOST => fn () => Http::response('{"message": "PCSX is not enabled for this user."}', 403),
    ]);

    $provider = new EightfoldDiscoveryProvider;

    expect(fn () => $provider->retrieve(efContext()))
        ->toThrow(DiscoveryProviderException::class, 'lockheed failed');
    expect($provider->diagnostics())->toHaveKeys(['lockheed', 'nvidia']);
});

it('skips a single missing position but marks the tenant degraded', function () {
    efOnly('nvidia');
    $template = fx('nvidia_search')['data']['positions'][2];
    $rows = array_map(fn ($i) => efRow($template, 7000 + $i), range(0, 2));
    efFake([NVIDIA_HOST => efTenant($rows, [], function (int $id) {
        return $id === 7001 ? Http::response('', 404) : efDetail(fx('nvidia_detail_us'), $id);
    })]);

    $provider = new EightfoldDiscoveryProvider;
    $candidates = $provider->retrieve(efContext());

    expect($candidates)->toHaveCount(2)
        ->and($provider->diagnostics()['nvidia']['status'])->toBe('degraded')
        ->and($provider->diagnostics()['nvidia']['detail_failures'])->toBe(1)
        ->and($provider->partialFailureSummary())->toContain('nvidia degraded')->toContain('1 position skipped');
});

it('isolates an unexpected error on one tenant without exposing its message', function () {
    efOnly('lockheed', 'starbucks');
    efFake([
        LOCKHEED_HOST => efTenant([fx('lockheed_search')['data']['positions'][1]], [996476391868 => fx('lockheed_detail_996476391868')]),
        STARBUCKS_HOST => efTenant([fx('starbucks_search')['data']['positions'][0]], [481078371802 => fx('starbucks_detail')]),
    ]);
    // Something outside the HTTP layer blows up for Lockheed only.
    Cache::shouldReceive('get')->andReturnUsing(function (string $key) {
        if (str_contains($key, ':lockheed:')) {
            throw new RuntimeException('db password=hunter2');
        }

        return null;
    });
    Cache::shouldReceive('put')->andReturnTrue();

    $provider = new EightfoldDiscoveryProvider;
    $candidates = $provider->retrieve(efContext());

    expect(collect($candidates)->pluck('sourceJobId')->all())->toBe(['starbucks:481078371802']);
    $d = $provider->diagnostics();
    expect($d['lockheed']['status'])->toBe('failed')
        ->and($d['lockheed']['failure_category'])->toBe('unexpected_error')
        ->and($d['lockheed']['failure_message'])->not->toContain('hunter2')
        ->and($d['starbucks']['status'])->toBe('ok')
        ->and($provider->partialFailureSummary())->toContain('lockheed failed')->not->toContain('hunter2');
});

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------

it('ships Lockheed, NVIDIA and Starbucks enabled and Microsoft disabled', function () {
    $enabled = array_map(fn (EightfoldTenant $t) => $t->slug, EightfoldTenant::enabled());

    expect($enabled)->toBe(['lockheed', 'nvidia', 'starbucks']);

    $microsoft = collect(EightfoldTenant::configured())->firstWhere('slug', 'microsoft');
    expect($microsoft->enabled)->toBeFalse()
        ->and($microsoft->host)->toBe('apply.careers.microsoft.com')
        ->and($microsoft->domain)->toBe('microsoft.com')
        ->and($microsoft->budget['pause_ms'])->toBeGreaterThan(config('job_discovery.eightfold.budget.pause_ms'));
});

it('makes no requests for a disabled tenant and can be enabled by configuration', function () {
    efOnly('starbucks');
    efFake([STARBUCKS_HOST => efTenant([]), 'apply.careers.microsoft.com' => efTenant([fx('microsoft_search')['data']['positions'][0]], [1970393557053368 => fx('microsoft_detail')])]);

    (new EightfoldDiscoveryProvider)->retrieve(efContext());
    expect(efRequests('apply.careers.microsoft.com'))->toBe([]);

    config(['job_discovery.eightfold.tenants.microsoft.enabled' => true]);
    $candidates = (new EightfoldDiscoveryProvider)->retrieve(efContext());

    $request = efRequests('apply.careers.microsoft.com', '/search')[0];
    expect($request->data()['domain'])->toBe('microsoft.com')
        ->and(collect($candidates)->pluck('sourceJobId')->all())->toContain('microsoft:1970393557053368');
});

it('is inactive when the master switch is off or no tenant is enabled', function () {
    expect(EightfoldDiscoveryProvider::isActive())->toBeTrue();

    config(['job_discovery.eightfold.enabled' => false]);
    expect(EightfoldDiscoveryProvider::isActive())->toBeFalse();

    config(['job_discovery.eightfold.enabled' => true]);
    efOnly();
    expect(EightfoldDiscoveryProvider::isActive())->toBeFalse()
        ->and((new EightfoldDiscoveryProvider)->retrieve(efContext()))->toBe([]);
});

it('refuses a tenant whose host is not a bare hostname', function (string $host) {
    config(['job_discovery.eightfold.tenants.lockheed.host' => $host]);
    efOnly('lockheed');

    expect(fn () => (new EightfoldDiscoveryProvider)->retrieve(efContext()))
        ->toThrow(DiscoveryProviderException::class, 'invalid host');
})->with(['https://evil.test', 'evil.test/path', 'nodots', 'a b.test']);

it('refuses a tenant with a missing domain', function () {
    config(['job_discovery.eightfold.tenants.lockheed.domain' => '']);

    expect(fn () => EightfoldTenant::configured())->toThrow(DiscoveryProviderException::class, 'domain');
});

// ---------------------------------------------------------------------------
// Integration with the existing discovery pipeline
// ---------------------------------------------------------------------------

function efPipeline(): void
{
    CareerProfile::factory()->create();
    config([
        'job_discovery.search.keywords' => ['software engineer'],
        'job_discovery.search.excluded_keywords' => [],
        'job_discovery.search.remote_preference' => 'any',
        'job_discovery.search.allowed_locations' => [],
        'job_discovery.search.employment_type' => null,
    ]);
}

/** Lockheed serves two remote Software Engineer postings; NVIDIA serves one US-remote listing. */
function efHealthyHosts(): array
{
    $lockheedRows = [fx('lockheed_search')['data']['positions'][1], fx('lockheed_search')['data']['positions'][2]];

    return [
        LOCKHEED_HOST => efTenant($lockheedRows, [
            996476391868 => fx('lockheed_detail_996476391868'),
            996476482699 => fx('lockheed_detail_996476482699'),
        ]),
        NVIDIA_HOST => efTenant(
            [collect(fx('nvidia_search')['data']['positions'])->firstWhere('id', 893397676085)],
            [893397676085 => fx('nvidia_detail_us')],
        ),
    ];
}

it('is not registered or scheduled while the master switch is off', function () {
    config(['job_discovery.eightfold.enabled' => false]);

    $registered = (new ReflectionProperty(RunJobDiscovery::class, 'providers'))->getValue(new RunJobDiscovery);

    expect(array_keys($registered))->not->toContain('eightfold')
        ->and(DiscoveryScheduleIsDue::scheduledSources())->not->toContain(JobDiscoverySource::Eightfold);
});

it('registers when configured but stays out of the schedule until scheduling is switched on', function () {
    config(['job_discovery.eightfold.enabled' => true, 'job_discovery.eightfold.scheduled' => false]);
    $registered = (new ReflectionProperty(RunJobDiscovery::class, 'providers'))->getValue(new RunJobDiscovery);

    expect(array_keys($registered))->toContain('eightfold')
        ->and(DiscoveryScheduleIsDue::scheduledSources())->not->toContain(JobDiscoverySource::Eightfold);

    config(['job_discovery.eightfold.scheduled' => true]);
    expect(DiscoveryScheduleIsDue::scheduledSources())->toContain(JobDiscoverySource::Eightfold)
        ->and(array_map(fn ($s) => $s->value, DiscoveryScheduleIsDue::scheduledSources()))
        ->toEqualCanonicalizing(array_keys((new ReflectionProperty(RunJobDiscovery::class, 'providers'))->getValue(new RunJobDiscovery)));

    // Scheduled but with every tenant off: nothing to run, so nothing scheduled.
    efOnly();
    expect(DiscoveryScheduleIsDue::scheduledSources())->not->toContain(JobDiscoverySource::Eightfold);
});

it('ingests Eightfold candidates through the existing pipeline with employer-scoped IDs', function () {
    efPipeline();
    efOnly('lockheed', 'nvidia');
    efFake(efHealthyHosts());

    $run = (new RunJobDiscovery(eightfold: new EightfoldDiscoveryProvider))->run([JobDiscoverySource::Eightfold]);

    expect($run->status)->toBe(DiscoveryStatus::Succeeded);
    $attempt = $run->providerAttempts->sole();
    expect($attempt->provider)->toBe(JobDiscoverySource::Eightfold)
        ->and($attempt->status)->toBe(DiscoveryStatus::Succeeded)
        ->and($attempt->candidates_retrieved)->toBe(3)
        ->and($attempt->candidates_accepted)->toBe(3)
        ->and($attempt->jobs_created)->toBe(3)
        ->and($attempt->failure_category)->toBeNull();

    $ids = JobPosting::query()->orderBy('discovery_source_id')->pluck('discovery_source_id')->all();
    expect($ids)->toBe(['lockheed:996476391868', 'lockheed:996476482699', 'nvidia:893397676085']);

    $posting = JobPosting::where('discovery_source_id', 'lockheed:996476391868')->sole();
    expect($posting->discovery_source)->toBe(JobDiscoverySource::Eightfold)
        ->and($posting->company)->toBe('Lockheed Martin')
        ->and($posting->source_url)->toBe('https://lockheedmartin.eightfold.ai/careers/job/996476391868')
        ->and($posting->remote_status)->toBe(JobRemoteStatus::Remote)
        ->and($posting->compensation_min)->toBeNull()
        ->and($posting->discovery_metadata['discovery']['tenant'])->toBe('lockheed')
        ->and($posting->discovery_metadata['discovery']['floridaListed'])->toBeTrue();
});

it('updates rather than duplicates on a second run', function () {
    efPipeline();
    efOnly('lockheed', 'nvidia');
    efFake(efHealthyHosts());

    (new RunJobDiscovery(eightfold: new EightfoldDiscoveryProvider))->run([JobDiscoverySource::Eightfold]);
    $second = (new RunJobDiscovery(eightfold: new EightfoldDiscoveryProvider))->run([JobDiscoverySource::Eightfold]);

    $attempt = $second->providerAttempts->sole();
    expect(JobPosting::count())->toBe(3)
        ->and($attempt->jobs_created)->toBe(0)
        ->and($attempt->jobs_updated)->toBe(3);
});

it('keeps equal position IDs from different employers as separate postings', function () {
    efPipeline();
    efOnly('lockheed', 'nvidia');
    $lockheed = efRow(fx('lockheed_search')['data']['positions'][1], 555);
    $nvidia = efRow(fx('nvidia_search')['data']['positions'][2], 555);
    efFake([
        LOCKHEED_HOST => efTenant([$lockheed], [555 => efDetail(fx('lockheed_detail_996476391868'), 555)]),
        NVIDIA_HOST => efTenant([$nvidia], [555 => efDetail(fx('nvidia_detail_us'), 555)]),
    ]);

    (new RunJobDiscovery(eightfold: new EightfoldDiscoveryProvider))->run([JobDiscoverySource::Eightfold]);

    expect(JobPosting::orderBy('discovery_source_id')->pluck('discovery_source_id')->all())->toBe(['lockheed:555', 'nvidia:555']);
});

it('records a partially failed multi-tenant run as Partial, not as a clean success', function () {
    efPipeline();
    efOnly('lockheed', 'nvidia');
    $hosts = efHealthyHosts();
    $hosts[NVIDIA_HOST] = fn () => Http::response('Please try again later', 429);
    efFake($hosts);

    $run = (new RunJobDiscovery(eightfold: new EightfoldDiscoveryProvider))->run([JobDiscoverySource::Eightfold]);

    $attempt = $run->providerAttempts->sole();
    expect($attempt->status)->toBe(DiscoveryStatus::Partial)
        ->and($attempt->failure_category)->toBe('partial_provider_failure')
        ->and($attempt->failure_message)->toContain('nvidia failed')->toContain('rate_limited')->not->toContain('lockheed')
        // what the healthy tenant produced is kept and counted
        ->and($attempt->candidates_retrieved)->toBe(2)
        ->and($attempt->jobs_created)->toBe(2)
        ->and($run->status)->toBe(DiscoveryStatus::Partial)
        ->and(JobPosting::count())->toBe(2);
});

it('retries a partial attempt on the failed-provider cadence, not the success cadence', function () {
    efPipeline();
    config(['job_discovery.eightfold.scheduled' => true, 'job_discovery.eightfold.enabled' => true]);
    DiscoveryProviderAttempt::factory()->create([
        'provider' => JobDiscoverySource::Eightfold,
        'status' => DiscoveryStatus::Partial,
        'started_at' => now()->subHours(7),
        'finished_at' => now()->subHours(7),
    ]);

    expect((new DiscoveryScheduleIsDue)->dueSources())->toContain(JobDiscoverySource::Eightfold);

    DiscoveryProviderAttempt::query()->update(['started_at' => now()->subHours(2), 'finished_at' => now()->subHours(2)]);
    expect((new DiscoveryScheduleIsDue)->dueSources())->not->toContain(JobDiscoverySource::Eightfold);
});

it('fails the attempt only when every tenant failed, and leaves other providers alone', function () {
    efPipeline();
    config([
        'services.adzuna.app_id' => 'id', 'services.adzuna.app_key' => 'key', 'services.adzuna.country' => 'us',
    ]);
    efOnly('lockheed', 'nvidia');
    Http::fake([
        LOCKHEED_HOST.'/*' => Http::response('', 500),
        NVIDIA_HOST.'/*' => Http::response('{"message": "PCSX is not enabled for this user."}', 403),
        'himalayas.app/*' => Http::response(['jobs' => []]),
        'api.adzuna.com/*' => Http::response(['results' => []]),
        'jobicy.com/*' => Http::response(['jobs' => []]),
    ]);

    $run = (new RunJobDiscovery(eightfold: new EightfoldDiscoveryProvider))->run();

    $eightfold = $run->providerAttempts->firstWhere('provider', JobDiscoverySource::Eightfold);
    expect($eightfold->status)->toBe(DiscoveryStatus::Failed)
        ->and($eightfold->failure_category)->toBe('provider_error')
        ->and($eightfold->failure_message)->toContain('lockheed failed')->toContain('nvidia failed')
        ->and($run->providerAttempts->firstWhere('provider', JobDiscoverySource::Jobicy)->status)->toBe(DiscoveryStatus::Succeeded)
        ->and($run->status)->toBe(DiscoveryStatus::Partial);
});

it('runs a single provider from the command and warns about a partial run', function () {
    efPipeline();
    config(['job_discovery.eightfold.enabled' => true]);
    efOnly('lockheed', 'nvidia');
    $hosts = efHealthyHosts();
    $hosts[NVIDIA_HOST] = fn () => Http::response('Please try again later', 429);
    efFake($hosts);

    $this->artisan('discovery:run', ['--only' => ['eightfold']])
        ->expectsOutputToContain('only partly succeeded')
        ->expectsOutputToContain('finished with status partial')
        ->assertSuccessful();

    expect(DiscoveryProviderAttempt::pluck('provider')->map->value->all())->toBe(['eightfold']);
});

it('rejects an unknown provider name on the command', function () {
    $this->artisan('discovery:run', ['--only' => ['nope']])
        ->expectsOutputToContain('Unknown discovery provider')
        ->assertFailed();
});
