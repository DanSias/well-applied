<?php

use App\Enums\DiscoveryRejectionReason;
use App\Enums\JobDiscoverySource;
use App\Enums\JobDisposition;
use App\Models\CareerProfile;
use App\Models\DiscoveryRejection;
use App\Models\JobPosting;
use App\Support\JobDiscovery\DiscoveredJobCandidate;
use App\Support\JobDiscovery\DiscoveryRejectionLog;
use App\Support\JobDiscovery\FilterDecision;
use App\Support\JobDiscovery\RunJobDiscovery;
use Illuminate\Support\Carbon;
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
        'job_discovery.search.employment_type' => null,
        'services.adzuna.app_id' => 'test-id',
        'services.adzuna.app_key' => 'test-key',
    ]);
});

function rejJob(int $id, string $title, string $geo = 'USA'): array
{
    return [
        'id' => $id,
        'jobTitle' => $title,
        'companyName' => 'Acme',
        'jobDescription' => '<p>Build things. '.str_repeat('Long private description text. ', 200).'</p>',
        'jobGeo' => $geo,
        'jobType' => ['Full-Time'],
        'url' => "https://jobicy.com/jobs/{$id}",
        'pubDate' => '2026-09-01T12:00:00+00:00',
    ];
}

/**
 * Http::fake() stubs accumulate and the first match wins, so one stub reads the jobs the current run should see.
 *
 * @param  array<int, array<string, mixed>>|null  $set
 * @return array<int, array<string, mixed>>
 */
function rejFeed(?array $set = null): array
{
    static $jobs = [];
    if ($set !== null) {
        $jobs = $set;
    }

    return $jobs;
}

function rejRun(array $jobicyJobs): void
{
    rejFeed($jobicyJobs);
    Http::fake([
        'himalayas.app/*' => Http::response(['jobs' => []]),
        'api.adzuna.com/*' => Http::response(['results' => []]),
        'jobicy.com/*' => fn () => Http::response(['jobs' => rejFeed()]),
    ]);

    (new RunJobDiscovery)->run();
}

it('records a rejected candidate without creating a job posting', function () {
    rejRun([rejJob(1, 'Warehouse Associate'), rejJob(2, 'Senior Software Engineer')]);

    $rejection = DiscoveryRejection::sole();

    expect($rejection->source)->toBe(JobDiscoverySource::Jobicy)
        ->and($rejection->source_job_id)->toBe('1')
        ->and($rejection->title)->toBe('Warehouse Associate')
        ->and($rejection->company)->toBe('Acme')
        ->and($rejection->location)->toBe('USA')
        ->and($rejection->remote_status)->toBe('remote')
        ->and($rejection->source_url)->toBe('https://jobicy.com/jobs/1')
        ->and($rejection->reason_code)->toBe(DiscoveryRejectionReason::TitleNotMatching)
        ->and($rejection->matched_field)->toBe('title')
        ->and($rejection->evidence_snippet)->toBe('Warehouse Associate')
        ->and($rejection->seen_count)->toBe(1)
        ->and($rejection->resolved_at)->toBeNull()
        ->and($rejection->last_discovery_run_id)->not->toBeNull()
        ->and(JobPosting::pluck('title')->all())->toBe(['Senior Software Engineer'])
        ->and(collect($rejection->getAttributes())->implode(' '))->not->toContain('Long private description');
});

it('keeps one row per provider identity and counts repeated sightings', function () {
    Carbon::setTestNow('2026-11-01 09:00:00');
    rejRun([rejJob(1, 'Warehouse Associate')]);
    Carbon::setTestNow('2026-11-02 09:00:00');
    rejRun([rejJob(1, 'Warehouse Associate')]);
    Carbon::setTestNow('2026-11-03 09:00:00');
    rejRun([rejJob(1, 'Warehouse Associate'), rejJob(3, 'Warehouse Associate')]);

    $first = DiscoveryRejection::where('source_job_id', '1')->sole();

    expect(DiscoveryRejection::count())->toBe(2)
        ->and($first->seen_count)->toBe(3)
        ->and($first->first_seen_at->toDateTimeString())->toBe('2026-11-01 09:00:00')
        ->and($first->last_seen_at->toDateTimeString())->toBe('2026-11-03 09:00:00')
        ->and(DiscoveryRejection::where('source_job_id', '3')->sole()->seen_count)->toBe(1);

    Carbon::setTestNow();
});

