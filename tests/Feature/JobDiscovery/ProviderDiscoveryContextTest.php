<?php

use App\Enums\DiscoveryStatus;
use App\Enums\JobDiscoverySource;
use App\Models\CareerProfile;
use App\Models\DiscoveryRun;
use App\Models\JobPosting;
use App\Support\CurrentCareerProfile;
use App\Support\JobDiscovery\DiscoverySearchCriteria;
use App\Support\JobDiscovery\LoadSearchProfiles;
use App\Support\JobDiscovery\Providers\DiscoveryProviderContract;
use App\Support\JobDiscovery\Providers\ProviderDiscoveryContext;
use App\Support\JobDiscovery\RunJobDiscovery;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use InvalidArgumentException;

/*
| Phase 4 — ProviderDiscoveryContext.
|
| Covers the context VO itself, its contract-level reception (via a stub
| provider that RECORDS what it was handed), RunJobDiscovery's verbatim
| forwarding into the provider retrieve() call, and end-to-end behavior
| with a context attached. The per-provider "accepts and IGNORES the
| context" tests live next to each provider's own fixtures in its test
| file; the no-context behavior is pinned by the pre-existing
| RunJobDiscoveryTest, RunJobDiscoveryCommandTest and *ProviderTest
| suites, which must stay green unchanged. This file therefore reuses
| LoadSearchProfiles (the real seeded profiles) instead of duplicating
| fixtures.
*/

beforeEach(function () {
    Sleep::fake();
    CareerProfile::factory()->create();
    config([
        'services.adzuna.app_id' => 'test-id',
        'services.adzuna.app_key' => 'test-key',
        'services.adzuna.country' => 'us',
    ]);
});

/**
 * Implements the contract purely to RECORD what context it received —
 * the real providers ignore the context, so this is the only place it
 * can be observed end-to-end. runProviderAttempt() is typed against
 * DiscoveryProviderContract, so the stub slots in without any seam.
 */
final class RecordingDiscoveryProvider implements DiscoveryProviderContract
{
    public ?ProviderDiscoveryContext $receivedContext = null;

    public bool $wasCalled = false;

    public function retrieve(?ProviderDiscoveryContext $context = null): array
    {
        $this->wasCalled = true;
        $this->receivedContext = $context;

        return [];
    }
}

function contextFixture(int $maxResults = 25, string $profileId = 'data_analytics'): ProviderDiscoveryContext
{
    return new ProviderDiscoveryContext(profile: LoadSearchProfiles::byId($profileId), maxResults: $maxResults);
}

/**
 * runProviderAttempt() takes `array &$boardCache` by reference, which
 * ReflectionMethod::invoke() cannot supply (PHP flattens its variadic
 * arguments by value). A normal method call *can* pass a reference, so
 * the private method is invoked through a closure bound to the instance.
 */
function invokeRunProviderAttempt(
    RunJobDiscovery $instance,
    DiscoveryRun $discoveryRun,
    JobDiscoverySource $source,
    DiscoveryProviderContract $provider,
    DiscoverySearchCriteria $criteria,
    CareerProfile $careerProfile,
    ?ProviderDiscoveryContext $context = null,
): void {
    $bound = Closure::bind(
        function ($dr, $src, $prov, $crit, $cp, $bc, $ctx): void {
            $this->runProviderAttempt($dr, $src, $prov, $crit, $cp, $bc, $ctx);
        },
        $instance,
        RunJobDiscovery::class,
    );

    $bound($discoveryRun, $source, $provider, $criteria, $careerProfile, [], $context);
}

it('defaults both optional values to null when nothing is provided', function () {
    $context = new ProviderDiscoveryContext;

    expect($context->profile)->toBeNull()
        ->and($context->maxResults)->toBeNull();
});

it('stores the profile and max-results cap it was given, instance-identical', function () {
    $profile = LoadSearchProfiles::byId('data_analytics');

    $context = new ProviderDiscoveryContext(profile: $profile, maxResults: 50);

    expect($context->profile)->toBe($profile)
        ->and($context->maxResults)->toBe(50);
});

it('treats an explicit null profile and cap as valid', function () {
    $context = new ProviderDiscoveryContext(profile: null, maxResults: null);

    expect($context->profile)->toBeNull()
        ->and($context->maxResults)->toBeNull();
});

it('accepts a bare profile with no cap, and a bare cap with no profile', function () {
    $profile = LoadSearchProfiles::byId('marketing_technology');

    expect((new ProviderDiscoveryContext(profile: $profile))->profile)->toBe($profile)
        ->and((new ProviderDiscoveryContext(profile: $profile))->maxResults)->toBeNull()
        ->and((new ProviderDiscoveryContext(maxResults: 1))->maxResults)->toBe(1)
        ->and((new ProviderDiscoveryContext(maxResults: 1))->profile)->toBeNull();
});

it('rejects a max-results cap of zero or a negative number', function () {
    expect(fn () => new ProviderDiscoveryContext(maxResults: 0))
        ->toThrow(InvalidArgumentException::class, 'positive');

    expect(fn () => new ProviderDiscoveryContext(maxResults: -5))
        ->toThrow(InvalidArgumentException::class, 'positive');
});

