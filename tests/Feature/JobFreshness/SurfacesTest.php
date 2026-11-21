<?php

use App\Enums\DescriptionCompleteness;
use App\Enums\JobDiscoverySource;
use App\Enums\JobDisposition;
use App\Enums\JobPostingLifecycleStatus;
use App\Models\Application;
use App\Models\CareerProfile;
use App\Models\JobPosting;
use App\Support\JobDiscovery\DiscoveredJobCandidate;
use App\Support\JobDiscovery\IngestDiscoveredCandidate;
use App\Support\JobFreshness\CheckRequests;
use App\Support\JobFreshness\FreshnessState;
use App\Support\JobFreshness\JobFreshness;
use App\Support\JobFreshness\LivenessRecord;
use App\Support\JobFreshness\Observation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

function frJob(array $attributes = [], ?int $expiresAt = null, array $metadata = []): JobPosting
{
    $discovery = array_filter(['expiryDate' => $expiresAt, 'companySlug' => 'acme'], fn ($v) => $v !== null);

    return JobPosting::factory()->create($attributes + [
        'career_profile_id' => CareerProfile::query()->firstOrCreate([], CareerProfile::factory()->make()->toArray())->id,
        'discovery_source' => JobDiscoverySource::Himalayas, 'discovery_source_id' => 'g-'.uniqid(),
        'source_url' => 'https://himalayas.app/companies/acme/jobs/engineer-'.uniqid(),
        'discovery_metadata' => ['discovery' => $discovery] + $metadata,
    ]);
}

function frOn(bool $on = true): void
{
    config(['job_freshness.enabled' => $on]);
}

$past = fn () => now()->subDay()->timestamp;
$future = fn () => now()->addWeek()->timestamp;

// ---- default off --------------------------------------------------------------------------------------------------------------------------

it('is off by default and changes nothing: no freshness in any payload, no job hidden, Home unchanged', function () use ($past) {
    $expired = frJob(['disposition' => JobDisposition::New], $past());

    expect(config('job_freshness.enabled'))->toBeFalse()->and(JobFreshness::view($expired))->toBeNull();

    $jobs = collect($this->get('/jobs')->viewData('page')['props']);
    expect(collect($jobs['jobs'])->pluck('id')->all())->toContain($expired->id)
        ->and($jobs['hidden_inactive'])->toBe(0)->and($jobs['filters']['inactive'])->toBeFalse()
        ->and(collect($jobs['jobs'])->firstWhere('id', $expired->id)['freshness'])->toBeNull();
    expect(file_get_contents(base_path('routes/console.php')).file_get_contents(base_path('bootstrap/app.php')))->not->toContain('freshness');
});

// ---- the read model ------------------------------------------------------------------------------------------------------------------------

it('derives known expiry from the provider\'s own stored expiryDate, with no request and no write', function () use ($past, $future) {
    Http::fake();
    $queries = [];
    DB::listen(function ($q) use (&$queries) {
        $queries[] = $q->sql;
    });

    $expired = frJob([], $past());
    $open = frJob([], $future());
    $none = frJob([], null);
    $queries = [];

    expect(JobFreshness::decide($expired)->state)->toBe(FreshnessState::Expired)
        ->and(JobFreshness::decide($open)->state)->toBe(FreshnessState::Unchecked)
        ->and(JobFreshness::decide($none)->state)->toBe(FreshnessState::Unchecked);
    Http::assertNothingSent();
    expect(collect($queries)->filter(fn ($q) => preg_match('/^(insert|update|delete)/i', $q))->all())->toBe([]);
});

it('reads an expiry only from Himalayas, never from another source\'s metadata', function () use ($past) {
    $jobicy = frJob(['discovery_source' => JobDiscoverySource::Jobicy, 'source_url' => 'https://jobicy.com/jobs/1-x'], $past());

    expect(JobFreshness::decide($jobicy)->state)->toBe(FreshnessState::Unchecked);
});

