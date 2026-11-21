<?php

use App\Enums\DescriptionCompleteness;
use App\Enums\DiscoveryRejectionReason;
use App\Enums\DiscoveryStatus;
use App\Enums\JobDiscoverySource;
use App\Enums\JobRemoteStatus;
use App\Models\CareerProfile;
use App\Models\DiscoveryProviderAttempt;
use App\Models\DiscoveryRejection;
use App\Models\JobPosting;
use App\Support\JobDiscovery\DiscoveredJobCandidate;
use App\Support\JobDiscovery\DiscoveryScheduleIsDue;
use App\Support\JobDiscovery\DiscoverySearchCriteria;
use App\Support\JobDiscovery\FilterDiscoveredCandidates;
use App\Support\JobDiscovery\Providers\DiscoveryProviderException;
use App\Support\JobDiscovery\Providers\ProviderDiscoveryContext;
use App\Support\JobDiscovery\Providers\UsajobsDiscoveryProvider;
use App\Support\JobDiscovery\RunJobDiscovery;
use App\Support\JobDiscovery\SearchProfile;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

const USJ_KEY = 'secret-usajobs-key-123';
const USJ_AGENT = 'owner@example.com';

beforeEach(function () {
    Sleep::fake();
    config([
        'services.usajobs.api_key' => USJ_KEY,
        'services.usajobs.user_agent' => USJ_AGENT,
        'job_discovery.usajobs.per_page' => 50,
        'job_discovery.usajobs.max_pages' => 3,
        // The pre-existing behavior (remote only); the Florida tests opt in explicitly.
        'job_discovery.search.hybrid_regions' => [],
    ]);
});

/**
 * One SearchResultItems entry, shaped like the documented USAJOBS response.
 *
 * @param  array<string, mixed>  $descriptor  merged over the defaults
 * @param  array<string, mixed>  $details  merged over UserArea.Details defaults
 */
function usjItem(string $control, string $title = 'IT Specialist (APPSW)', array $descriptor = [], array $details = []): array
{
    return [
        'MatchedObjectId' => $control,
        'MatchedObjectDescriptor' => array_merge([
            'PositionID' => "ANN-{$control}",
            'PositionTitle' => $title,
            'PositionURI' => "https://www.usajobs.gov/job/{$control}",
            'ApplyURI' => ["https://www.usajobs.gov/job/{$control}"],
            'PositionLocationDisplay' => 'Anywhere in the U.S. (remote job)',
            'PositionLocation' => [['LocationName' => 'Anywhere in the U.S. (remote job)', 'CountryCode' => 'United States']],
            'OrganizationName' => 'Department of Example',
            'DepartmentName' => 'Example Department',
            'JobCategory' => [['Name' => 'Information Technology Management', 'Code' => '2210']],
            'JobGrade' => [['Code' => 'GS']],
            'PositionSchedule' => [['Name' => '', 'Code' => '1']],
            'PositionOfferingType' => [['Name' => '', 'Code' => '15317']],
            'QualificationSummary' => 'One year of specialized experience at the GS-12 level.',
            'PositionRemuneration' => [
                ['MinimumRange' => '96370', 'MaximumRange' => '125268', 'RateIntervalCode' => 'PA', 'Description' => 'Per Year'],
                ['MinimumRange' => '114590', 'MaximumRange' => '148967', 'RateIntervalCode' => 'PA', 'Description' => 'Per Year'],
            ],
            'PublicationStartDate' => '2026-11-05T00:00:00.0000',
            'PositionStartDate' => '2026-11-05T00:00:00.0000',
            'ApplicationCloseDate' => '2026-11-19T23:59:59.9970',
            'UserArea' => ['Details' => array_merge([
                'JobSummary' => 'Develop and maintain applications.',
                'MajorDuties' => ['Write code.', 'Review pull requests.'],
                'Requirements' => 'U.S. citizenship.',
                'LowGrade' => '12',
                'HighGrade' => '13',
                'RemoteIndicator' => true,
                'TeleworkEligible' => false,
                'HiringPath' => ['public'],
                'WhoMayApply' => ['Name' => '', 'Code' => ''],
                'SecurityClearance' => 'Not Required',
            ], $details)],
        ], $descriptor),
    ];
}

function usjPage(array $items, ?int $total = null): array
{
    return ['LanguageCode' => 'EN', 'SearchResult' => [
        'SearchResultCount' => count($items),
        'SearchResultCountAll' => $total ?? count($items),
        'SearchResultItems' => $items,
    ]];
}

function usjFake(array|Closure $response, int $status = 200): void
{
    Http::fake(['data.usajobs.gov/*' => $response instanceof Closure ? $response : Http::response($response, $status)]);
}

// ---------------------------------------------------------------------------
// Credentials and requests
// ---------------------------------------------------------------------------

it('fails closed without making a request when either credential is missing', function (string $key, string $agent) {
    config(['services.usajobs.api_key' => $key, 'services.usajobs.user_agent' => $agent]);
    Http::fake();

    expect(fn () => (new UsajobsDiscoveryProvider)->retrieve())->toThrow(DiscoveryProviderException::class, 'USAJOBS is not configured');
    Http::assertNothingSent();
    expect(UsajobsDiscoveryProvider::isActive())->toBeFalse();
})->with([['', USJ_AGENT], [USJ_KEY, ''], ['', '']]);

it('sends the key and registered email as headers and a bounded, narrow query', function () {
    usjFake(usjPage([usjItem('1')]));

    (new UsajobsDiscoveryProvider)->retrieve();

    Http::assertSent(function (Request $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $request->hasHeader('Authorization-Key', USJ_KEY)
            && $request->hasHeader('User-Agent', USJ_AGENT)
            && str_starts_with($request->url(), 'https://data.usajobs.gov/api/Search?')
            && $query['JobCategoryCode'] === '2210'
            && $query['RemoteIndicator'] === 'True'
            && $query['HiringPath'] === 'public'
            && $query['ResultsPerPage'] === '50'
            && $query['Page'] === '1'
            && $query['SortField'] === 'opendate';
    });
});

