<?php

use App\Enums\DiscoveryRejectionReason;
use App\Enums\DiscoveryStatus;
use App\Enums\JobDiscoverySource;
use App\Enums\JobDisposition;
use App\Models\CareerProfile;
use App\Models\DiscoveryProviderAttempt;
use App\Models\DiscoveryRejection;
use App\Models\DiscoveryRun;
use App\Models\JobPosting;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

function pageAttempt(JobDiscoverySource $source, DiscoveryStatus $status, int $hoursAgo, array $attributes = [], ?DiscoveryRun $run = null): DiscoveryProviderAttempt
{
    $at = now()->subHours($hoursAgo);

    return DiscoveryProviderAttempt::factory()->create([
        'discovery_run_id' => $run?->id ?? DiscoveryRun::factory()->create(['status' => $status, 'started_at' => $at, 'finished_at' => $at])->id,
        'provider' => $source, 'status' => $status, 'started_at' => $at, 'finished_at' => $at,
    ] + $attributes);
}

beforeEach(function () {
    Http::preventStrayRequests();
    CareerProfile::factory()->create();
});

it('renders the Sources page with all five providers, never-run and unconfigured included', function () {
    $this->get('/sources')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('sources/index')
        ->has('providers', 5)
        ->where('providers', fn ($providers) => collect($providers)->pluck('provider')->all() === ['himalayas', 'adzuna', 'jobicy', 'eightfold', 'usajobs'])
        ->where('providers.4', fn ($usajobs) => $usajobs['configured'] === false && $usajobs['registered'] === false && $usajobs['effective_schedule'] === 'unavailable' && $usajobs['last_attempt'] === null)
        ->where('providers.0.effective_schedule', 'automatic')
        ->where('summary.total', 5)
        ->where('summary.ever_ran', false)
        ->where('activity.runs', [])
        ->where('selected', null)
        ->where('filters', ['provider' => null, 'history_page' => 1]));
});

it('is a navigation destination of its own', function () {
    $this->get('/sources')->assertInertia(fn (Assert $page) => $page->where('nav.section', 'sources'));
    $this->get('/discovery/rejections')->assertInertia(fn (Assert $page) => $page->where('nav.section', 'sources'));
    $this->get('/')->assertInertia(fn (Assert $page) => $page->where('nav.section', 'home'));
});

it('surfaces failed and partial attempts, and keeps null counters distinct from zero', function () {
    pageAttempt(JobDiscoverySource::Jobicy, DiscoveryStatus::Failed, 1, ['failure_category' => 'provider_error', 'failure_message' => 'HTTP 500']);
    pageAttempt(JobDiscoverySource::Eightfold, DiscoveryStatus::Partial, 2, ['candidates_retrieved' => 0, 'jobs_created' => 0, 'rejection_counts' => [], 'failure_category' => 'partial_provider_failure', 'failure_message' => 'one tenant down']);

    $this->get('/sources')->assertInertia(fn (Assert $page) => $page
        ->where('providers.2.last_attempt.status', 'failed')
        ->where('providers.2.last_attempt.retrieved', null)
        ->where('providers.2.last_attempt.filtered', null)
        ->where('providers.2.last_attempt.failure.message', 'HTTP 500')
        ->where('providers.3.last_attempt.status', 'partial')
        ->where('providers.3.last_attempt.retrieved', 0)
        ->where('providers.3.last_attempt.filtered', 0)
        ->where('providers.3.last_attempt.retrieved_basis', 'after_prefilter')
        ->where('providers.0.needs_attention', false)
        ->where('providers.0.overdue', false)
        ->where('summary.attention_providers', fn ($names) => collect($names)->sort()->values()->all() === ['eightfold', 'jobicy']));
});

it('pages recent activity on the server', function () {
    foreach (range(1, 13) as $i) {
        pageAttempt(JobDiscoverySource::Himalayas, DiscoveryStatus::Succeeded, 14 - $i);
    }

    $this->get('/sources')->assertInertia(fn (Assert $page) => $page
        ->has('activity.runs', 10)->where('activity.pagination.total', 13)->where('activity.pagination.last_page', 2));
    $this->get('/sources?page=2')->assertInertia(fn (Assert $page) => $page
        ->has('activity.runs', 3)->where('activity.pagination.page', 2));
    $this->get('/sources?page=99')->assertInertia(fn (Assert $page) => $page->where('activity.pagination.page', 2));
});