it('uses recorded observations beside the expiry, and ignores a malformed record', function () {
    $observations = [
        (new Observation('himalayas', 'job_endpoint', 'closed', 'http_410', CarbonImmutable::now()->subDays(2)))->toArray(),
        (new Observation('himalayas', 'job_endpoint', 'closed', 'http_410', CarbonImmutable::now()->subHours(7)))->toArray(),
        'garbage',
    ];
    $job = frJob([], null, ['liveness' => ['observations' => $observations]]);

    expect(JobFreshness::decide($job)->state)->toBe(FreshnessState::Closed)->and(JobFreshness::decide($job)->action)->toBe('set_closed');
});

it('labels a pursued job or one with an Application, and never turns it into a status-changing action', function () use ($past) {
    $pursued = frJob(['disposition' => JobDisposition::Pursued], $past());
    $applied = frJob(['disposition' => JobDisposition::Shortlisted], $past());
    Application::factory()->create(['job_posting_id' => $applied->id]);

    foreach ([$pursued, $applied] as $job) {
        $d = JobFreshness::decide($job);
        expect([$d->state, $d->action, $d->protected])->toBe([FreshnessState::Expired, 'review', true]);
    }
    expect(JobFreshness::decide(frJob(['disposition' => JobDisposition::New], $past()))->action)->toBe('set_closed');
});

it('does NOT reopen a closed job from rediscovered, fresher-looking data', function () use ($future) {
    $profile = CareerProfile::factory()->create();
    $job = JobPosting::factory()->create([
        'career_profile_id' => $profile->id, 'discovery_source' => JobDiscoverySource::Himalayas, 'discovery_source_id' => 'g-1', 'source_url' => 'https://himalayas.app/companies/acme/jobs/x-1',
        'status' => JobPostingLifecycleStatus::Closed, 'discovery_metadata' => ['discovery' => ['expiryDate' => 1], 'liveness' => ['schema' => 1, 'observations' => [], 'decision' => ['state' => 'closed', 'action' => 'set_closed', 'reason' => 'closure_confirmed']]],
    ]);

    $candidate = new DiscoveredJobCandidate(
        source: JobDiscoverySource::Himalayas, sourceJobId: 'g-1', company: 'Acme', title: 'Engineer', description: 'x', descriptionCompleteness: DescriptionCompleteness::Complete,
        location: null, remoteStatus: null, employmentType: null, compensationMin: null, compensationMax: null, compensationCurrency: null, compensationInterval: null,
        applicationUrl: 'https://himalayas.app/companies/acme/jobs/x-1', postedAt: null, sourceUpdatedAt: null, discoveredAt: CarbonImmutable::now(),
        sourceMetadata: ['expiryDate' => $future(), 'companySlug' => 'acme'],
    );
    (new IngestDiscoveredCandidate)->ingest($candidate, null, $profile);

    $job->refresh();
    expect($job->status)->toBe(JobPostingLifecycleStatus::Closed, 'discovery never touches the lifecycle status')
        ->and($job->discovery_metadata['discovery']['expiryDate'])->toBe($future())
        ->and($job->discovery_metadata['liveness']['decision']['state'])->toBe('closed', 'the stored liveness record survives a rediscovery');
    expect(JobFreshness::decide($job)->state)->toBe(FreshnessState::Closed);
});

it('preserves the original applicationLink and the provider attribution: freshness changes neither', function () use ($past) {
    $url = 'https://himalayas.app/companies/acme/jobs/engineer-keep?ref=abc&utm_source=feed';
    $job = frJob(['source_url' => $url], $past());
    $before = [$job->source_url, $job->only(['status', 'disposition', 'title'])];

    frOn();
    JobFreshness::view($job);
    JobFreshness::decide($job);
    $row = collect($this->get('/jobs?disposition=all&inactive=1')->viewData('page')['props']['jobs'])->firstWhere('id', $job->id);

    expect($row['source_url'])->toBe($url)->and($row['attribution']['provider'])->toBe('himalayas')->and($row['attribution']['link_label'])->toBe('Apply on Himalayas ↗');
    $job->refresh();
    expect([$job->source_url, $job->only(['status', 'disposition', 'title'])])->toBe($before);
});