it('drops the remote filter only when the profile asks for any location', function () {
    usjFake(usjPage([]));
    $profile = new SearchProfile('any', 'Any', ['x'], [], null, 'any', [], null, null);

    (new UsajobsDiscoveryProvider)->retrieve(new ProviderDiscoveryContext($profile));

    Http::assertSent(fn (Request $r) => ! str_contains($r->url(), 'RemoteIndicator'));
});

it('never logs the credentials when a request fails', function () {
    Log::spy();
    usjFake([], 500);

    try {
        (new UsajobsDiscoveryProvider)->retrieve();
    } catch (DiscoveryProviderException $e) {
        expect($e->getMessage())->not->toContain(USJ_KEY)->not->toContain(USJ_AGENT);
    }

    Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context = []) => ! str_contains(json_encode([$message, $context]), USJ_KEY) && ! str_contains(json_encode([$message, $context]), USJ_AGENT))->atLeast()->once();
});

// ---------------------------------------------------------------------------
// Retrieval: success, pagination, empty, malformed, failure
// ---------------------------------------------------------------------------

it('normalizes a posting, keeping federal metadata and not inventing fields', function () {
    usjFake(usjPage([usjItem('900')]));

    $candidates = (new UsajobsDiscoveryProvider)->retrieve();
    $c = $candidates[0];

    expect($candidates)->toHaveCount(1)
        ->and($c->source)->toBe(JobDiscoverySource::Usajobs)
        ->and($c->sourceJobId)->toBe('ANN-900')
        ->and($c->title)->toBe('IT Specialist (APPSW)')
        ->and($c->company)->toBe('Department of Example')
        ->and($c->applicationUrl)->toBe('https://www.usajobs.gov/job/900')
        ->and($c->location)->toBe('Anywhere in the U.S. (remote job)')
        ->and($c->remoteStatus)->toBe(JobRemoteStatus::Remote)
        ->and($c->employmentType)->toBe('Full-Time')
        ->and($c->compensationMin)->toBe(96370)
        ->and($c->compensationMax)->toBe(148967)
        ->and($c->compensationCurrency)->toBe('USD')
        ->and($c->compensationInterval)->toBe('year')
        ->and($c->postedAt?->toDateString())->toBe('2026-11-05')
        ->and($c->descriptionCompleteness)->toBe(DescriptionCompleteness::Complete)
        ->and($c->description)->toContain('Develop and maintain applications.')->toContain('Write code.')->toContain('specialized experience')
        ->and($c->sourceMetadata)->toMatchArray([
            'announcementNumber' => 'ANN-900',
            'controlNumber' => '900',
            'payPlan' => ['GS'],
            'lowGrade' => '12',
            'highGrade' => '13',
            'teleworkEligible' => false,
            'remoteIndicator' => true,
            'hiringPath' => ['public'],
            'schedule' => [['code' => '1']],
            'offeringType' => [['code' => '15317']],
            'securityClearance' => 'Not Required',
        ])
        // The live "who may apply" block is an empty shell: not stated, so not stored.
        ->and($c->sourceMetadata)->not->toHaveKey('whoMayApply')
        ->and($c->sourceMetadata['closingDate'])->toStartWith('2026-11-19')
        ->and($c->sourceMetadata['remuneration'])->toHaveCount(2);
});

it('leaves unavailable fields null instead of guessing', function () {
    usjFake(usjPage([usjItem('901', 'Software Engineer', [
        'PositionRemuneration' => [], 'PositionSchedule' => [], 'PublicationStartDate' => null, 'PositionStartDate' => null,
        'QualificationSummary' => null, 'ApplicationCloseDate' => null, 'PositionEndDate' => null,
    ], ['MajorDuties' => null, 'Requirements' => null])]));

    $c = (new UsajobsDiscoveryProvider)->retrieve()[0];

    expect($c->compensationMin)->toBeNull()->and($c->compensationMax)->toBeNull()->and($c->compensationCurrency)->toBeNull()
        ->and($c->compensationInterval)->toBeNull()->and($c->employmentType)->toBeNull()->and($c->postedAt)->toBeNull()
        ->and($c->descriptionCompleteness)->toBe(DescriptionCompleteness::Preview)
        ->and($c->sourceMetadata)->not->toHaveKey('closingDate');
});

it('distinguishes remote from telework-eligible and from location-dependent postings', function () {
    usjFake(usjPage([
        usjItem('1', descriptor: ['PositionLocationDisplay' => 'Anywhere in the U.S. (remote job)'], details: ['RemoteIndicator' => true, 'TeleworkEligible' => true]),
        usjItem('2', descriptor: ['PositionLocationDisplay' => 'Washington, District of Columbia'], details: ['RemoteIndicator' => false, 'TeleworkEligible' => true]),
        usjItem('3', descriptor: ['PositionLocationDisplay' => 'Huntsville, Alabama'], details: ['RemoteIndicator' => false, 'TeleworkEligible' => false]),
        usjItem('4', descriptor: ['PositionLocationDisplay' => 'Anywhere in the U.S. (remote job)'], details: ['RemoteIndicator' => null]),
    ]));

    $byId = collect((new UsajobsDiscoveryProvider)->retrieve())->keyBy('sourceJobId');

    expect($byId['ANN-1']->remoteStatus)->toBe(JobRemoteStatus::Remote)
        ->and($byId['ANN-2']->remoteStatus)->toBeNull()
        ->and($byId['ANN-2']->sourceMetadata['teleworkEligible'])->toBeTrue()
        ->and($byId['ANN-2']->sourceMetadata['remoteIndicator'])->toBeFalse()
        ->and($byId['ANN-3']->remoteStatus)->toBeNull()
        ->and($byId['ANN-3']->sourceMetadata['teleworkEligible'])->toBeFalse()
        ->and($byId['ANN-4']->remoteStatus)->toBe(JobRemoteStatus::Remote);
});

