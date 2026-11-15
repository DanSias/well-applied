<?php

use App\Enums\DiscoveryStatus;
use App\Enums\JobDiscoverySource;
use App\Models\CareerProfile;
use App\Models\DiscoveryProviderAttempt;
use App\Models\DiscoveryRun;
use App\Models\JobPosting;
use App\Support\Home\PresentDiscoveryHealth;
use App\Support\Sources\PresentSources;
use Illuminate\Support\Facades\DB;

function srcAttempt(JobDiscoverySource $source, DiscoveryStatus $status, int $hoursAgo, array $attributes = [], ?DiscoveryRun $run = null): DiscoveryProviderAttempt
{
    return DiscoveryProviderAttempt::factory()->create([
        'discovery_run_id' => $run?->id ?? DiscoveryRun::factory()->create(['status' => $status, 'started_at' => now()->subHours($hoursAgo), 'finished_at' => now()->subHours($hoursAgo)])->id,
        'provider' => $source,
        'status' => $status,
        'started_at' => now()->subHours($hoursAgo),
        'finished_at' => now()->subHours($hoursAgo),
    ] + $attributes);
}

function srcProvider(string $provider, ?PresentSources $presenter = null): array
{
    return collect(($presenter ?? new PresentSources)->providers())->firstWhere('provider', $provider);
}

beforeEach(fn () => CareerProfile::factory()->create());

it('presents every provider with no history without inventing anything', function () {
    $sources = (new PresentSources)->present();

    expect(array_column($sources['providers'], 'provider'))->toBe(['himalayas', 'adzuna', 'jobicy', 'eightfold', 'usajobs'])
        ->and($sources['summary'])->toMatchArray(['ever_ran' => false, 'last_run_at' => null, 'runs_in_window' => 0, 'jobs_added' => 0, 'total' => 5])
        ->and($sources['activity']['runs'])->toBe([])
        ->and($sources['activity']['pagination'])->toMatchArray(['total' => 0, 'last_page' => 1, 'from' => 0, 'to' => 0]);

    foreach ($sources['providers'] as $provider) {
        expect($provider['last_attempt'])->toBeNull()
            ->and($provider['last_success'])->toBeNull()
            ->and($provider['last_failure'])->toBeNull();
    }
});

it('keeps the last attempt, last success and last failure apart', function () {
    srcAttempt(JobDiscoverySource::Jobicy, DiscoveryStatus::Succeeded, 30, ['candidates_retrieved' => 100, 'candidates_accepted' => 9]);
    srcAttempt(JobDiscoverySource::Jobicy, DiscoveryStatus::Failed, 10, ['failure_category' => 'provider_error', 'failure_message' => 'HTTP 500', 'failure_exception_class' => 'X']);
    srcAttempt(JobDiscoverySource::Jobicy, DiscoveryStatus::Succeeded, 2, ['candidates_retrieved' => 0, 'candidates_accepted' => 0, 'rejection_counts' => []]);

    $jobicy = srcProvider('jobicy');

    expect($jobicy['last_attempt']['status'])->toBe('succeeded')
        ->and($jobicy['last_attempt']['retrieved'])->toBe(0)
        ->and($jobicy['last_success']['id'])->toBe($jobicy['last_attempt']['id'])
        ->and($jobicy['last_failure']['failure'])->toBe(['category' => 'provider_error', 'message' => 'HTTP 500', 'exception_class' => 'X'])
        ->and($jobicy['needs_attention'])->toBeFalse();
});

it('flags a provider whose latest attempt failed, while keeping its last success', function () {
    srcAttempt(JobDiscoverySource::Jobicy, DiscoveryStatus::Succeeded, 30, ['candidates_accepted' => 9]);
    srcAttempt(JobDiscoverySource::Jobicy, DiscoveryStatus::Failed, 1, ['failure_category' => 'provider_error', 'failure_message' => 'boom']);

    $jobicy = srcProvider('jobicy');

    expect($jobicy['needs_attention'])->toBeTrue()
        ->and($jobicy['last_attempt']['status'])->toBe('failed')
        ->and($jobicy['last_success']['accepted'])->toBe(9)
        ->and($jobicy['last_failure']['id'])->toBe($jobicy['last_attempt']['id']);
});

it('treats Partial as the last success and as needing attention, with its summary as the failure', function () {
    srcAttempt(JobDiscoverySource::Eightfold, DiscoveryStatus::Partial, 3, [
        'candidates_retrieved' => 10, 'candidates_accepted' => 4,
        'failure_category' => 'partial_provider_failure', 'failure_message' => 'Eightfold: one tenant degraded.',
    ]);

    $eightfold = srcProvider('eightfold');

    expect($eightfold['last_success']['status'])->toBe('partial')
        ->and($eightfold['last_success']['accepted'])->toBe(4)
        ->and($eightfold['last_success']['failure']['message'])->toBe('Eightfold: one tenant degraded.')
        ->and($eightfold['last_success']['retrieved_basis'])->toBe('after_prefilter')
        ->and($eightfold['last_failure'])->toBeNull()
        ->and($eightfold['needs_attention'])->toBeTrue()
        ->and($eightfold['window']['partial'])->toBe(1);
});

