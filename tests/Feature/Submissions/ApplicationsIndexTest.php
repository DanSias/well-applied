<?php

use App\Enums\JobDisposition;
use App\Enums\SubmissionEventType;
use App\Models\Application;
use App\Models\CareerProfile;
use App\Models\JobAnalysis;
use App\Models\JobMatch;
use App\Models\JobPosting;
use App\Models\JobResumeSelection;
use App\Models\ResumeProfile;
use App\Models\ResumeProfileSynthesis;
use App\Models\ResumeVariant;
use App\Models\Submission;
use App\Support\Navigation\JobHome;
use App\Support\Submissions\RecordSubmissionEvent;
use Inertia\Testing\AssertableInertia as Assert;

function idxProfile(): CareerProfile
{
    return CareerProfile::query()->oldest('id')->first() ?? CareerProfile::factory()->create();
}

function idxJob(array $attributes = []): JobPosting
{
    return JobPosting::factory()->create(['career_profile_id' => idxProfile()->id, 'disposition' => JobDisposition::Pursued, 'pursued_at' => now(), ...$attributes]);
}

function idxSubmission(JobPosting $job, string $on, array $attributes = []): Submission
{
    return Submission::factory()->create(['job_posting_id' => $job->id, 'submitted_at' => $on, ...$attributes]);
}

function idxEvent(Submission $submission, SubmissionEventType $type, $at = null): void
{
    app(RecordSubmissionEvent::class)->record($submission, $type, $at ?? now());
}

function idxProps($test): array
{
    return $test->get('/applications')->assertOk()->viewData('page')['props'];
}