it('paginates within its bounds and stops when the results run out', function () {
    config(['job_discovery.usajobs.per_page' => 2, 'job_discovery.usajobs.max_pages' => 5]);
    usjFake(function (Request $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return Http::response(match ((int) $query['Page']) {
            1 => usjPage([usjItem('1'), usjItem('2')], 3),
            default => usjPage([usjItem('3')], 3),
        });
    });

    $provider = new UsajobsDiscoveryProvider;
    $candidates = $provider->retrieve();

    expect($candidates)->toHaveCount(3)
        ->and($provider->retrievalDiagnostics())->toMatchArray(['raw_entries' => 3, 'normalization_dropped' => 0, 'pages_fetched' => 2]);
    Http::assertSentCount(2);
});

it('never fetches more than max_pages even when more results exist', function () {
    config(['job_discovery.usajobs.per_page' => 2, 'job_discovery.usajobs.max_pages' => 2]);
    $n = 0;
    usjFake(function () use (&$n) {
        return Http::response(usjPage([usjItem((string) (++$n)), usjItem((string) (++$n))], 500));
    });

    expect((new UsajobsDiscoveryProvider)->retrieve())->toHaveCount(4);
    Http::assertSentCount(2);
});

it('treats maxResults as a ceiling on requests and results', function () {
    usjFake(function (Request $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return Http::response(usjPage([usjItem('1'), usjItem('2'), usjItem('3')], 100));
    });

    $candidates = (new UsajobsDiscoveryProvider)->retrieve(new ProviderDiscoveryContext(maxResults: 2));

    expect($candidates)->toHaveCount(2);
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'ResultsPerPage=2'));
    Http::assertSentCount(1);
});

it('returns an empty list for a genuinely empty result, not an error', function () {
    usjFake(usjPage([]));

    $provider = new UsajobsDiscoveryProvider;

    expect($provider->retrieve())->toBe([])
        ->and($provider->retrievalDiagnostics())->toMatchArray(['raw_entries' => 0, 'normalization_dropped' => 0]);
    Http::assertSentCount(1);
});

it('rejects malformed responses without returning partial data', function (array $body) {
    usjFake($body);

    expect(fn () => (new UsajobsDiscoveryProvider)->retrieve())->toThrow(DiscoveryProviderException::class);
})->with([
    'no SearchResult' => [['LanguageCode' => 'EN']],
    'items not a list' => [['SearchResult' => ['SearchResultItems' => 'oops']]],
    'a non-object item' => [['SearchResult' => ['SearchResultItems' => ['nope']]]],
]);

it('rejects a non-JSON body', function () {
    Http::fake(['data.usajobs.gov/*' => Http::response('<html>maintenance</html>', 200)]);

    expect(fn () => (new UsajobsDiscoveryProvider)->retrieve())->toThrow(DiscoveryProviderException::class);
});

it('counts records missing an identity, title or organization instead of silently ignoring them', function () {
    usjFake(usjPage([
        usjItem('1'),
        ['MatchedObjectId' => '2', 'MatchedObjectDescriptor' => ['PositionID' => 'ANN-2', 'OrganizationName' => 'Agency']],
        ['MatchedObjectId' => '3', 'MatchedObjectDescriptor' => 'not an object'],
        usjItem('4', descriptor: ['OrganizationName' => null, 'DepartmentName' => null]),
    ]));

    $provider = new UsajobsDiscoveryProvider;

    expect($provider->retrieve())->toHaveCount(1)
        ->and($provider->retrievalDiagnostics())->toMatchArray(['raw_entries' => 4, 'normalization_dropped' => 3]);
});

it('fails the attempt on API errors and connection problems', function (array|string $fake, int $status) {
    usjFake($fake === 'timeout' ? fn () => throw new ConnectionException('timed out') : $fake, $status);

    expect(fn () => (new UsajobsDiscoveryProvider)->retrieve())->toThrow(DiscoveryProviderException::class, $status === 0 ? 'connection error' : "HTTP status {$status}");
})->with([
    '401 bad key' => [[], 401],
    '403' => [[], 403],
    '500 after retries' => [[], 500],
    'timeout' => ['timeout', 0],
]);

it('retries a transient failure and succeeds', function () {
    $calls = 0;
    usjFake(function () use (&$calls) {
        return ++$calls === 1 ? Http::response([], 503) : Http::response(usjPage([usjItem('1')]));
    });

    expect((new UsajobsDiscoveryProvider)->retrieve())->toHaveCount(1);
    expect($calls)->toBe(2);
});

it('deduplicates the same announcement within and across pages', function () {
    config(['job_discovery.usajobs.per_page' => 2]);
    usjFake(function (Request $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return Http::response((int) $query['Page'] === 1
            // Same control number on two pages, and a re-listed announcement under a new control number.
            ? usjPage([usjItem('10'), usjItem('11', descriptor: ['PositionID' => 'ANN-10'])], 4)
            : usjPage([usjItem('10'), usjItem('12')], 4));
    });

    $provider = new UsajobsDiscoveryProvider;
    $ids = array_map(fn (DiscoveredJobCandidate $c) => $c->sourceJobId, $provider->retrieve());

    expect($ids)->toBe(['ANN-10', 'ANN-12'])
        // Control number 10 again on page 2, plus control number 11 re-listing announcement ANN-10.
        ->and($provider->retrievalDiagnostics()['duplicate_announcements'])->toBe(2);
});

it('uses the control number only when an announcement number is missing', function () {
    usjFake(usjPage([usjItem('777', descriptor: ['PositionID' => null])]));

    $c = (new UsajobsDiscoveryProvider)->retrieve()[0];

    expect($c->sourceJobId)->toBe('777')->and($c->sourceMetadata)->not->toHaveKey('announcementNumber');
});

