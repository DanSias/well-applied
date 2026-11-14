<?php

use App\Enums\DiscoveryStatus;
use App\Enums\JobDiscoverySource;
use App\Models\CareerProfile;
use App\Models\DiscoveryProviderAttempt;
use App\Models\JobPosting;
use App\Support\JobDiscovery\RunJobDiscovery;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Sleep::fake();
    CareerProfile::factory()->create();
    config([
        'job_discovery.search.keywords' => ['software engineer'],
        'job_discovery.search.excluded_keywords' => ['junior'],
        'job_discovery.search.excluded_title_keywords' => [],
        'job_discovery.search.title_families' => null,
        'job_discovery.search.remote_preference' => 'remote_us',
    ]);
});

function cntJob(int $id, string $title, string $geo = 'USA'): array
{
    return [
        'id' => $id, 'jobTitle' => $title, 'companyName' => 'Acme', 'jobDescription' => '<p>Build things.</p>', 'jobGeo' => $geo,
        'url' => "https://jobicy.com/jobs/{$id}", 'pubDate' => '2026-09-01T12:00:00+00:00',
    ];
}

/**
 * Http::fake() stubs accumulate and the first match wins, so one stub reads the response the current attempt should see.
 *
 * @param  array{jobs: array<int, array<string, mixed>>, status: int|null}|null  $set
 * @return array{jobs: array<int, array<string, mixed>>, status: int|null}
 */
function cntFeed(?array $set = null): array
{
    static $feed = ['jobs' => [], 'status' => null];
    if ($set !== null) {
        $feed = $set;
    }

    return $feed;
}

function cntJobicyAttempt(array $jobs, ?int $status = null): DiscoveryProviderAttempt
{
    cntFeed(['jobs' => $jobs, 'status' => $status]);
    Http::fake([
        'himalayas.app/*' => Http::response(['jobs' => []]),
        'api.adzuna.com/*' => Http::response(['results' => []]),
        'jobicy.com/*' => fn () => cntFeed()['status'] === null ? Http::response(['jobs' => cntFeed()['jobs']]) : Http::response('', cntFeed()['status']),
    ]);
    config(['services.adzuna.app_id' => 'x', 'services.adzuna.app_key' => 'y']);

    return (new RunJobDiscovery)->run([JobDiscoverySource::Jobicy])->providerAttempts->firstWhere('provider', JobDiscoverySource::Jobicy);
}

it('records filter rejections by reason and keeps them separate from created and updated jobs', function () {
    $first = cntJobicyAttempt([
        cntJob(1, 'Senior Software Engineer'),
        cntJob(2, 'Warehouse Associate'),
        cntJob(3, 'Account Manager'),
        cntJob(4, 'Junior Software Engineer'),
        cntJob(5, 'Software Engineer', 'Germany'),
    ]);

    expect($first->candidates_retrieved)->toBe(5)
        ->and($first->candidates_accepted)->toBe(1)
        ->and($first->jobs_created)->toBe(1)
        ->and($first->jobs_updated)->toBe(0)
        ->and($first->rejection_counts)->toBe(['title_not_matching' => 2, 'excluded_seniority' => 1, 'location_not_allowed' => 1])
        ->and(array_sum($first->rejection_counts))->toBe($first->candidates_retrieved - $first->candidates_accepted);

    // The same job again is an update, not a rejection and not a second creation.
    $second = cntJobicyAttempt([cntJob(1, 'Senior Software Engineer')]);

    expect($second->candidates_accepted)->toBe(1)
        ->and($second->jobs_created)->toBe(0)
        ->and($second->jobs_updated)->toBe(1)
        ->and($second->rejection_counts)->toBe([])
        ->and(JobPosting::count())->toBe(1);
});

it('reports entries that could not be normalized without counting them as filter rejections', function () {
    $attempt = cntJobicyAttempt([
        cntJob(1, 'Senior Software Engineer'),
        ['id' => 2, 'companyName' => 'No Title Inc'],
        cntJob(3, 'Warehouse Associate'),
    ]);

    expect($attempt->retrieval_diagnostics)->toBe(['raw_entries' => 3, 'normalization_dropped' => 1])
        ->and($attempt->candidates_retrieved)->toBe(2)
        ->and($attempt->candidates_accepted)->toBe(1)
        ->and($attempt->rejection_counts)->toBe(['title_not_matching' => 1]);
});

it('distinguishes a successful zero-result attempt from a failed one', function () {
    $empty = cntJobicyAttempt([]);

    expect($empty->status)->toBe(DiscoveryStatus::Succeeded)
        ->and($empty->candidates_retrieved)->toBe(0)
        ->and($empty->rejection_counts)->toBe([])
        ->and($empty->retrieval_diagnostics)->toBe(['raw_entries' => 0, 'normalization_dropped' => 0]);

    $failed = cntJobicyAttempt([], 500);

    expect($failed->status)->toBe(DiscoveryStatus::Failed)
        ->and($failed->failure_category)->toBe('provider_error')
        ->and($failed->candidates_retrieved)->toBe(0)
        // Never observed, so never recorded as zero.
        ->and($failed->rejection_counts)->toBeNull()
        ->and($failed->retrieval_diagnostics)->toBeNull();
});

it('leaves counts null for attempts recorded before stage counts existed', function () {
    $attempt = DiscoveryProviderAttempt::factory()->create(['candidates_retrieved' => 10, 'candidates_accepted' => 2]);

    expect($attempt->fresh()->rejection_counts)->toBeNull()
        ->and($attempt->fresh()->retrieval_diagnostics)->toBeNull();
});
