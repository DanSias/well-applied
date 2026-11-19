<?php

use App\Enums\ApplicationStatus;
use App\Enums\SubmissionEventSource;
use App\Enums\SubmissionEventType;
use App\Enums\SubmissionMethod;
use App\Models\Application;
use App\Models\JobPosting;
use App\Models\ResumeVariant;
use App\Models\Submission;
use App\Models\SubmissionEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

it('records a submission against a job without an inspection record or a resume', function () {
    $job = JobPosting::factory()->create();

    $submission = Submission::factory()->create(['job_posting_id' => $job->id]);

    expect($submission->fresh())
        ->application_id->toBeNull()
        ->resume_variant_id->toBeNull()
        ->application_url->toBeNull()
        ->workflow_run_id->toBeNull()
        ->submitted_pdf_path->toBeNull()
        ->method->toBe(SubmissionMethod::Manual)
        ->and($submission->jobPosting->is($job))->toBeTrue()
        ->and(Application::count())->toBe(0);
});

it('lets a job have several submissions over time, oldest first', function () {
    $job = JobPosting::factory()->create();
    $later = Submission::factory()->create(['job_posting_id' => $job->id, 'submitted_at' => now()->subDay()]);
    $earlier = Submission::factory()->create(['job_posting_id' => $job->id, 'submitted_at' => now()->subWeek()]);

    expect($job->submissions->pluck('id')->all())->toBe([$earlier->id, $later->id]);
});

it('keeps the inspection record as provenance only, and leaves it untouched', function () {
    $job = JobPosting::factory()->create();
    $application = Application::factory()->for($job, 'jobPosting')->create();
    $before = $application->fresh()->getAttributes();

    $submission = Submission::factory()->create(['job_posting_id' => $job->id, 'application_id' => $application->id]);

    expect($submission->application->is($application))->toBeTrue()
        ->and($application->fresh()->getAttributes())->toBe($before)
        ->and($application->status)->toBe(ApplicationStatus::Draft);
});

it('does not change the shape of the applications table', function () {
    // Recording a submission adds nothing here. The only columns beyond the original are the application URL (and its
    // origin and timestamp): inspection's own operational data, not anything about having applied.
    expect(Schema::getColumnListing('applications'))->toEqualCanonicalizing(['id', 'job_posting_id', 'status', 'application_url', 'application_url_origin', 'application_url_set_at', 'created_at', 'updated_at'])
        ->and(array_map(fn ($case) => $case->value, ApplicationStatus::cases()))->toBe(['draft']);
});

it('refuses to delete a job or an inspection record that a submission points at', function () {
    $job = JobPosting::factory()->create();
    $application = Application::factory()->for($job, 'jobPosting')->create();
    Submission::factory()->create(['job_posting_id' => $job->id, 'application_id' => $application->id]);

    expect(fn () => $application->delete())->toThrow(QueryException::class)
        ->and(fn () => $job->delete())->toThrow(QueryException::class)
        ->and(Submission::count())->toBe(1);
});

it('keeps a submission when the resume variant it names is removed', function () {
    $variant = ResumeVariant::factory()->create();
    $submission = Submission::factory()->create(['resume_variant_id' => $variant->id, 'submitted_pdf_sha256' => str_repeat('a', 64)]);

    $variant->delete();

    expect($submission->fresh()->resume_variant_id)->toBeNull()
        ->and($submission->fresh()->submitted_pdf_sha256)->toBe(str_repeat('a', 64));
});

it('never deletes a submission', function () {
    $submission = Submission::factory()->create();

    expect(fn () => $submission->delete())->toThrow(LogicException::class)
        ->and(Submission::count())->toBe(1);
});

it('keeps the time of the submission apart from the time it was recorded', function () {
    $submission = Submission::factory()->create(['submitted_at' => now()->subDays(3)]);

    expect($submission->submitted_at->isBefore($submission->created_at))->toBeTrue();
});

it('refuses a repeated idempotency key at the database', function () {
    Submission::factory()->create(['idempotency_key' => 'same']);

    expect(fn () => Submission::factory()->create(['idempotency_key' => 'same']))->toThrow(QueryException::class);
});

describe('events', function () {
    it('belong to a submission, in the order they occurred', function () {
        $submission = Submission::factory()->create();
        $late = SubmissionEvent::factory()->create(['submission_id' => $submission->id, 'type' => SubmissionEventType::Interview, 'occurred_at' => now()->addDays(2)]);
        $early = SubmissionEvent::factory()->create(['submission_id' => $submission->id, 'occurred_at' => now()->addDay()]);

        expect($submission->events->pluck('id')->all())->toBe([$early->id, $late->id])
            ->and($early->submission->is($submission))->toBeTrue()
            ->and($early->source)->toBe(SubmissionEventSource::User);
    });

    it('are append-only', function () {
        $event = SubmissionEvent::factory()->create();

        expect(fn () => $event->update(['note' => 'changed']))->toThrow(LogicException::class)
            ->and(fn () => $event->delete())->toThrow(LogicException::class)
            ->and(SubmissionEvent::count())->toBe(1);
    });

    it('block removing the submission they belong to', function () {
        $event = SubmissionEvent::factory()->create();

        expect(fn () => DB::table('submissions')->where('id', $event->submission_id)->delete())->toThrow(QueryException::class);
    });

    it('keep when it happened apart from when it was recorded, and round-trip evidence', function () {
        $event = SubmissionEvent::factory()->create(['occurred_at' => now()->subDays(5), 'evidence' => ['kind' => 'url', 'reference' => 'https://example.com/c']]);

        expect($event->fresh()->occurred_at->isBefore($event->fresh()->created_at))->toBeTrue()
            ->and($event->fresh()->evidence)->toBe(['kind' => 'url', 'reference' => 'https://example.com/c']);
    });

    it('refuse a repeated idempotency key within a submission, but not across submissions', function () {
        $a = Submission::factory()->create();
        $b = Submission::factory()->create();
        SubmissionEvent::factory()->create(['submission_id' => $a->id, 'idempotency_key' => 'k']);

        expect(fn () => SubmissionEvent::factory()->create(['submission_id' => $a->id, 'idempotency_key' => 'k']))->toThrow(QueryException::class);
        SubmissionEvent::factory()->create(['submission_id' => $b->id, 'idempotency_key' => 'k']);
        expect(SubmissionEvent::count())->toBe(2);
    });
});