// ---------------------------------------------------------------------------
// Federal title gate
// ---------------------------------------------------------------------------

function usjCandidate(string $title, ?JobRemoteStatus $remote = JobRemoteStatus::Remote, JobDiscoverySource $source = JobDiscoverySource::Usajobs, array $metadata = []): DiscoveredJobCandidate
{
    return new DiscoveredJobCandidate(
        source: $source, sourceJobId: 'g-1', company: 'Dept', title: $title, description: null,
        descriptionCompleteness: DescriptionCompleteness::Complete, location: 'Anywhere in the U.S. (remote job)',
        remoteStatus: $remote, employmentType: null, compensationMin: null, compensationMax: null, compensationCurrency: null,
        compensationInterval: null, applicationUrl: null, postedAt: null, sourceUpdatedAt: null,
        discoveredAt: CarbonImmutable::now(), sourceMetadata: $metadata,
    );
}

function usjDecision(DiscoveredJobCandidate $candidate)
{
    return (new FilterDiscoveredCandidates)->evaluate($candidate, DiscoverySearchCriteria::fromConfig());
}

it('accepts the application-software federal titles and nothing broader', function (string $title, bool $accepted) {
    $decision = usjDecision(usjCandidate($title));

    expect($decision->accepted)->toBe($accepted);
    if (! $accepted) {
        expect($decision->reason)->toBe(DiscoveryRejectionReason::TitleNotMatching);
    }
})->with([
    ['IT Specialist (APPSW)', true],
    ['Information Technology Specialist (APPSW)', true],
    ['it specialist ( appsw )', true],
    ['IT Specialist (INFOSEC)', false],
    ['IT Specialist (CUSTSPT)', false],
    ['IT Specialist (SYSADMIN)', false],
    ['IT Specialist', false],
    ['Information Technology Specialist', false],
    ['IT Project Manager (APPSW)', false],
    ['Management Analyst', false],
    ['Cybersecurity Specialist', false],
    ['Administrative Assistant', false],
    // The commercial title rules still apply to federal postings.
    ['Software Engineer', true],
    ['Computer Scientist', false],
]);

it('applies the federal title patterns to USAJOBS candidates only', function () {
    expect(usjDecision(usjCandidate('IT Specialist (APPSW)', source: JobDiscoverySource::Jobicy))->reason)->toBe(DiscoveryRejectionReason::TitleNotMatching)
        ->and(usjDecision(usjCandidate('IT Specialist (APPSW)', source: JobDiscoverySource::Usajobs))->accepted)->toBeTrue();
});

it('keeps the exclusions in force for federal titles', function (string $title, DiscoveryRejectionReason $reason) {
    expect(usjDecision(usjCandidate($title))->reason)->toBe($reason);
})->with([
    ['Supervisory IT Specialist (APPSW)', DiscoveryRejectionReason::ExcludedTitleRole],
    ['Supervisory Information Technology Specialist (APPSW)', DiscoveryRejectionReason::ExcludedTitleRole],
    ['IT Specialist (APPSW), Supervisor', DiscoveryRejectionReason::ExcludedTitleRole],
    ['Pathways Intern IT Specialist (APPSW)', DiscoveryRejectionReason::ExcludedSeniority],
    ['Junior IT Specialist (APPSW)', DiscoveryRejectionReason::ExcludedSeniority],
    ['IT Specialist (APPSW) DevOps', DiscoveryRejectionReason::ExcludedTitleRole],
    ['Firmware Software Engineer', DiscoveryRejectionReason::ExcludedTitleRole],
]);

it('accepts only announcements marked remote, never telework-only or location-dependent ones', function () {
    $remote = usjDecision(usjCandidate('IT Specialist (APPSW)', JobRemoteStatus::Remote));
    $telework = usjDecision(usjCandidate('IT Specialist (APPSW)', null, metadata: ['teleworkEligible' => true]));
    $plain = usjDecision(usjCandidate('IT Specialist (APPSW)', null, metadata: ['teleworkEligible' => false]));

    expect($remote->accepted)->toBeTrue()
        ->and($telework->accepted)->toBeFalse()
        ->and($telework->reason)->toBe(DiscoveryRejectionReason::WorkArrangementNotAllowed)
        ->and($telework->matchedTerm)->toBe('not_remote')
        ->and($telework->evidence)->toContain('telework eligible')
        ->and($plain->accepted)->toBeFalse()
        ->and($plain->evidence)->toContain('no remote designation');
});

it('does not change how other providers treat an unknown remote status', function () {
    expect(usjDecision(usjCandidate('Software Engineer', null, JobDiscoverySource::Adzuna))->accepted)->toBeTrue()
        ->and(usjDecision(usjCandidate('Software Engineer', null, JobDiscoverySource::Jobicy))->accepted)->toBeTrue();
});

// ---------------------------------------------------------------------------
// Orchestration
// ---------------------------------------------------------------------------

function usjRun(): void
{
    (new RunJobDiscovery)->run([JobDiscoverySource::Usajobs]);
}

it('registers only when credentials are configured and leaves the other providers alone', function () {
    CareerProfile::factory()->create();
    Http::fake();

    config(['services.usajobs.api_key' => '', 'services.usajobs.user_agent' => '']);
    $run = (new RunJobDiscovery)->run([JobDiscoverySource::Usajobs]);
    expect($run->providerAttempts)->toHaveCount(0);
    Http::assertNothingSent();

    config(['services.usajobs.api_key' => USJ_KEY, 'services.usajobs.user_agent' => USJ_AGENT]);
    usjFake(usjPage([]));
    $run = (new RunJobDiscovery)->run([JobDiscoverySource::Usajobs]);
    expect($run->providerAttempts->pluck('provider')->map->value->all())->toBe(['usajobs']);
});