// ---- presentation (feature on) -----------------------------------------------------------------------------------------------------------

it('shows the state on every payload a job appears in', function () use ($past) {
    frOn();
    $expired = frJob(['disposition' => JobDisposition::Pursued, 'pursued_at' => now()], $past());

    $row = collect($this->get('/opportunities')->viewData('page')['props']['opportunities'])->firstWhere('id', $expired->id);
    $drawer = $this->get('/opportunities?inspect='.$expired->id)->viewData('page')['props']['inspection_job'];
    $selected = $this->get('/jobs?job='.$expired->id)->viewData('page')['props']['selected_job'];

    foreach ([$row, $drawer, $selected] as $payload) {
        expect($payload['freshness'])->toMatchArray(['state' => 'expired', 'label' => 'Expired', 'actionable' => false, 'reason' => 'expired_known', 'tone' => 'warn']);
    }
    expect(collect($this->get('/opportunities')->viewData('page')['props']['opportunities'])->pluck('id')->all())->toContain($expired->id);   // a pursued job stays in Opportunities, labelled
});

it('leaves expired and closed jobs out of the Inbox and Shortlist, says how many, and shows them on request', function () use ($past, $future) {
    frOn();
    $live = frJob(['disposition' => JobDisposition::New], $future());
    $expired = frJob(['disposition' => JobDisposition::New], $past());
    $closed = frJob(['disposition' => JobDisposition::New, 'status' => JobPostingLifecycleStatus::Closed], $future());
    $shortlistedExpired = frJob(['disposition' => JobDisposition::Shortlisted], $past());
    $withApplication = frJob(['disposition' => JobDisposition::New], $past());
    Application::factory()->create(['job_posting_id' => $withApplication->id]);

    $inbox = $this->get('/jobs')->viewData('page')['props'];
    expect(collect($inbox['jobs'])->pluck('id')->sort()->values()->all())->toBe(collect([$live->id, $withApplication->id])->sort()->values()->all())
        ->and($inbox['hidden_inactive'])->toBe(2)->and($inbox['pagination']['total'])->toBe(2);

    $shown = $this->get('/jobs?inactive=1')->viewData('page')['props'];
    expect(collect($shown['jobs'])->pluck('id')->all())->toContain($expired->id, $closed->id)->and($shown['filters']['inactive'])->toBeTrue()->and($shown['pagination']['total'])->toBe(4);

    $shortlist = $this->get('/jobs?disposition=shortlist')->viewData('page')['props'];
    expect(collect($shortlist['jobs'])->pluck('id')->all())->not->toContain($shortlistedExpired->id)->and($shortlist['hidden_inactive'])->toBe(1);

    $all = $this->get('/jobs?disposition=all')->viewData('page')['props'];
    expect(collect($all['jobs'])->pluck('id')->all())->toContain($expired->id, $closed->id, $shortlistedExpired->id)->and($all['hidden_inactive'])->toBe(0, 'other views are untouched');
});

it('deletes nothing: every job is still stored after it is hidden', function () use ($past) {
    frOn();
    $job = frJob(['disposition' => JobDisposition::New], $past());
    $before = JobPosting::count();

    $this->get('/jobs');
    expect(JobPosting::count())->toBe($before)->and(JobPosting::find($job->id))->not->toBeNull();
});

it('drops an expired job from Home\'s open work, but never one with an Application, and only when the feature is on', function () use ($past, $future) {
    frJob(['disposition' => JobDisposition::New], $future());
    frJob(['disposition' => JobDisposition::New], $past());
    $kept = frJob(['disposition' => JobDisposition::New], $past());
    Application::factory()->create(['job_posting_id' => $kept->id]);

    $off = $this->get('/')->viewData('page')['props']['overview'];
    expect([$off['jobs'], $off['awaiting']])->toBe([3, 3]);

    frOn();
    $on = $this->get('/')->viewData('page')['props']['overview'];
    expect([$on['jobs'], $on['actionable'], $on['left_out'], $on['awaiting']])->toBe([3, 2, 1, 2], 'jobs is the total; the expired inbox job is left out of what is actionable');
    expect(array_key_exists('actionable', $off))->toBeFalse()->and(array_key_exists('left_out', $off))->toBeFalse();
});