it('renders the Applications page for the current profile', function () {
    idxProfile();

    $this->get(route('applications.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('applications/index')
        ->where('applications', [])
        ->where('in_error', [])
        ->where('counts.recorded', 0)
        ->where('counts.in_progress', 0));
});

it('lives at /applications, apart from the inspection record at /applications/{id}', function () {
    $job = idxJob();
    $inspection = Application::factory()->for($job, 'jobPosting')->create();

    expect(route('applications.index', absolute: false))->toBe('/applications')
        ->and(route('applications.show', $inspection, absolute: false))->toBe("/applications/{$inspection->id}");
    $this->get(route('applications.show', $inspection))->assertOk()->assertInertia(fn (Assert $page) => $page->component('applications/show'));
});

it('is empty when nothing was recorded, even if jobs were inspected', function () {
    $job = idxJob();
    Application::factory()->for($job, 'jobPosting')->create();

    expect(idxProps($this)['applications'])->toBe([]);
});

it('shows one recorded application with company, title, date, derived status and resume', function () {
    $job = idxJob(['title' => 'Staff Engineer', 'company' => 'Acme']);
    $submission = idxSubmission($job, '2026-11-10 05:00:00');
    idxEvent($submission, SubmissionEventType::ConfirmationReceived, now()->subHour());

    $row = idxProps($this)['applications'][0];

    expect($row)->toMatchArray([
        'id' => $submission->id,
        'phase' => 'confirmed',
        'phase_label' => 'Confirmed',
        'active' => true,
        'in_error' => false,
        'method' => 'manual',
        'resume' => null,
        'attempt' => 1,
        'attempts' => 1,
    ])->and($row['job'])->toMatchArray(['id' => $job->id, 'title' => 'Staff Engineer', 'company' => 'Acme', 'pursued' => true])
        ->and($row['submitted_at'])->toBe($submission->submitted_at->toIso8601String());
});

it('names the resume used, and a PDF that was uploaded', function () {
    $job = idxJob();
    $analysis = JobAnalysis::factory()->for($job, 'jobPosting')->create();
    $profile = idxProfile();
    $resumeProfile = ResumeProfile::query()->create(['career_profile_id' => $profile->id, 'slug' => 'idx', 'name' => 'Idx', 'role_family' => 'engineering']);
    $synthesis = ResumeProfileSynthesis::query()->create(['career_profile_id' => $profile->id, 'resume_profile_id' => $resumeProfile->id, 'version' => 1, 'status' => 'approved', 'evidence_revision' => 0, 'input_hash' => 'x', 'content' => []]);
    $variant = ResumeVariant::factory()->create([
        'career_profile_id' => $profile->id, 'job_analysis_id' => $analysis->id, 'resume_profile_synthesis_id' => $synthesis->id,
        'job_match_id' => JobMatch::factory()->create(['job_analysis_id' => $analysis->id, 'career_profile_id' => $profile->id])->id,
    ]);
    JobResumeSelection::query()->create(['job_posting_id' => $job->id, 'current_resume_variant_id' => $variant->id]);
    idxSubmission($job, '2026-11-01 05:00:00', ['resume_variant_id' => $variant->id, 'submitted_pdf_sha256' => str_repeat('a', 64)]);
    $upload = idxJob();
    idxSubmission($upload, '2026-11-02 05:00:00', ['submitted_pdf_sha256' => str_repeat('b', 64)]);

    $rows = collect(idxProps($this)['applications'])->keyBy(fn ($r) => $r['job']['id']);

    expect($rows[$job->id]['resume'])->toBe('Generated resume 1')->and($rows[$upload->id]['resume'])->toBe('A PDF you uploaded');
});

it('sorts the newest submission first, whatever order they were recorded in', function () {
    $a = idxJob();
    $b = idxJob();
    $c = idxJob();
    idxSubmission($b, '2026-10-10 05:00:00');
    idxSubmission($c, '2026-11-12 05:00:00');
    idxSubmission($a, '2026-11-01 05:00:00');

    expect(collect(idxProps($this)['applications'])->pluck('job.id')->all())->toBe([$c->id, $a->id, $b->id]);
});

it('lists several submissions to one job as separate numbered rows', function () {
    $job = idxJob();
    $first = idxSubmission($job, '2026-09-01 05:00:00');
    idxEvent($first, SubmissionEventType::Rejected, now()->subDay());
    $second = idxSubmission($job, '2026-11-05 05:00:00');
    $third = idxSubmission($job, '2026-11-09 05:00:00');

    $rows = idxProps($this)['applications'];

    expect(collect($rows)->pluck('id')->all())->toBe([$third->id, $second->id, $first->id])
        ->and(collect($rows)->pluck('attempt')->all())->toBe([3, 2, 1])
        ->and(collect($rows)->pluck('attempts')->unique()->all())->toBe([3])
        ->and(collect($rows)->pluck('phase')->all())->toBe(['submitted', 'submitted', 'rejected'])
        ->and(collect($rows)->pluck('active')->all())->toBe([true, true, false])
        ->and(idxProps($this)['counts'])->toBe(['recorded' => 3, 'in_progress' => 2]);
});

it('keeps an application whose job is no longer pursued, and points it at the job page', function () {
    $job = idxJob();
    idxSubmission($job, '2026-11-01 05:00:00');
    $job->forceFill(['disposition' => JobDisposition::Shortlisted, 'pursued_at' => null])->save();
    $dismissed = idxJob(['disposition' => JobDisposition::Dismissed, 'pursued_at' => null]);
    idxSubmission($dismissed, '2026-11-02 05:00:00');
    $stillPursued = idxJob();
    idxSubmission($stillPursued, '2026-11-03 05:00:00');

    $rows = collect(idxProps($this)['applications'])->keyBy(fn ($r) => $r['job']['id']);

    expect($rows)->toHaveCount(3)
        ->and($rows[$job->id]['job'])->toMatchArray(['pursued' => false, 'url' => route('jobs.show', $job)])
        ->and($rows[$dismissed->id]['job']['url'])->toBe(route('jobs.show', $dismissed))
        ->and($rows[$stillPursued->id]['job'])->toMatchArray(['pursued' => true, 'url' => route('opportunities.show', $stillPursued)]);
});

it('derives every status from the events, in the same words as the workspace', function (SubmissionEventType $type, string $phase, string $label, bool $active) {
    $submission = idxSubmission(idxJob(), '2026-11-01 05:00:00');
    idxEvent($submission, $type, now()->subHour());

    expect(idxProps($this)['applications'][0])->toMatchArray(['phase' => $phase, 'phase_label' => $label, 'active' => $active]);
})->with([
    [SubmissionEventType::ConfirmationReceived, 'confirmed', 'Confirmed', true],
    [SubmissionEventType::Interview, 'interviewing', 'Interviewing', true],
    [SubmissionEventType::OfferReceived, 'offer_received', 'Offer received', true],
    [SubmissionEventType::Rejected, 'rejected', 'Rejected', false],
    [SubmissionEventType::Withdrawn, 'withdrawn', 'Withdrawn', false],
    [SubmissionEventType::OfferAccepted, 'offer_accepted', 'Offer accepted', false],
    [SubmissionEventType::OfferDeclined, 'offer_declined', 'Offer declined', false],
    [SubmissionEventType::FollowUpSent, 'submitted', 'Applied', true],
]);

it('keeps records marked in error out of the list, and shows them apart', function () {
    $job = idxJob();
    $good = idxSubmission($job, '2026-11-05 05:00:00');
    $mistake = idxSubmission($job, '2026-11-01 05:00:00');
    idxEvent($mistake, SubmissionEventType::RecordedInError);

    $props = idxProps($this);

    expect(collect($props['applications'])->pluck('id')->all())->toBe([$good->id])
        ->and($props['applications'][0]['attempts'])->toBe(1)
        ->and(collect($props['in_error'])->pluck('id')->all())->toBe([$mistake->id])
        ->and($props['in_error'][0])->toMatchArray(['in_error' => true, 'phase' => 'recorded_in_error', 'attempt' => null])
        ->and($props['counts']['recorded'])->toBe(1);
});

it('only shows the current profile\'s applications', function () {
    $mine = idxJob();
    idxSubmission($mine, '2026-11-01 05:00:00');
    idxProfile();
    $stranger = JobPosting::factory()->create(['career_profile_id' => CareerProfile::factory()->create()->id, 'disposition' => JobDisposition::Pursued]);
    idxSubmission($stranger, '2026-11-02 05:00:00');

    $props = idxProps($this);

    expect(collect($props['applications'])->pluck('job.id')->all())->toBe([$mine->id])
        ->and(Submission::count())->toBe(2);
});

it('is read-only: it changes nothing and has no way to write', function () {
    $job = idxJob();
    $submission = idxSubmission($job, '2026-11-01 05:00:00');
    $before = $submission->fresh()->getAttributes();

    $this->get('/applications')->assertOk();

    expect($submission->fresh()->getAttributes())->toBe($before)->and($job->fresh()->disposition)->toBe(JobDisposition::Pursued);
    $this->post('/applications', [])->assertStatus(405);
});

describe('navigation', function () {
    it('shares the profile the header menu is named for', function () {
        $profile = idxProfile();
        $profile->forceFill(['name' => 'Daniel Sias'])->save();

        $this->get('/applications')->assertInertia(fn (Assert $page) => $page->where('profile.name', 'Daniel Sias'));
    });

    it('points the header at routes that exist, and the profile menu at existing pages', function () {
        $paths = ['/' => 'home', '/jobs' => 'jobs.index', '/opportunities' => 'opportunities.index', '/applications' => 'applications.index', '/career-data' => 'career-data.index', '/resume-profiles' => 'resume-profiles.index'];

        foreach ($paths as $path => $name) {
            expect(route($name, absolute: false))->toBe($path);
        }
    });

    it('links an application back through the shared job-home rule', function () {
        $job = idxJob();
        idxSubmission($job, '2026-11-01 05:00:00');

        expect(idxProps($this)['applications'][0]['job']['url'])->toBe(JobHome::url($job));
    });
});