it('ingests accepted federal postings once, records the rest as rejections, and never touches other providers', function () {
    CareerProfile::factory()->create();
    usjFake(usjPage([
        usjItem('1'),
        usjItem('2', 'Management Analyst'),
        usjItem('3', descriptor: ['PositionLocationDisplay' => 'Washington, District of Columbia'], details: ['RemoteIndicator' => false]),
    ]));

    usjRun();
    usjRun();

    $attempt = DiscoveryProviderAttempt::query()->where('provider', JobDiscoverySource::Usajobs)->orderBy('id')->first();
    expect(JobPosting::query()->where('discovery_source', JobDiscoverySource::Usajobs)->count())->toBe(1)
        ->and(JobPosting::query()->where('discovery_source', '!=', JobDiscoverySource::Usajobs)->count())->toBe(0)
        ->and($attempt->status)->toBe(DiscoveryStatus::Succeeded)
        ->and([$attempt->candidates_retrieved, $attempt->candidates_accepted, $attempt->jobs_created])->toBe([3, 1, 1])
        ->and($attempt->rejection_counts)->toBe(['title_not_matching' => 1, 'work_arrangement_not_allowed' => 1])
        ->and($attempt->retrieval_diagnostics)->toMatchArray(['raw_entries' => 3, 'normalization_dropped' => 0])
        ->and(DiscoveryRejection::query()->where('source', JobDiscoverySource::Usajobs)->count())->toBe(2);

    $posting = JobPosting::query()->where('discovery_source', JobDiscoverySource::Usajobs)->first();
    expect($posting->discovery_source_id)->toBe('ANN-1')
        ->and($posting->discovery_metadata['discovery']['teleworkEligible'])->toBeFalse()
        ->and($posting->discovery_metadata['discovery']['remoteIndicator'])->toBeTrue();
});

it('records an API failure as a failed attempt without affecting the run for other providers', function () {
    CareerProfile::factory()->create();
    usjFake([], 500);

    $run = (new RunJobDiscovery)->run([JobDiscoverySource::Usajobs]);
    $attempt = $run->providerAttempts->first();

    expect($attempt->status)->toBe(DiscoveryStatus::Failed)
        ->and($attempt->failure_message)->toContain('HTTP status 500')->not->toContain(USJ_KEY)
        ->and($attempt->rejection_counts)->toBeNull();
});

it('is not scheduled by default, and only scheduled when credentials and the opt-in are both present', function () {
    expect(DiscoveryScheduleIsDue::scheduledSources())->not->toContain(JobDiscoverySource::Usajobs);

    config(['job_discovery.usajobs.scheduled' => true]);
    expect(DiscoveryScheduleIsDue::scheduledSources())->toContain(JobDiscoverySource::Usajobs);

    config(['services.usajobs.api_key' => '']);
    expect(DiscoveryScheduleIsDue::scheduledSources())->not->toContain(JobDiscoverySource::Usajobs);
});

it('rejects supervisory titles for USAJOBS only, and still accepts the ordinary APPSW title', function () {
    $supervisory = usjDecision(usjCandidate('Supervisory IT Specialist (APPSW)'));

    expect($supervisory->accepted)->toBeFalse()
        ->and($supervisory->reason)->toBe(DiscoveryRejectionReason::ExcludedTitleRole)
        ->and($supervisory->matchedTerm)->toBe('supervisory')
        ->and($supervisory->matchedField)->toBe('title')
        ->and(usjDecision(usjCandidate('IT Specialist (APPSW)'))->accepted)->toBeTrue()
        // A commercial posting is judged by the commercial rules, which this federal exclusion does not touch.
        ->and(usjDecision(usjCandidate('Supervisory Software Engineer', source: JobDiscoverySource::Jobicy))->accepted)->toBeTrue();
});

// ---------------------------------------------------------------------------
// Live-shaped fixtures (recorded from the real API, long text trimmed, contact details removed)
// ---------------------------------------------------------------------------

function usjFixture(string $name): array
{
    return json_decode((string) file_get_contents(base_path("tests/Fixtures/usajobs/{$name}.json")), true, flags: JSON_THROW_ON_ERROR);
}

it('maps a recorded live remote announcement without losing its remote designation', function () {
    usjFake(['SearchResult' => ['SearchResultCountAll' => 1, 'SearchResultItems' => [usjFixture('search_live_shape')['SearchResult']['SearchResultItems'][0]]]]);

    $c = (new UsajobsDiscoveryProvider)->retrieve()[0];

    expect($c->sourceJobId)->toBe('27-CIO-13106963')
        ->and($c->sourceMetadata['controlNumber'])->toBe('892723000')
        ->and($c->remoteStatus)->toBe(JobRemoteStatus::Remote)
        ->and($c->sourceMetadata['remoteIndicator'])->toBeTrue()
        ->and($c->sourceMetadata['teleworkEligible'])->toBeFalse()
        ->and($c->sourceMetadata['hiringPath'])->toBe(['fed-internal-search'])
        ->and($c->sourceMetadata['payPlan'])->toBe(['AD'])
        ->and($c->compensationMin)->toBe(102415)->and($c->compensationMax)->toBe(191965)->and($c->compensationInterval)->toBe('year')
        ->and($c->employmentType)->toBe('Full-Time')
        ->and($c->descriptionCompleteness)->toBe(DescriptionCompleteness::Complete)
        ->and($c->sourceMetadata['closingDate'])->toStartWith('2026-11-30');
    // The only live remote 2210 posting is open to federal employees only; eligibility is kept raw, never judged here.
    expect(usjDecision($c)->reason)->toBe(DiscoveryRejectionReason::TitleNotMatching);
});