it('opens a provider by its identifier and pages its history independently of recent activity', function () {
    foreach (range(1, 12) as $i) {
        pageAttempt(JobDiscoverySource::Jobicy, DiscoveryStatus::Succeeded, 13 - $i);
    }
    pageAttempt(JobDiscoverySource::Himalayas, DiscoveryStatus::Succeeded, 1);

    $this->get('/sources?provider=jobicy')->assertInertia(fn (Assert $page) => $page
        ->where('filters.provider', 'jobicy')
        ->where('selected.provider', 'jobicy')
        ->has('selected.history.attempts', 10)
        ->where('selected.history.pagination.total', 12)
        ->where('activity.pagination.page', 1));

    $this->get('/sources?provider=jobicy&page=2&history_page=2')->assertInertia(fn (Assert $page) => $page
        ->where('selected.history.pagination.page', 2)
        ->has('selected.history.attempts', 2)
        ->where('activity.pagination.page', 2)
        ->where('filters.history_page', 2));
});

it('ignores a provider identifier it does not know, or one that is not a provider', function (string $value) {
    $this->get('/sources?provider='.$value)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('filters.provider', null)->where('selected', null)->has('providers', 5));
})->with(['nonsense', 'manual', 'HIMALAYAS', '']);

it('loads the drawer without recomputing the lists', function () {
    pageAttempt(JobDiscoverySource::Jobicy, DiscoveryStatus::Succeeded, 1);

    $version = $this->get('/sources')->viewData('page')['version'];

    $response = $this->get('/sources?provider=jobicy', [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => $version,
        'X-Inertia-Partial-Component' => 'sources/index',
        'X-Inertia-Partial-Data' => 'filters,selected',
    ])->assertOk();

    $props = $response->json('props');

    expect($props)->toHaveKeys(['filters', 'selected'])
        ->and($props)->not->toHaveKey('providers')
        ->and($props)->not->toHaveKey('activity')
        ->and($props['selected']['provider'])->toBe('jobicy');
});

it('shows read-only configuration without any credential or the registered email', function () {
    config([
        'services.usajobs.api_key' => 'USA-KEY-SECRET', 'services.usajobs.user_agent' => 'private.person@example.test',
        'services.adzuna.app_id' => 'ADZ-ID-SECRET', 'services.adzuna.app_key' => 'ADZ-KEY-SECRET',
    ]);

    foreach (['usajobs', 'adzuna', 'eightfold', 'himalayas', 'jobicy'] as $provider) {
        $body = $this->get('/sources?provider='.$provider)->assertOk()->getContent();
        expect($body)->not->toContain('SECRET')->not->toContain('private.person');
    }

    $this->get('/sources?provider=usajobs')->assertInertia(fn (Assert $page) => $page
        ->where('selected.configuration.credentials_present', true)
        ->where('selected.configuration.category', '2210'));
});

it('lists discovered jobs for a source in every disposition, and nothing else', function () {
    $profile = CareerProfile::query()->first();
    $make = fn (JobDiscoverySource $source, JobDisposition $disposition, string $id) => JobPosting::factory()->create([
        'career_profile_id' => $profile->id, 'discovery_source' => $source, 'disposition' => $disposition,
        'discovery_source_id' => $id, 'title' => "Engineer {$id}",
    ]);
    $make(JobDiscoverySource::Himalayas, JobDisposition::New, 'h1');
    $make(JobDiscoverySource::Himalayas, JobDisposition::Shortlisted, 'h2');
    $make(JobDiscoverySource::Himalayas, JobDisposition::Dismissed, 'h3');
    $make(JobDiscoverySource::Himalayas, JobDisposition::Pursued, 'h4');
    $make(JobDiscoverySource::Jobicy, JobDisposition::New, 'j1');

    $this->get('/jobs?source=himalayas&disposition=all')->assertInertia(fn (Assert $page) => $page
        ->where('filters.source', 'himalayas')
        ->where('pagination.total', 4)
        ->where('jobs', fn ($jobs) => collect($jobs)->every(fn ($j) => $j['discovery']['source'] === 'himalayas'))
        ->where('disposition_counts', ['inbox' => 1, 'shortlist' => 1, 'dismissed' => 1, 'all' => 4]));

    // The Inbox is still the default view when only a source is given.
    $this->get('/jobs?source=himalayas')->assertInertia(fn (Assert $page) => $page
        ->where('filters.disposition', 'inbox')->where('pagination.total', 1));
});