it('is immutable once constructed', function () {
    $profile = LoadSearchProfiles::byId('software_engineering');
    $context = new ProviderDiscoveryContext(profile: $profile, maxResults: 10);

    expect(fn () => $context->profile = null)->toThrow(Error::class);
    expect(fn () => $context->maxResults = 5)->toThrow(Error::class);

    expect($context->profile)->toBe($profile)
        ->and($context->maxResults)->toBe(10);
});

it('receives the exact context instance a caller passes it', function () {
    $stub = new RecordingDiscoveryProvider;
    $context = contextFixture(maxResults: 25, profileId: 'software_engineering');

    $stub->retrieve($context);

    expect($stub->wasCalled)->toBeTrue()
        ->and($stub->receivedContext)->toBe($context);
});

it('receives a null context when the caller passes none', function () {
    $stub = new RecordingDiscoveryProvider;

    $stub->retrieve();

    expect($stub->wasCalled)->toBeTrue()
        ->and($stub->receivedContext)->toBeNull();
});

it('forwards the exact context instance from the orchestrator into the provider retrieve call', function () {
    $discoveryRun = DiscoveryRun::create(['status' => DiscoveryStatus::Running, 'started_at' => now()]);
    $stub = new RecordingDiscoveryProvider;
    $context = contextFixture();

    // runProviderAttempt() is private but typed against the contract,
    // so a recording stub proves what reaches $provider->retrieve().
    invokeRunProviderAttempt(
        new RunJobDiscovery,
        $discoveryRun,
        JobDiscoverySource::Himalayas,
        $stub,
        DiscoverySearchCriteria::fromConfig(),
        CurrentCareerProfile::resolve(),
        $context,
    );

    expect($stub->receivedContext)->toBe($context);
});

it('forwards a null context when the orchestrator was given none', function () {
    $discoveryRun = DiscoveryRun::create(['status' => DiscoveryStatus::Running, 'started_at' => now()]);
    $stub = new RecordingDiscoveryProvider;

    invokeRunProviderAttempt(
        new RunJobDiscovery,
        $discoveryRun,
        JobDiscoverySource::Himalayas,
        $stub,
        DiscoverySearchCriteria::fromConfig(),
        CurrentCareerProfile::resolve(),
        null,
    );

    expect($stub->receivedContext)->toBeNull();
});

it('runs an unchanged manual discovery pipeline end-to-end when a context is passed', function () {
    Http::fake([
        'himalayas.app/*' => Http::response(['jobs' => [
            ['title' => 'Software Engineer', 'companyName' => 'Acme', 'description' => 'Build things.',
                'locationRestrictions' => ['United States'],
                'applicationLink' => 'https://himalayas.app/jobs/1', 'guid' => 'https://himalayas.app/jobs/1'],
        ]]),
        'api.adzuna.com/*' => Http::response(['results' => [
            ['id' => 'a1', 'title' => 'Software Engineer', 'description' => 'Build things.',
                'company' => ['display_name' => 'Acme'], 'location' => ['display_name' => 'Remote'],
                'redirect_url' => 'https://www.adzuna.com/land/ad/a1', 'created' => '2026-09-01T12:00:00Z'],
        ]]),
        'jobicy.com/*' => Http::response(['jobs' => [
            ['id' => 1, 'jobTitle' => 'Software Engineer', 'companyName' => 'Acme',
                'jobDescription' => '<p>Build things.</p>', 'jobGeo' => 'USA', 'jobType' => ['Full-Time'],
                'url' => 'https://jobicy.com/jobs/1', 'pubDate' => '2026-09-01T12:00:00+00:00'],
        ]]),
    ]);

    // A marketing-tech profile with a tight cap: none of it may change
    // what the V1 providers request or return.
    $discoveryRun = (new RunJobDiscovery)->run(null, contextFixture(maxResults: 2, profileId: 'marketing_technology'));

    expect($discoveryRun->status)->toBe(DiscoveryStatus::Succeeded)
        ->and($discoveryRun->providerAttempts)->toHaveCount(3)
        ->and(JobPosting::count())->toBe(3);
});

it('still isolates one provider failing while the others ingest, even with a context attached', function () {
    Http::fake([
        'himalayas.app/*' => Http::response(['jobs' => [
            ['title' => 'Software Engineer', 'companyName' => 'Acme', 'description' => 'Build things.',
                'locationRestrictions' => ['United States'],
                'applicationLink' => 'https://himalayas.app/jobs/1', 'guid' => 'https://himalayas.app/jobs/1'],
        ]]),
        'api.adzuna.com/*' => Http::response(null, 500),
        'jobicy.com/*' => Http::response(['jobs' => []]),
    ]);

    $discoveryRun = (new RunJobDiscovery)->run(null, contextFixture());

    expect($discoveryRun->status)->toBe(DiscoveryStatus::Partial);

    $himalayasAttempt = $discoveryRun->providerAttempts->firstWhere('provider', JobDiscoverySource::Himalayas);
    $adzunaAttempt = $discoveryRun->providerAttempts->firstWhere('provider', JobDiscoverySource::Adzuna);

    expect($himalayasAttempt->status)->toBe(DiscoveryStatus::Succeeded)
        ->and($himalayasAttempt->jobs_created)->toBe(1)
        ->and($adzunaAttempt->status)->toBe(DiscoveryStatus::Failed)
        ->and($adzunaAttempt->failure_message)->not->toBeNull()
        ->and(JobPosting::count())->toBe(1);
});