it('maps recorded live location-dependent announcements as not remote and keeps free-text schedule names out of employment type', function () {
    usjFake(usjFixture('search_live_shape'));

    $candidates = collect((new UsajobsDiscoveryProvider)->retrieve())->skip(1);

    expect($candidates)->toHaveCount(5);
    foreach ($candidates as $c) {
        expect($c->remoteStatus)->toBeNull()
            ->and($c->sourceMetadata['remoteIndicator'])->toBeFalse()
            ->and($c->employmentType)->toBe('Full-Time')
            ->and($c->sourceMetadata)->not->toHaveKey('whoMayApply')
            ->and(usjDecision($c)->accepted)->toBeFalse();
    }
    $ssa = $candidates->firstWhere('title', 'JAVA Angular Full Stack Web Application Developer');
    expect($ssa->sourceMetadata['schedule'])->toBe([['code' => '1', 'name' => 'Flextime and Alternative Work Schedule are available']])
        ->and($ssa->sourceMetadata['hiringPath'])->toBe(['fed-transition', 'public'])
        ->and($ssa->location)->toBe('Woodlawn, Maryland');
});

it('treats the live empty result as no jobs, not a parsing failure', function () {
    usjFake(usjFixture('search_empty_live'));

    $provider = new UsajobsDiscoveryProvider;

    expect($provider->retrieve())->toBe([])->and($provider->retrievalDiagnostics()['raw_entries'])->toBe(0);
});

// ---------------------------------------------------------------------------
// Federal application-software titles (real titles from live USAJOBS research)
// ---------------------------------------------------------------------------

it('accepts APPSW and Applications Software titles, including mixed specialties, and rejects the rest', function (string $title, bool $accepted) {
    expect(usjDecision(usjCandidate($title))->accepted)->toBe($accepted);
})->with([
    ['IT Specialist (APPSW)', true],
    ['IT SPECIALIST (APPSW/INFOSEC)', true],
    ['IT Specialist (INFOSEC/APPSW)', true],
    ['Information Technology Specialist (APPSW)', true],
    ['IT Specialist (Applications Software)', true],
    ['Information Technology Specialist (Application Software)', true],
    ['IT Specialist (APPSW) - Direct Hire', true],
    ['IT Specialist', false],
    ['INFORMATION TECHNOLOGY SPECIALIST', false],
    ['IT Specialist (INFOSEC)', false],
    ['IT CYBERSECURITY SPECIALIST (INFOSEC)', false],
    ['IT SPECIALIST (SYSADMIN)', false],
    ['IT Specialist (INFOSEC/CUSTSPT)', false],
    ['Information Technology Specialist (Systems Administration)', false],
    ['Information Technology Specialist (Customer Support/Networking)', false],
    ['Information Technology Specialist (DATAMGT)', false],
    ['Information Technology Specialist (Systems Analysis)', false],
    ['IT Specialist (Network Services)', false],
    ['SYSADMIN INFOSEC/CORD Operator, 20 SPSS', false],
    ['IT PROGRAM MANAGER (POLICY AND PLANNING)', false],
    ['Supervisory IT Specialist (APPSW)', false],
    ['SUPERVISORY IT SPECIALIST (APPSW/INFOSEC)', false],
    ['Supervisory IT Specialist (Policy and Planning)', false],
]);

// ---------------------------------------------------------------------------
// Florida telework: query branches, budget, deduplication
// ---------------------------------------------------------------------------

/** @return list<array<string, string>> the query strings of every request sent, in order */
function usjQueries(): array
{
    return collect(Http::recorded())->map(function (array $pair) {
        parse_str((string) parse_url($pair[0]->url(), PHP_URL_QUERY), $query);

        return $query;
    })->values()->all();
}

function usjProfile(string $remote = 'remote_us', array $locations = ['United States', 'US', 'USA', 'Remote']): SearchProfile
{
    return new SearchProfile('p', 'P', ['x'], [], null, $remote, $locations, null, null);
}

it('searches remote first and then Florida, each as its own public 2210 branch', function () {
    config(['job_discovery.search.hybrid_regions' => ['Florida']]);
    usjFake(usjPage([]));

    (new UsajobsDiscoveryProvider)->retrieve();
    $queries = usjQueries();

    expect($queries)->toHaveCount(2)
        ->and($queries[0])->toMatchArray(['JobCategoryCode' => '2210', 'HiringPath' => 'public', 'RemoteIndicator' => 'True'])
        ->and($queries[0])->not->toHaveKey('LocationName')
        ->and($queries[1])->toMatchArray(['JobCategoryCode' => '2210', 'HiringPath' => 'public', 'LocationName' => 'Florida'])
        ->and($queries[1])->not->toHaveKey('RemoteIndicator');
});

it('uses the profile to decide the branches: remote-only profiles stay remote-only', function (string $remote, array $locations, int $requests, bool $florida, bool $remoteFilter) {
    config(['job_discovery.search.hybrid_regions' => ['Florida']]);
    usjFake(usjPage([]));

    (new UsajobsDiscoveryProvider)->retrieve(new ProviderDiscoveryContext(usjProfile($remote, $locations)));
    $queries = usjQueries();

    expect($queries)->toHaveCount($requests)
        ->and(collect($queries)->contains(fn ($q) => ($q['LocationName'] ?? null) === 'Florida'))->toBe($florida)
        ->and(collect($queries)->contains(fn ($q) => ($q['RemoteIndicator'] ?? null) === 'True'))->toBe($remoteFilter);
})->with([
    'remote_us built-in' => ['remote_us', ['United States', 'US', 'USA', 'Remote'], 1, false, true],
    'regional profile naming Florida' => ['remote_us', ['United States', 'Remote', 'florida'], 2, true, true],
    'any location' => ['any', ['United States'], 1, false, false],
]);

it('stays remote-only when no hybrid regions are configured', function () {
    config(['job_discovery.search.hybrid_regions' => []]);
    usjFake(usjPage([]));

    (new UsajobsDiscoveryProvider)->retrieve();

    expect(usjQueries())->toHaveCount(1)->and(usjQueries()[0]['RemoteIndicator'])->toBe('True');
});

