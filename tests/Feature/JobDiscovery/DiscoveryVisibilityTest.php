<?php

use App\Enums\DiscoveryRejectionReason;
use App\Enums\DiscoveryStatus;
use App\Enums\JobDiscoverySource;
use App\Models\CareerProfile;
use App\Models\DiscoveryProviderAttempt;
use App\Models\DiscoveryRejection;
use App\Models\JobPosting;
use App\Support\JobDiscovery\RunJobDiscovery;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

function visAttempt(JobDiscoverySource $source, DiscoveryStatus $status, int $hoursAgo, array $attributes = []): DiscoveryProviderAttempt
{
    return DiscoveryProviderAttempt::factory()->create([
        'provider' => $source,
        'status' => $status,
        'started_at' => now()->subHours($hoursAgo),
        'finished_at' => now()->subHours($hoursAgo),
    ] + $attributes);
}

function visRejection(string $id, string $title, string $company = 'Acme', array $attributes = []): DiscoveryRejection
{
    return DiscoveryRejection::create($attributes + [
        'source' => JobDiscoverySource::Jobicy, 'source_job_id' => $id, 'title' => $title, 'company' => $company,
        'location' => 'USA', 'remote_status' => 'remote', 'source_url' => "https://jobicy.com/jobs/{$id}",
        'reason_code' => DiscoveryRejectionReason::TitleNotMatching, 'matched_field' => 'title', 'evidence_snippet' => $title,
        'first_seen_at' => now()->subDay(), 'last_seen_at' => now(), 'seen_count' => 1,
    ]);
}

beforeEach(fn () => CareerProfile::factory()->create());

it('shows an honest empty state when discovery has never run', function () {
    $this->get('/')->assertInertia(fn (Assert $page) => $page
        ->where('discovery.ever_ran', false)
        ->where('discovery.stale', true)
        ->where('discovery.active_rejections', 0)
        ->has('discovery.providers', 3)
        ->where('discovery.providers.0.last_success', null)
        ->where('discovery.providers.0.latest', null));
});

it('flags a scheduled provider whose last success is old, and not one that succeeded recently', function () {
    visAttempt(JobDiscoverySource::Jobicy, DiscoveryStatus::Succeeded, 3, ['candidates_retrieved' => 100, 'candidates_accepted' => 8, 'jobs_created' => 5, 'jobs_updated' => 3]);
    visAttempt(JobDiscoverySource::Adzuna, DiscoveryStatus::Succeeded, 24 * 5);

    $this->get('/')->assertInertia(fn (Assert $page) => $page
        ->where('discovery.ever_ran', true)
        ->where('discovery.stale', true)
        ->where('discovery.providers', fn ($providers) => collect($providers)->mapWithKeys(fn ($p) => [$p['provider'] => $p['stale']])->all()
            === ['himalayas' => true, 'adzuna' => true, 'jobicy' => false])
        ->where('discovery.providers.2.last_success.age_hours', 3)
        ->where('discovery.providers.2.last_success.retrieved', 100)
        ->where('discovery.providers.2.last_success.created', 5)
        ->where('discovery.providers.2.last_success.updated', 3));
});

it('is not stale once every scheduled provider has succeeded recently', function () {
    foreach ([JobDiscoverySource::Himalayas, JobDiscoverySource::Adzuna, JobDiscoverySource::Jobicy] as $source) {
        visAttempt($source, DiscoveryStatus::Succeeded, 2);
    }

    $this->get('/')->assertInertia(fn (Assert $page) => $page->where('discovery.stale', false));
});

it('treats a Partial attempt as the last success, labelled partial, and keeps a later failure visible', function () {
    visAttempt(JobDiscoverySource::Eightfold, DiscoveryStatus::Partial, 3, ['candidates_retrieved' => 10, 'candidates_accepted' => 4, 'failure_message' => 'Eightfold: one tenant degraded.']);

    $this->get('/')->assertInertia(fn (Assert $page) => $page
        ->where('discovery.providers', fn ($providers) => collect($providers)->contains(fn ($p) => $p['provider'] === 'eightfold'
            && $p['last_success']['status'] === 'partial'
            && $p['last_success']['partial_summary'] === 'Eightfold: one tenant degraded.'
            && $p['last_success']['accepted'] === 4
            && $p['latest']['is_last_success'] === true)));

    visAttempt(JobDiscoverySource::Eightfold, DiscoveryStatus::Failed, 1, ['failure_message' => 'Eightfold: every tenant failed.']);

    $this->get('/')->assertInertia(fn (Assert $page) => $page
        ->where('discovery.providers', fn ($providers) => collect($providers)->contains(fn ($p) => $p['provider'] === 'eightfold'
            && $p['last_success']['status'] === 'partial'
            && $p['latest']['status'] === 'failed'
            && $p['latest']['is_last_success'] === false)));
});