// ---- the checks a later runner would make (specifications only) ------------------------------------------------------------------------------

it('builds the Himalayas check from the stored link and company slug, reading and never changing the applicationLink', function () {
    config(['services.himalayas.base_url' => 'https://himalayas.app/jobs/api']);
    $job = frJob(['source_url' => 'https://himalayas.app/companies/acme/jobs/senior-engineer-123']);

    expect(CheckRequests::himalayas($job))->toMatchArray(['method' => 'GET', 'url' => 'https://himalayas.app/jobs/api/companies/acme/jobs/senior-engineer-123']);
    expect($job->fresh()->source_url)->toBe('https://himalayas.app/companies/acme/jobs/senior-engineer-123');

    foreach (['https://himalayas.app/companies/other/jobs/x', 'https://himalayas.app/jobs/x', 'https://example.com/x', null] as $bad) {
        expect(CheckRequests::himalayas(frJob(['source_url' => $bad])))->toBeNull($bad ?? 'null');
    }
    expect(CheckRequests::himalayas(frJob(['discovery_source' => JobDiscoverySource::Jobicy])))->toBeNull();
});

it('batches Jobicy ids 100 to a request, numeric ids only, and never for another source', function () {
    config(['job_freshness.jobicy_status_url' => 'https://jobicy.example/api/jobs/status']);
    $jobs = [];
    foreach (range(1, 205) as $i) {
        $jobs[] = JobPosting::factory()->make(['discovery_source' => JobDiscoverySource::Jobicy, 'discovery_source_id' => (string) (5000 + $i)]);
    }
    $jobs[] = JobPosting::factory()->make(['discovery_source' => JobDiscoverySource::Jobicy, 'discovery_source_id' => 'not-numeric']);
    $jobs[] = JobPosting::factory()->make(['discovery_source' => JobDiscoverySource::Himalayas, 'discovery_source_id' => '7']);

    $batches = CheckRequests::jobicyBatches($jobs);

    expect($batches)->toHaveCount(3)->and(array_map(fn ($b) => count($b['ids']), $batches))->toBe([100, 100, 5])
        ->and($batches[0]['url'])->toStartWith('https://jobicy.example/api/jobs/status?ids=5001,5002,')->and($batches[0]['limits'])->toContain('1 request/second');
});

it('does not touch the existing Jobicy discovery provider or the ingestion path\'s status handling', function () {
    $provider = file_get_contents(base_path('app/Support/JobDiscovery/Providers/JobicyDiscoveryProvider.php'));
    expect($provider)->not->toContain('JobFreshness')->and(file_get_contents(base_path('app/Support/JobDiscovery/IngestDiscoveredCandidate.php')))->not->toContain("'status'");
    expect(trim((string) shell_exec('git -C '.escapeshellarg(base_path()).' diff --name-only master -- app/Support/JobDiscovery/Providers/JobicyDiscoveryProvider.php app/Support/JobDiscovery/IngestDiscoveredCandidate.php app/Support/JobDiscovery/RunJobDiscovery.php app/Models 2>/dev/null')))->toBe('');
});

it('records observations only through the pure record builder: nothing in B3 writes liveness', function () {
    $src = collect(glob(base_path('app/Support/JobFreshness/*.php')))->push(base_path('app/Console/Commands/JobsFreshnessReport.php'))->map(fn ($f) => file_get_contents($f))->implode("\n");

    expect($src)->not->toMatch('/->(save|update|delete|forceDelete|insert|create)\(|DB::(table|update|insert|delete)|Http::|file_put_contents|->put\(/');
    expect(LivenessRecord::SCHEMA)->toBe(1);
});