it('shares one request ceiling and one result cap across the branches', function () {
    config(['job_discovery.search.hybrid_regions' => ['Florida'], 'job_discovery.usajobs.per_page' => 2, 'job_discovery.usajobs.max_pages' => 3]);
    $n = 0;
    usjFake(function () use (&$n) {
        return Http::response(usjPage([usjItem((string) (++$n)), usjItem((string) (++$n))], 500));
    });

    $provider = new UsajobsDiscoveryProvider;
    $candidates = $provider->retrieve();

    // 3 requests in total (not 3 per branch), and the second branch is never starved.
    expect(usjQueries())->toHaveCount(3)
        ->and(collect(usjQueries())->pluck('LocationName')->filter()->count())->toBe(1)
        ->and($candidates)->toHaveCount(6)
        ->and($provider->retrievalDiagnostics())->toMatchArray(['pages_fetched' => 3, 'search_branches' => 2]);
});

it('gives every branch its first page even when the ceiling is lower than the branch count', function () {
    config(['job_discovery.search.hybrid_regions' => ['Florida'], 'job_discovery.usajobs.max_pages' => 1]);
    usjFake(usjPage([usjItem('1')], 1));

    (new UsajobsDiscoveryProvider)->retrieve();

    expect(usjQueries())->toHaveCount(2);
});

it('applies maxResults to the combined result, not per branch', function () {
    config(['job_discovery.search.hybrid_regions' => ['Florida']]);
    $n = 0;
    usjFake(function (Request $request) use (&$n) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        $items = [];
        for ($i = 0; $i < (int) $query['ResultsPerPage']; $i++) {
            $items[] = usjItem((string) (++$n));
        }

        return Http::response(usjPage($items, 500));
    });

    $candidates = (new UsajobsDiscoveryProvider)->retrieve(new ProviderDiscoveryContext(maxResults: 3));

    expect($candidates)->toHaveCount(3);
    // The remote branch filled the cap, so no Florida request was needed.
    expect(usjQueries())->toHaveCount(1);
});

it('deduplicates an announcement returned by both the remote and the Florida branch', function () {
    config(['job_discovery.search.hybrid_regions' => ['Florida']]);
    usjFake(usjPage([usjItem('55', 'IT Specialist (APPSW)'), usjItem('56')]));

    $provider = new UsajobsDiscoveryProvider;
    $candidates = $provider->retrieve();

    expect(usjQueries())->toHaveCount(2)
        ->and(array_map(fn (DiscoveredJobCandidate $c) => $c->sourceJobId, $candidates))->toBe(['ANN-55', 'ANN-56'])
        ->and($provider->retrievalDiagnostics()['raw_entries'])->toBe(2);
});

// ---------------------------------------------------------------------------
// Florida telework: mapping and acceptance (recorded live Florida results)
// ---------------------------------------------------------------------------

function usjFloridaFake(): void
{
    config(['job_discovery.search.hybrid_regions' => ['Florida']]);
    Http::fake(function (Request $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return Http::response(isset($query['LocationName']) ? usjFixture('search_florida_live') : usjPage([]));
    });
}

function usjFloridaCandidates(): Collection
{
    usjFloridaFake();

    return collect((new UsajobsDiscoveryProvider)->retrieve())->keyBy(fn (DiscoveredJobCandidate $c) => $c->sourceMetadata['controlNumber']);
}

it('keeps telework as telework: Florida postings are never stored as remote and keep their agency locations', function () {
    $all = usjFloridaCandidates();
    $c = $all['892455400'];

    expect($c->remoteStatus)->toBeNull()
        ->and($c->sourceMetadata['teleworkEligible'])->toBeTrue()
        ->and($c->sourceMetadata['remoteIndicator'])->toBeFalse()
        ->and($c->sourceMetadata['hybridRegionsListed'])->toBe(['Florida'])
        ->and($c->location)->toBe('Cecil Field, Florida')
        ->and($c->sourceMetadata['locations'])->toBe(['Cecil Field, Florida'])
        ->and($c->sourceMetadata['hiringPath'])->toBe(['public']);

    $multi = $all['892782200'];
    expect($multi->location)->toBe('Multiple Locations')
        ->and($multi->sourceMetadata['locations'])->toContain('Saint Petersburg, Florida', 'West Palm Beach, Florida');
});

it('finds Florida among every listed location of a multi-location announcement, not just the first twenty', function () {
    config(['job_discovery.search.hybrid_regions' => ['Florida']]);
    $places = array_map(fn ($i) => ['LocationName' => "Town {$i}, Texas"], range(1, 40));
    $places[] = ['LocationName' => 'Eglin AFB, Florida'];
    usjFake(usjPage([usjItem('77', 'IT Specialist (APPSW)', ['PositionLocationDisplay' => 'Multiple Locations', 'PositionLocation' => $places], ['RemoteIndicator' => false, 'TeleworkEligible' => true])]));

    $c = (new UsajobsDiscoveryProvider)->retrieve()[0];

    expect($c->sourceMetadata['hybridRegionsListed'])->toBe(['Florida'])
        ->and($c->sourceMetadata['locations'])->toHaveCount(20)
        ->and(usjDecision($c)->accepted)->toBeTrue();
});

it('accepts Florida telework postings with an application-software title and rejects the rest of the live Florida results', function () {
    $decisions = usjFloridaCandidates()->map(fn (DiscoveredJobCandidate $c) => usjDecision($c));

    expect($decisions['892455400']->accepted)->toBeTrue()
        ->and($decisions['892199900']->accepted)->toBeTrue()
        ->and($decisions['892625200']->accepted)->toBeTrue()
        ->and($decisions->filter(fn ($d) => $d->accepted)->count())->toBe(3);

    // Florida on-site and not telework eligible: rejected as on-site even though the title matches.
    $key = $decisions['891986600'];
    expect($key->accepted)->toBeFalse()
        ->and($key->reason)->toBe(DiscoveryRejectionReason::WorkArrangementNotAllowed)
        ->and($key->matchedTerm)->toBe('onsite')
        ->and($key->evidence)->toContain('not telework eligible');

    // Unrelated or supervisory titles are rejected on the title, telework or not.
    expect($decisions['892782200']->reason)->toBe(DiscoveryRejectionReason::ExcludedTitleRole)
        ->and($decisions['892776500']->reason)->toBe(DiscoveryRejectionReason::TitleNotMatching)
        ->and($decisions['892723400']->reason)->toBe(DiscoveryRejectionReason::TitleNotMatching)
        ->and($decisions['892230400']->reason)->toBe(DiscoveryRejectionReason::TitleNotMatching);
});