it('keeps the last success visible and reports a failure since', function () {
    visAttempt(JobDiscoverySource::Jobicy, DiscoveryStatus::Succeeded, 30, ['candidates_retrieved' => 100, 'candidates_accepted' => 9]);
    visAttempt(JobDiscoverySource::Jobicy, DiscoveryStatus::Failed, 1, ['failure_message' => 'Jobicy request failed with HTTP status 500.']);

    $this->get('/')->assertInertia(fn (Assert $page) => $page
        ->where('discovery.providers.2.last_success.age_hours', 30)
        ->where('discovery.providers.2.last_success.accepted', 9)
        ->where('discovery.providers.2.latest.status', 'failed')
        ->where('discovery.providers.2.latest.is_last_success', false)
        ->where('discovery.providers.2.latest.failure', 'Jobicy request failed with HTTP status 500.'));
});

it('reports filter counts by reason, and null for attempts that never recorded them', function () {
    visAttempt(JobDiscoverySource::Jobicy, DiscoveryStatus::Succeeded, 2, ['rejection_counts' => ['title_not_matching' => 80, 'excluded_seniority' => 4]]);
    visAttempt(JobDiscoverySource::Adzuna, DiscoveryStatus::Succeeded, 2);

    $this->get('/')->assertInertia(fn (Assert $page) => $page
        ->where('discovery.providers.2.last_success.filtered', 84)
        ->where('discovery.providers.2.last_success.filtered_by_reason.0', ['code' => 'title_not_matching', 'label' => 'Title is not a target role', 'count' => 80])
        ->where('discovery.providers.1.last_success.filtered', null));
});

it('lists a manual-only provider that has run, without calling it stale', function () {
    visAttempt(JobDiscoverySource::Eightfold, DiscoveryStatus::Succeeded, 24 * 20, ['candidates_retrieved' => 10]);

    $this->get('/')->assertInertia(fn (Assert $page) => $page
        ->has('discovery.providers', 4)
        ->where('discovery.providers.3.provider', 'eightfold')
        ->where('discovery.providers.3.scheduled', false)
        ->where('discovery.providers.3.stale', false)
        ->where('discovery.providers.3.last_success.retrieved_basis', 'after_prefilter'));
});

it('lists active rejections newest sighting first, with the evidence that explains them', function () {
    visRejection('1', 'Warehouse Associate', 'Acme', ['last_seen_at' => now()->subDay()]);
    visRejection('2', 'Junior Software Engineer', 'Initech', ['reason_code' => DiscoveryRejectionReason::ExcludedSeniority, 'matched_term' => 'junior', 'seen_count' => 3]);

    $this->get('/discovery/rejections')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('discovery/rejections')
        ->where('pagination.total', 2)
        ->where('rejections.0.title', 'Junior Software Engineer')
        ->where('rejections.0.reason', ['code' => 'excluded_seniority', 'label' => 'Junior or intern role'])
        ->where('rejections.0.matched_term', 'junior')
        ->where('rejections.0.seen_count', 3)
        ->where('rejections.0.source_url', 'https://jobicy.com/jobs/2')
        ->where('rejections.0.provider', 'jobicy')
        ->where('rejections.1.title', 'Warehouse Associate'));
});

it('hides resolved rejections and non-http source links', function () {
    visRejection('1', 'Was Rejected Once', 'Acme', ['resolved_at' => now()]);
    visRejection('2', 'Odd Link', 'Acme', ['source_url' => 'javascript:alert(1)']);

    $this->get('/discovery/rejections')->assertInertia(fn (Assert $page) => $page
        ->where('pagination.total', 1)
        ->where('rejections.0.title', 'Odd Link')
        ->where('rejections.0.source_url', null));
});