it('does not turn missing counters into zero', function () {
    srcAttempt(JobDiscoverySource::Himalayas, DiscoveryStatus::Failed, 1, [
        'failure_category' => 'provider_error', 'failure_message' => 'down', 'rejection_counts' => null,
    ]);

    $attempt = srcProvider('himalayas')['last_attempt'];

    expect($attempt)->toMatchArray(['retrieved' => null, 'accepted' => null, 'created' => null, 'updated' => null, 'canonicalized' => null, 'filtered' => null, 'filtered_by_reason' => []]);
});

it('distinguishes zero rejections from unrecorded rejections', function () {
    srcAttempt(JobDiscoverySource::Himalayas, DiscoveryStatus::Succeeded, 3, ['candidates_retrieved' => 5, 'candidates_accepted' => 5, 'rejection_counts' => []]);
    expect(srcProvider('himalayas')['last_attempt']['filtered'])->toBe(0);

    srcAttempt(JobDiscoverySource::Jobicy, DiscoveryStatus::Succeeded, 3, ['candidates_retrieved' => 5, 'candidates_accepted' => 3, 'rejection_counts' => ['title_not_matching' => 2]]);
    $jobicy = srcProvider('jobicy')['last_attempt'];
    expect($jobicy['filtered'])->toBe(2)
        ->and($jobicy['filtered_by_reason'][0])->toMatchArray(['code' => 'title_not_matching', 'count' => 2]);
});

it('reports a successful zero-result run as a success, not a failure', function () {
    srcAttempt(JobDiscoverySource::Himalayas, DiscoveryStatus::Succeeded, 1, ['candidates_retrieved' => 0, 'candidates_accepted' => 0, 'jobs_created' => 0, 'jobs_updated' => 0, 'rejection_counts' => []]);

    $himalayas = srcProvider('himalayas');

    expect($himalayas['last_attempt']['status'])->toBe('succeeded')
        ->and($himalayas['last_attempt']['failure'])->toBeNull()
        ->and($himalayas['last_failure'])->toBeNull();
});

it('agrees with Overview health on every provider both list', function () {
    srcAttempt(JobDiscoverySource::Himalayas, DiscoveryStatus::Succeeded, 2, ['candidates_retrieved' => 40, 'candidates_accepted' => 6, 'jobs_created' => 3, 'jobs_updated' => 3]);
    srcAttempt(JobDiscoverySource::Adzuna, DiscoveryStatus::Succeeded, 24 * 5);
    srcAttempt(JobDiscoverySource::Jobicy, DiscoveryStatus::Succeeded, 30);
    srcAttempt(JobDiscoverySource::Jobicy, DiscoveryStatus::Failed, 1, ['failure_message' => 'boom']);
    srcAttempt(JobDiscoverySource::Eightfold, DiscoveryStatus::Partial, 3, ['failure_message' => 'tenant down']);

    $overview = collect((new PresentDiscoveryHealth)->present()['providers'])->keyBy('provider');
    $sources = collect((new PresentSources)->providers())->keyBy('provider');

    expect($overview->keys()->all())->toBe(['himalayas', 'adzuna', 'jobicy', 'eightfold']);

    foreach ($overview as $provider => $health) {
        expect($sources[$provider]['health'])->toEqual($health)
            ->and($sources[$provider]['stale'])->toBe($health['stale']);
    }
});

it('lists a never-run manual-only provider that Overview hides', function () {
    $overview = array_column((new PresentDiscoveryHealth)->present()['providers'], 'provider');

    expect($overview)->not->toContain('usajobs')
        ->and(srcProvider('usajobs'))->toMatchArray(['effective_schedule' => 'unavailable', 'last_attempt' => null]);
});

it('counts discovered jobs added in the window from the postings, excluding manual ones', function () {
    $profile = CareerProfile::query()->first();
    $make = fn (JobDiscoverySource $source, int $daysAgo, string $id) => JobPosting::factory()->create([
        'career_profile_id' => $profile->id, 'discovery_source' => $source,
        'discovery_source_id' => $source === JobDiscoverySource::Manual ? null : $id, 'created_at' => now()->subDays($daysAgo),
    ]);
    $make(JobDiscoverySource::Jobicy, 1, 'a');
    $make(JobDiscoverySource::Jobicy, 3, 'b');
    $make(JobDiscoverySource::Jobicy, 9, 'old');
    $make(JobDiscoverySource::Himalayas, 2, 'c');
    $make(JobDiscoverySource::Manual, 1, 'm');

    $sources = new PresentSources;

    expect(srcProvider('jobicy', $sources)['jobs_added'])->toBe(2)
        ->and(srcProvider('himalayas', $sources)['jobs_added'])->toBe(1)
        ->and(srcProvider('adzuna', $sources)['jobs_added'])->toBe(0)
        ->and($sources->summary()['jobs_added'])->toBe(3);
});

