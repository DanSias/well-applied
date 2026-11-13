<?php

use App\Enums\JobDisposition;
use App\Enums\SubmissionEventType;
use App\Enums\SubmissionMethod;
use App\Models\Application;
use App\Models\JobAnalysis;
use App\Models\JobMatch;
use App\Models\JobPosting;
use App\Models\ResumeVariant;
use App\Models\Submission;
use App\Models\WorkflowRun;
use App\Support\Submissions\RecordSubmission;
use App\Support\Submissions\RecordSubmissionEvent;
use App\Support\Submissions\SubmissionException;
use Illuminate\Support\Carbon;

function variantFor(JobPosting $job): ResumeVariant
{
    $analysis = JobAnalysis::factory()->for($job, 'jobPosting')->create();
    $match = JobMatch::factory()->create(['job_analysis_id' => $analysis->id]);

    return ResumeVariant::factory()->create(['job_analysis_id' => $analysis->id, 'job_match_id' => $match->id]);
}

function recorder(): RecordSubmission
{
    return app(RecordSubmission::class);
}

function expectRefusal(callable $fn, string $reason): void
{
    try {
        $fn();
    } catch (SubmissionException $e) {
        expect($e->reason)->toBe($reason);

        return;
    }

    throw new RuntimeException("Expected a refusal with reason {$reason}.");
}

it('records a manual submission with nothing but a job and a time', function () {
    $job = JobPosting::factory()->create();
    $when = Carbon::parse('2026-11-10 14:30:00');

    $submission = recorder()->record($job, $when);

    expect($submission->wasRecentlyCreated)->toBeTrue()
        ->and($submission->method)->toBe(SubmissionMethod::Manual)
        ->and($submission->submitted_at->equalTo($when))->toBeTrue()
        ->and($submission->application_id)->toBeNull()
        ->and($submission->resume_variant_id)->toBeNull()
        ->and($submission->application_url)->toBeNull()
        ->and(Application::count())->toBe(0)
        ->and($submission->created_at->isAfter($when))->toBeTrue();
});

it('stores the details it is given, trimmed', function () {
    $job = JobPosting::factory()->create();
    $variant = variantFor($job);
    $inspection = Application::factory()->for($job, 'jobPosting')->create();

    $submission = recorder()->record($job->id, now()->subHour(), resumeVariant: $variant, application: $inspection, applicationUrl: ' https://boards.example.com/apply/1 ', notes: "  Applied via the careers page.\n");

    expect($submission->resume_variant_id)->toBe($variant->id)
        ->and($submission->application_id)->toBe($inspection->id)
        ->and($submission->application_url)->toBe('https://boards.example.com/apply/1')
        ->and($submission->notes)->toBe('Applied via the careers page.');
});

it('does not touch the job, its disposition or any inspection record', function () {
    $job = JobPosting::factory()->create(['disposition' => JobDisposition::Shortlisted]);
    $inspection = Application::factory()->for($job, 'jobPosting')->create();
    $before = [$job->fresh()->getAttributes(), $inspection->fresh()->getAttributes()];

    recorder()->record($job, now());

    expect([$job->fresh()->getAttributes(), $inspection->fresh()->getAttributes()])->toBe($before)
        ->and($job->fresh()->disposition)->toBe(JobDisposition::Shortlisted);
});

it('is never created by an inspection, a resume, a pursued job or a worker run', function () {
    $job = JobPosting::factory()->create();
    variantFor($job);
    $job->forceFill(['disposition' => JobDisposition::Pursued, 'pursued_at' => now()])->save();
    $inspection = Application::factory()->for($job, 'jobPosting')->create();
    WorkflowRun::factory()->for($inspection)->create();

    expect(Submission::count())->toBe(0);
});

it('refuses a job that does not exist', function () {
    expectRefusal(fn () => recorder()->record(999999, now()), 'job_not_found');
    expect(Submission::count())->toBe(0);
});

it('refuses methods that nothing produces yet, and times that cannot be true', function () {
    $job = JobPosting::factory()->create();

    expectRefusal(fn () => recorder()->record($job, now(), method: SubmissionMethod::Automated), 'method_unsupported');
    expectRefusal(fn () => recorder()->record($job, now(), method: SubmissionMethod::Assisted), 'method_unsupported');
    expectRefusal(fn () => recorder()->record($job, now()->addHour()), 'submitted_in_future');
    recorder()->record($job, now()->addMinutes(2));
    expect(Submission::count())->toBe(1);
});