it('combines the source filter with search and ignores an unknown source', function () {
    $profile = CareerProfile::query()->first();
    JobPosting::factory()->create(['career_profile_id' => $profile->id, 'discovery_source' => JobDiscoverySource::Jobicy, 'discovery_source_id' => 'a', 'title' => 'Laravel Engineer']);
    JobPosting::factory()->create(['career_profile_id' => $profile->id, 'discovery_source' => JobDiscoverySource::Jobicy, 'discovery_source_id' => 'b', 'title' => 'Go Engineer']);
    JobPosting::factory()->create(['career_profile_id' => $profile->id, 'discovery_source' => JobDiscoverySource::Himalayas, 'discovery_source_id' => 'c', 'title' => 'Laravel Developer']);

    $this->get('/jobs?source=jobicy&q=laravel')->assertInertia(fn (Assert $page) => $page
        ->where('pagination.total', 1)->where('filters.q', 'laravel')->where('filters.source', 'jobicy'));
    $this->get('/jobs?source=bogus')->assertInertia(fn (Assert $page) => $page
        ->where('filters.source', null)->where('pagination.total', 3));
    $this->get('/jobs')->assertInertia(fn (Assert $page) => $page->where('filters.source', null));
});

it('filters rejected candidates by provider, alongside search and reason, and rejects unknown providers', function () {
    $reject = fn (JobDiscoverySource $source, string $id, string $title, DiscoveryRejectionReason $reason) => DiscoveryRejection::create([
        'source' => $source, 'source_job_id' => $id, 'title' => $title, 'company' => 'Acme', 'location' => 'USA',
        'remote_status' => 'remote', 'source_url' => 'https://example.test/'.$id, 'reason_code' => $reason,
        'matched_field' => 'title', 'evidence_snippet' => $title, 'first_seen_at' => now()->subDay(), 'last_seen_at' => now(), 'seen_count' => 1,
    ]);
    $reject(JobDiscoverySource::Jobicy, '1', 'Sales Lead', DiscoveryRejectionReason::TitleNotMatching);
    $reject(JobDiscoverySource::Jobicy, '2', 'Junior Dev', DiscoveryRejectionReason::ExcludedSeniority);
    $reject(JobDiscoverySource::Himalayas, '3', 'Sales Rep', DiscoveryRejectionReason::TitleNotMatching);

    $this->get('/discovery/rejections?provider=jobicy')->assertInertia(fn (Assert $page) => $page
        ->where('filters.provider', 'jobicy')->where('pagination.total', 2)
        ->has('providers', 5));
    $this->get('/discovery/rejections?provider=jobicy&reason=title_not_matching')->assertInertia(fn (Assert $page) => $page
        ->where('pagination.total', 1));
    $this->get('/discovery/rejections?provider=jobicy&q=sales')->assertInertia(fn (Assert $page) => $page
        ->where('pagination.total', 1)->where('filters.q', 'sales'));
    $this->get('/discovery/rejections?provider=bogus')->assertInertia(fn (Assert $page) => $page
        ->where('filters.provider', null)->where('pagination.total', 3));
    $this->get('/discovery/rejections?provider=manual')->assertInertia(fn (Assert $page) => $page
        ->where('filters.provider', null));
});

it('keeps Overview discovery health unchanged by the Sources page', function () {
    pageAttempt(JobDiscoverySource::Jobicy, DiscoveryStatus::Succeeded, 3, ['candidates_retrieved' => 100, 'candidates_accepted' => 8]);

    $overview = $this->get('/')->viewData('page')['props']['discovery'];
    $sources = collect($this->get('/sources')->viewData('page')['props']['providers'])->keyBy('provider');

    foreach ($overview['providers'] as $health) {
        expect($sources[$health['provider']]['health'])->toEqual($health);
    }
});

it('calls a scheduled provider overdue only once it has succeeded before', function () {
    pageAttempt(JobDiscoverySource::Himalayas, DiscoveryStatus::Succeeded, 24 * 5);

    $this->get('/sources')->assertInertia(fn (Assert $page) => $page
        ->where('providers.0.overdue', true)->where('providers.0.needs_attention', true)
        ->where('providers.2.overdue', false)->where('providers.2.needs_attention', false));
});