it('does not merge the same id from different providers', function () {
    DiscoveryRejection::create([
        'source' => JobDiscoverySource::Himalayas, 'source_job_id' => '1', 'title' => 'X', 'company' => 'Y',
        'reason_code' => DiscoveryRejectionReason::TitleNotMatching, 'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);

    rejRun([rejJob(1, 'Warehouse Associate')]);

    expect(DiscoveryRejection::count())->toBe(2);
});

it('updates the current reason when a candidate is rejected for a different rule', function () {
    rejRun([rejJob(1, 'Warehouse Associate')]);
    expect(DiscoveryRejection::sole()->reason_code)->toBe(DiscoveryRejectionReason::TitleNotMatching);

    rejRun([rejJob(1, 'Junior Software Engineer')]);

    $rejection = DiscoveryRejection::sole();
    expect($rejection->reason_code)->toBe(DiscoveryRejectionReason::ExcludedSeniority)
        ->and($rejection->matched_term)->toBe('junior')
        ->and($rejection->title)->toBe('Junior Software Engineer')
        ->and($rejection->seen_count)->toBe(2);
});

it('resolves a rejection when the candidate is later accepted, and reopens it if rejected again', function () {
    rejRun([rejJob(1, 'Staff Platform Lead')]);
    expect(DiscoveryRejection::active()->count())->toBe(1)->and(JobPosting::count())->toBe(0);

    rejRun([rejJob(1, 'Staff Software Engineer')]);

    $rejection = DiscoveryRejection::sole();
    expect($rejection->resolved_at)->not->toBeNull()
        ->and($rejection->reason_code)->toBe(DiscoveryRejectionReason::TitleNotMatching)
        ->and($rejection->seen_count)->toBe(1)
        ->and(DiscoveryRejection::active()->count())->toBe(0)
        ->and(JobPosting::count())->toBe(1);

    rejRun([rejJob(1, 'Junior Software Engineer')]);

    expect(DiscoveryRejection::sole()->resolved_at)->toBeNull()
        ->and(DiscoveryRejection::sole()->seen_count)->toBe(2)
        // The job it once became is untouched by the later rejection.
        ->and(JobPosting::sole()->title)->toBe('Staff Software Engineer');
});

it('never creates or updates a job posting for a rejected candidate and leaves decisions intact', function () {
    rejRun([rejJob(1, 'Senior Software Engineer')]);
    $job = JobPosting::sole();
    $job->forceFill(['disposition' => JobDisposition::Shortlisted, 'last_checked_at' => '2026-01-01 00:00:00'])->saveQuietly();
    $before = $job->fresh()->getAttributes();

    // The same provider identity now comes back rejected (for example after a title edit or a rule change).
    rejRun([rejJob(1, 'Warehouse Associate')]);

    expect(JobPosting::count())->toBe(1)
        ->and(JobPosting::sole()->getAttributes())->toBe($before)
        ->and(JobPosting::sole()->disposition)->toBe(JobDisposition::Shortlisted)
        ->and(DiscoveryRejection::active()->count())->toBe(1);
});

it('bounds stored text and keeps no description', function () {
    rejRun([rejJob(1, str_repeat('Warehouse ', 60), str_repeat('Berlin, Germany; ', 40))]);

    $rejection = DiscoveryRejection::sole();
    expect(mb_strlen($rejection->title))->toBeLessThanOrEqual(255)
        ->and(mb_strlen($rejection->location))->toBeLessThanOrEqual(255)
        ->and(mb_strlen($rejection->evidence_snippet))->toBeLessThanOrEqual(240);
});

it('does not fail the provider attempt when a rejection cannot be written', function () {
    $log = new class extends DiscoveryRejectionLog
    {
        public function record(DiscoveredJobCandidate $candidate, FilterDecision $decision, ?int $discoveryRunId): void
        {
            throw new RuntimeException('disk full');
        }
    };

    rejFeed([rejJob(1, 'Warehouse Associate'), rejJob(2, 'Senior Software Engineer')]);
    Http::fake([
        'himalayas.app/*' => Http::response(['jobs' => []]),
        'api.adzuna.com/*' => Http::response(['results' => []]),
        'jobicy.com/*' => fn () => Http::response(['jobs' => rejFeed()]),
    ]);

    $run = (new RunJobDiscovery(rejections: $log))->run();

    expect($run->status->value)->toBe('succeeded')
        ->and(JobPosting::count())->toBe(1);
});