it('refuses a resume or an inspection record that belongs to another job', function () {
    $job = JobPosting::factory()->create();
    $foreignVariant = variantFor(JobPosting::factory()->create());
    $foreignInspection = Application::factory()->create();

    expectRefusal(fn () => recorder()->record($job, now(), resumeVariant: $foreignVariant), 'resume_not_for_job');
    expectRefusal(fn () => recorder()->record($job, now(), application: $foreignInspection), 'inspection_not_for_job');
    expect(Submission::count())->toBe(0);
});

it('refuses a link that is not a web address', function (string $url) {
    expectRefusal(fn () => recorder()->record(JobPosting::factory()->create(), now(), applicationUrl: $url), 'url_invalid');
})->with(['javascript:alert(1)', 'ftp://example.com/x', 'example.com/apply', 'file:///etc/passwd']);

describe('retries', function () {
    it('return the submission already recorded for the same key', function () {
        $job = JobPosting::factory()->create();

        $first = recorder()->record($job, now()->subHour(), 'key-1');
        $retry = recorder()->record($job, now()->subHour(), 'key-1', notes: 'retried');

        expect($retry->id)->toBe($first->id)
            ->and($retry->wasRecentlyCreated)->toBeFalse()
            ->and($retry->notes)->toBeNull()
            ->and(Submission::count())->toBe(1);
    });

    it('stay safe even when the second attempt asks to record another', function () {
        $job = JobPosting::factory()->create();

        $first = recorder()->record($job, now(), 'key-2');

        expect(recorder()->record($job, now(), 'key-2', allowAnother: true)->id)->toBe($first->id)
            ->and(Submission::count())->toBe(1);
    });

    it('refuse a key already used for a different job', function () {
        recorder()->record(JobPosting::factory()->create(), now(), 'shared');

        expectRefusal(fn () => recorder()->record(JobPosting::factory()->create(), now(), 'shared'), 'idempotency_key_reused');
        expect(Submission::count())->toBe(1);
    });

    it('treat a blank key as no key', function () {
        $job = JobPosting::factory()->create();

        recorder()->record($job, now(), '  ', allowAnother: true);
        recorder()->record($job, now(), '', allowAnother: true);

        expect(Submission::count())->toBe(2)->and(Submission::whereNotNull('idempotency_key')->count())->toBe(0);
    });
});

describe('more than one submission', function () {
    it('needs explicit intent while one is still active', function () {
        $job = JobPosting::factory()->create();
        recorder()->record($job, now()->subDay());

        expectRefusal(fn () => recorder()->record($job, now()), 'active_submission_exists');
        expect(Submission::count())->toBe(1);

        $second = recorder()->record($job, now(), allowAnother: true);

        expect($job->submissions()->count())->toBe(2)->and($second->wasRecentlyCreated)->toBeTrue();
    });

    it('is allowed without intent once the earlier one has ended', function () {
        $job = JobPosting::factory()->create();
        $first = recorder()->record($job, now()->subWeek());
        app(RecordSubmissionEvent::class)->record($first, SubmissionEventType::Rejected, now()->subDay());

        recorder()->record($job, now());

        expect($job->submissions()->count())->toBe(2);
    });

    it('is allowed without intent when the earlier one was a mistake', function () {
        $job = JobPosting::factory()->create();
        $first = recorder()->record($job, now()->subWeek());
        app(RecordSubmissionEvent::class)->record($first, SubmissionEventType::RecordedInError, now()->subDay());

        recorder()->record($job, now());

        expect($job->submissions()->count())->toBe(2);
    });

    it('still needs intent while an earlier one is only confirmed, interviewing or has an offer', function (SubmissionEventType $type) {
        $job = JobPosting::factory()->create();
        $first = recorder()->record($job, now()->subWeek());
        app(RecordSubmissionEvent::class)->record($first, $type, now()->subDay());

        expectRefusal(fn () => recorder()->record($job, now()), 'active_submission_exists');
    })->with([SubmissionEventType::ConfirmationReceived, SubmissionEventType::Interview, SubmissionEventType::OfferReceived, SubmissionEventType::FollowUpSent]);

    it('judges each job on its own', function () {
        recorder()->record(JobPosting::factory()->create(), now());
        recorder()->record(JobPosting::factory()->create(), now());

        expect(Submission::count())->toBe(2);
    });
});