it('does not accept telework outside the configured regions or without any region configured', function () {
    $outside = usjCandidate('IT Specialist (APPSW)', null, metadata: ['teleworkEligible' => true, 'hybridRegionsListed' => []]);
    $unstated = usjCandidate('IT Specialist (APPSW)', null, metadata: ['hybridRegionsListed' => ['Florida']]);

    config(['job_discovery.search.hybrid_regions' => ['Florida']]);
    expect(usjDecision($outside)->matchedTerm)->toBe('not_remote')
        ->and(usjDecision($unstated)->matchedTerm)->toBe('telework_not_stated');

    config(['job_discovery.search.hybrid_regions' => []]);
    $florida = usjCandidate('IT Specialist (APPSW)', null, metadata: ['teleworkEligible' => true, 'hybridRegionsListed' => ['Florida']]);
    expect(usjDecision($florida)->matchedTerm)->toBe('not_remote');
});

it('leaves remote-only behavior and the commercial providers unchanged when Florida is configured', function () {
    config(['job_discovery.search.hybrid_regions' => ['Florida']]);

    expect(usjDecision(usjCandidate('IT Specialist (APPSW)', JobRemoteStatus::Remote))->accepted)->toBeTrue()
        ->and(usjDecision(usjCandidate('Software Engineer', null, JobDiscoverySource::Jobicy, ['teleworkEligible' => true, 'hybridRegionsListed' => ['Florida']]))->accepted)->toBeTrue()
        ->and(usjDecision(usjCandidate('IT Specialist (APPSW)', JobRemoteStatus::Remote, JobDiscoverySource::Adzuna))->reason)->toBe(DiscoveryRejectionReason::TitleNotMatching);
});

it('ingests accepted Florida telework postings as unknown-remote with their telework metadata, through the normal pipeline', function () {
    CareerProfile::factory()->create();
    usjFloridaFake();

    usjRun();

    $attempt = DiscoveryProviderAttempt::query()->where('provider', JobDiscoverySource::Usajobs)->first();
    $jobs = JobPosting::query()->where('discovery_source', JobDiscoverySource::Usajobs)->orderBy('discovery_source_id')->get();

    expect($attempt->status)->toBe(DiscoveryStatus::Succeeded)
        ->and([$attempt->candidates_retrieved, $attempt->candidates_accepted, $attempt->jobs_created])->toBe([10, 3, 3])
        ->and($attempt->retrieval_diagnostics)->toMatchArray(['raw_entries' => 10, 'search_branches' => 2])
        ->and($jobs)->toHaveCount(3)
        ->and($jobs->every(fn (JobPosting $j) => $j->remote_status === null))->toBeTrue()
        ->and($jobs->every(fn (JobPosting $j) => $j->discovery_metadata['discovery']['teleworkEligible'] === true))->toBeTrue()
        ->and(DiscoveryRejection::query()->where('source', JobDiscoverySource::Usajobs)->count())->toBe(7)
        ->and(DiscoveryRejection::query()->where('matched_term', 'onsite')->count())->toBe(1);
});

it('dedupes by announcement number first across branches, even when control numbers differ, and falls back to the control number', function () {
    config(['job_discovery.search.hybrid_regions' => ['Florida']]);
    usjFake(function (Request $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return Http::response(isset($query['LocationName'])
            // The Florida branch lists the same announcement under another control number, plus two postings that
            // have no announcement number and so are identified by control number.
            ? usjPage([usjItem('901', descriptor: ['PositionID' => 'ANN-A']), usjItem('777', descriptor: ['PositionID' => null]), usjItem('888', descriptor: ['PositionID' => ''])])
            : usjPage([usjItem('900', descriptor: ['PositionID' => 'ANN-A']), usjItem('777', descriptor: ['PositionID' => null])]));
    });

    $provider = new UsajobsDiscoveryProvider;
    $ids = array_map(fn (DiscoveredJobCandidate $c) => $c->sourceJobId, $provider->retrieve());

    // The first sighting of ANN-A wins; control numbers 777 and 888 stand in only where no announcement number exists.
    expect($ids)->toBe(['ANN-A', '777', '888'])
        ->and($provider->retrievalDiagnostics()['duplicate_announcements'])->toBe(2);
});

it('supplies each ApplyURI as an unverified candidate destination, and still keeps the legacy applyUris list', function () {
    usjFake(usjPage([usjItem('901', 'IT Specialist', ['ApplyURI' => ['https://apply.usajobs.gov/applicant/901', 'not a url', 'http://example.gov/apply?x=1']])]));

    $meta = (new UsajobsDiscoveryProvider)->retrieve()[0]->sourceMetadata;

    expect($meta['applyUris'])->toBe(['https://apply.usajobs.gov/applicant/901', 'http://example.gov/apply?x=1'])
        ->and(array_column($meta['destinations'], 'url'))->toBe(['https://apply.usajobs.gov/applicant/901', 'http://example.gov/apply?x=1']);
    foreach ($meta['destinations'] as $i => $d) {
        expect($d)->toMatchArray(['role' => 'candidate_destination', 'source' => 'usajobs', 'verification' => ['status' => 'unverified']])
            ->and($d['provenance'])->toBe(['origin' => 'provider_api', 'field' => 'ApplyURI', 'tier' => 'public']);
    }
});