it('summarizes schedule states, attention and recent activity', function () {
    srcAttempt(JobDiscoverySource::Jobicy, DiscoveryStatus::Failed, 1, ['failure_message' => 'boom']);

    $summary = (new PresentSources)->summary();

    expect($summary)->toMatchArray(['window_days' => 7, 'ever_ran' => true, 'runs_in_window' => 1, 'automatic' => 3, 'unavailable' => 2])
        ->and($summary['attention_providers'])->toContain('jobicy');
});

it('pages recent activity newest first without a query per run', function () {
    foreach (range(1, 12) as $i) {
        $run = DiscoveryRun::factory()->create(['status' => DiscoveryStatus::Succeeded, 'started_at' => now()->subHours(13 - $i)]);
        srcAttempt(JobDiscoverySource::Himalayas, DiscoveryStatus::Succeeded, 13 - $i, [], $run);
        srcAttempt(JobDiscoverySource::Jobicy, DiscoveryStatus::Succeeded, 13 - $i, [], $run);
    }
    $sources = new PresentSources;

    DB::enableQueryLog();
    $first = $sources->activity(1);
    expect(DB::getQueryLog())->toHaveCount(3);

    $second = $sources->activity(2);

    expect($first['runs'])->toHaveCount(10)
        ->and($first['runs'][0]['id'])->toBeGreaterThan($first['runs'][9]['id'])
        ->and($first['runs'][0]['attempts'])->toHaveCount(2)
        ->and($first['pagination'])->toMatchArray(['page' => 1, 'total' => 12, 'last_page' => 2, 'from' => 1, 'to' => 10])
        ->and($second['runs'])->toHaveCount(2)
        ->and($sources->activity(99)['pagination']['page'])->toBe(2)
        ->and($sources->activity(-4)['pagination']['page'])->toBe(1);
});

it('pages one provider\'s history, newest first, without other providers', function () {
    foreach (range(1, 12) as $i) {
        srcAttempt(JobDiscoverySource::Jobicy, DiscoveryStatus::Succeeded, 13 - $i);
    }
    srcAttempt(JobDiscoverySource::Himalayas, DiscoveryStatus::Succeeded, 1);

    $history = (new PresentSources)->history(JobDiscoverySource::Jobicy, 1);

    expect($history['attempts'])->toHaveCount(10)
        ->and(collect($history['attempts'])->pluck('provider')->unique()->all())->toBe(['jobicy'])
        ->and($history['attempts'][0]['started_at'] > $history['attempts'][9]['started_at'])->toBeTrue()
        ->and($history['pagination'])->toMatchArray(['total' => 12, 'last_page' => 2]);
});

it('gives the drawer a provider detail with safe configuration', function () {
    config([
        'services.usajobs.api_key' => 'USA-KEY-SECRET', 'services.usajobs.user_agent' => 'private.person@example.test',
        'services.adzuna.app_id' => 'ADZ-ID-SECRET', 'services.adzuna.app_key' => 'ADZ-KEY-SECRET',
        'job_discovery.eightfold.enabled' => true,
        'job_discovery.eightfold.tenants' => ['acme' => ['company' => 'Acme', 'host' => 'acme.eightfold.ai', 'enabled' => true, 'secret' => 'nope']],
    ]);
    $sources = new PresentSources;

    $usajobs = $sources->detail(JobDiscoverySource::Usajobs);
    $eightfold = $sources->detail(JobDiscoverySource::Eightfold);

    expect($usajobs['configuration'])->toMatchArray(['credentials_present' => true, 'category' => '2210', 'feed_host' => 'data.usajobs.gov'])
        ->and($eightfold['configuration']['tenants'])->toBe([['slug' => 'acme', 'company' => 'Acme', 'host' => 'acme.eightfold.ai', 'enabled' => true]])
        ->and($eightfold['configuration']['budget'])->toHaveKey('max_search_requests')
        ->and($eightfold['history']['attempts'])->toBe([]);

    $json = json_encode([$sources->present(), $usajobs, $eightfold, $sources->detail(JobDiscoverySource::Adzuna)], JSON_THROW_ON_ERROR);
    expect($json)->not->toContain('SECRET')->not->toContain('private.person')->not->toContain('nope');
});