it('searches by title or company and filters by reason', function () {
    visRejection('1', 'Warehouse Associate', 'Acme');
    visRejection('2', 'Account Manager', 'Initech');
    visRejection('3', 'Junior Engineer', 'Hooli', ['reason_code' => DiscoveryRejectionReason::ExcludedSeniority]);

    $this->get('/discovery/rejections?q=ware')->assertInertia(fn (Assert $page) => $page->where('pagination.total', 1)->where('rejections.0.title', 'Warehouse Associate')->where('filters.q', 'ware'));
    $this->get('/discovery/rejections?q=initech')->assertInertia(fn (Assert $page) => $page->where('pagination.total', 1)->where('rejections.0.company', 'Initech'));
    $this->get('/discovery/rejections?reason=excluded_seniority')->assertInertia(fn (Assert $page) => $page->where('pagination.total', 1)->where('rejections.0.title', 'Junior Engineer')->where('filters.reason', 'excluded_seniority'));
    $this->get('/discovery/rejections?reason=bogus')->assertInertia(fn (Assert $page) => $page->where('pagination.total', 3)->where('filters.reason', 'all'));
    $this->get('/discovery/rejections?q=nothing-matches')->assertInertia(fn (Assert $page) => $page->where('pagination.total', 0)->where('rejections', []));
});

it('paginates on the server and clamps an out-of-range page', function () {
    foreach (range(1, 30) as $n) {
        visRejection((string) $n, "Role {$n}", 'Acme', ['last_seen_at' => now()->subMinutes($n)]);
    }

    $this->get('/discovery/rejections')->assertInertia(fn (Assert $page) => $page
        ->has('rejections', 25)->where('pagination.total', 30)->where('pagination.last_page', 2)->where('pagination.from', 1)->where('pagination.to', 25));
    $this->get('/discovery/rejections?page=2')->assertInertia(fn (Assert $page) => $page
        ->has('rejections', 5)->where('pagination.page', 2)->where('pagination.from', 26)->where('pagination.to', 30));
    $this->get('/discovery/rejections?page=99')->assertInertia(fn (Assert $page) => $page->where('pagination.page', 2));
});

it('keeps rejected candidates out of the Jobs inbox and its counts, and out of the Overview payload', function () {
    JobPosting::factory()->create(['title' => 'Real Job', 'career_profile_id' => CareerProfile::first()->id]);
    visRejection('1', 'Warehouse Associate');

    $this->get('/jobs?disposition=all')->assertInertia(fn (Assert $page) => $page
        ->where('pagination.total', 1)
        ->where('jobs', fn ($jobs) => collect($jobs)->pluck('title')->all() === ['Real Job']));

    $this->get('/')->assertInertia(fn (Assert $page) => $page
        ->where('overview.jobs', 1)
        ->where('discovery.active_rejections', 1)
        ->missing('discovery.rejections'));
    expect(JobPosting::count())->toBe(1);
});

it('lights Overview as the active section on the rejections page', function () {
    $this->get('/discovery/rejections')->assertInertia(fn (Assert $page) => $page->where('nav.section', 'home'));
});

it('ingests Adzuna preview postings with unknown remote status and keeps them recognizable in Jobs', function () {
    config(['services.adzuna.app_id' => 'id', 'services.adzuna.app_key' => 'key', 'services.adzuna.country' => 'us']);
    Http::fake([
        'himalayas.app/*' => Http::response(['jobs' => []]),
        'jobicy.com/*' => Http::response(['jobs' => []]),
        'api.adzuna.com/*' => Http::response(['results' => [[
            'id' => '9001', 'title' => 'PHP Developer', 'description' => 'A short preview of the role…', 'company' => ['display_name' => 'Acme'],
            'location' => ['display_name' => 'West Virginia, US'], 'redirect_url' => 'https://www.adzuna.com/land/ad/9001', 'created' => '2026-11-01T12:00:00Z',
        ]]])]);

    (new RunJobDiscovery)->run([JobDiscoverySource::Adzuna]);

    $job = JobPosting::sole();
    expect($job->remote_status)->toBeNull()
        ->and($job->description_completeness->value)->toBe('preview')
        ->and($job->location)->toBe('West Virginia, US')
        ->and(DiscoveryRejection::count())->toBe(0);

    $this->get('/jobs?disposition=all')->assertInertia(fn (Assert $page) => $page
        ->where('jobs.0.discovery.description_completeness', 'preview')
        ->where('jobs.0.discovery.remote_status', null));
});
