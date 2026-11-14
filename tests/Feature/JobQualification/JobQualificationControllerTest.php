<?php

use App\Enums\GenerationStatus;
use App\Enums\GenerationType;
use App\Enums\JobDisposition;
use App\Enums\JobRemoteStatus;
use App\Jobs\GenerateJobAnalysisJob;
use App\Jobs\GenerateJobMatchJob;
use App\Jobs\GenerateJobQualificationJob;
use App\Models\GenerationAttempt;
use App\Models\JobPosting;
use App\Models\JobQualification;
use App\Support\JobQualification\GenerateJobQualification;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\QualificationFixtures as Q;

/**
 * The manual "Qualify" action and the drawer's qualification block. It queues one attempt at most, reuses a valid
 * assessment, never queues analysis or matching, and never moves a job between Inbox, Shortlist, Dismissed and Pursued.
 */
it('queues one qualification attempt for a job', function () {
    Queue::fake();
    $job = Q::job(Q::candidate(), Q::laravel());

    $this->post(route('jobs.qualification.store', $job))->assertRedirect();

    $attempt = GenerationAttempt::query()->sole();
    expect($attempt->generation_type)->toBe(GenerationType::JobQualification)->and($attempt->status)->toBe(GenerationStatus::Queued)
        ->and($attempt->regenerate)->toBeFalse()->and($attempt->subject_id)->toBe($job->id);
    Queue::assertPushed(GenerateJobQualificationJob::class, fn ($queued) => $queued->attempt->is($attempt));
});

it('does not queue a second attempt while one is in flight', function () {
    Queue::fake();
    $job = Q::job(Q::candidate(), Q::laravel());

    $this->post(route('jobs.qualification.store', $job));
    $this->post(route('jobs.qualification.store', $job));

    expect(GenerationAttempt::query()->count())->toBe(1);
    Queue::assertPushed(GenerateJobQualificationJob::class, 1);
});

it('does not queue anything when the stored assessment is still valid, unless a refresh is asked for', function () {
    Queue::fake();
    $job = Q::job(Q::candidate(), Q::laravel());
    Q::fake()->willReturn(Q::answer());
    app(GenerateJobQualification::class)->generate($job);

    $this->post(route('jobs.qualification.store', $job))->assertRedirect();
    expect(GenerationAttempt::query()->count())->toBe(0);

    $this->post(route('jobs.qualification.store', $job), ['refresh' => true])->assertRedirect();
    $attempt = GenerationAttempt::query()->sole();
    expect($attempt->regenerate)->toBeTrue();
});

it('queues again when the posting has changed since the last assessment', function () {
    Queue::fake();
    $job = Q::job(Q::candidate(), Q::laravel());
    Q::fake()->willReturn(Q::answer());
    app(GenerateJobQualification::class)->generate($job);
    $job->update(['title' => 'Principal Software Engineer']);

    $this->post(route('jobs.qualification.store', $job));

    expect(GenerationAttempt::query()->count())->toBe(1);
});

it('only qualifies, never analyzes, matches, or changes the job\'s disposition', function (JobDisposition $disposition) {
    Queue::fake();
    $job = Q::job(Q::candidate(), Q::laravel());
    $job->forceFill(['disposition' => $disposition])->save();

    $this->post(route('jobs.qualification.store', $job));

    Queue::assertNotPushed(GenerateJobAnalysisJob::class);
    Queue::assertNotPushed(GenerateJobMatchJob::class);
    expect($job->fresh()->disposition)->toBe($disposition)->and($job->decisions()->count())->toBe(0);
})->with([JobDisposition::New, JobDisposition::Shortlisted, JobDisposition::Dismissed]);

it('treats another profile\'s job as not found', function () {
    Queue::fake();
    Q::candidate();
    $theirs = Q::job(Q::candidate(), Q::laravel());

    $this->post(route('jobs.qualification.store', $theirs))->assertNotFound();

    expect(GenerationAttempt::query()->count())->toBe(0);
});

it('rejects a non-numeric job id', function () {
    $this->post('/jobs/abc/qualification')->assertNotFound();
});

it('gives the drawer the qualification: none, then queued, then ready with evidence, then stale after a change', function () {
    $job = Q::job(Q::candidate(), Q::laravel());
    $drawer = fn () => $this->get("/jobs?disposition=all&job={$job->id}");

    $drawer()->assertInertia(fn (Assert $page) => $page->where('selected_job.qualification.state', 'none')->where('selected_job.qualification.current', null)->where('selected_job.qualification.stale', false));

    Queue::fake();
    $this->post(route('jobs.qualification.store', $job));
    $drawer()->assertInertia(fn (Assert $page) => $page->where('selected_job.qualification.state', 'queued')->where('selected_job.qualification.attempt.status', 'queued'));

    Q::fake()->willReturn(Q::answer([
        'primary_role_focus' => 'Build the customer platform in Laravel.', 'biggest_concern' => 'Distributed team hours.', 'biggest_concern_evidence_refs' => ['S003'],
        'technologies' => [Q::tech('Laravel', 'mandatory', 'familiar'), Q::tech('Go', 'mandatory', 'unfamiliar', 'working', transferable: true)], 'verdict' => 'good',
    ]));
    $attempt = GenerationAttempt::query()->sole();
    (new GenerateJobQualificationJob($attempt))->handle(app(GenerateJobQualification::class));

    $drawer()->assertInertia(fn (Assert $page) => $page
        ->where('selected_job.qualification.state', 'ready')->where('selected_job.qualification.stale', false)
        ->where('selected_job.qualification.current.verdict', 'good')->where('selected_job.qualification.current.verdict_label', 'Good match')
        ->where('selected_job.qualification.current.eligibility', 'eligible')
        ->where('selected_job.qualification.current.assessment.primary_role_focus', 'Build the customer platform in Laravel.')
        ->where('selected_job.qualification.current.assessment.biggest_concern.text', 'Distributed team hours.')
        ->where('selected_job.qualification.current.assessment.mandatory_stack_concerns.0.name', 'Go')
        ->where('selected_job.qualification.current.assessment.why_it_fits.0.evidence.0.ref', 'S001')
        ->missing('selected_job.qualification.current.assessment.technologies'));

    $job->update(['description' => Q::laravel().' You will also lead a small team of four engineers.']);
    $drawer()->assertInertia(fn (Assert $page) => $page->where('selected_job.qualification.state', 'ready')->where('selected_job.qualification.stale', true)->where('selected_job.qualification.current.verdict', 'good'));
});

it('shows a failed attempt, and keeps showing the earlier assessment alongside it', function () {
    $job = Q::job(Q::candidate(), Q::laravel());
    Q::fake()->willReturn(Q::answer());
    app(GenerateJobQualification::class)->generate($job);
    $job->update(['title' => 'Staff Engineer']);

    $attempt = GenerationAttempt::factory()->create([
        'subject_type' => (new JobPosting)->getMorphClass(), 'subject_id' => $job->id, 'generation_type' => GenerationType::JobQualification,
        'status' => GenerationStatus::Failed, 'finished_at' => now()->addMinute(), 'failure_category' => 'provider_error', 'failure_message' => 'Ollama request timed out.',
    ]);

    $this->get("/jobs?disposition=all&job={$job->id}")->assertInertia(fn (Assert $page) => $page
        ->where('selected_job.qualification.state', 'failed')->where('selected_job.qualification.attempt.failure_message', 'Ollama request timed out.')
        ->where('selected_job.qualification.stale', true)->where('selected_job.qualification.current.verdict', 'strong'));
});

it('presents an ineligible or thin job with its reasons and no recommendation', function () {
    $job = Q::job(Q::candidate(), Q::laravel(), ['remote_status' => JobRemoteStatus::Onsite, 'location' => 'Austin, Texas']);
    app(GenerateJobQualification::class)->generate($job);

    $this->get("/jobs?disposition=all&job={$job->id}")->assertInertia(fn (Assert $page) => $page
        ->where('selected_job.qualification.current.verdict', null)->where('selected_job.qualification.current.assessment', null)
        ->where('selected_job.qualification.current.eligibility', 'ineligible')
        ->where('selected_job.qualification.current.eligibility_reasons.0.code', 'workplace_outside_home_state'));
});

it('does not put a qualification on the list rows', function () {
    $job = Q::job(Q::candidate(), Q::laravel());
    Q::fake()->willReturn(Q::answer());
    app(GenerateJobQualification::class)->generate($job);

    $this->get('/jobs?disposition=all')->assertInertia(fn (Assert $page) => $page->missing('jobs.0.qualification'));
    expect(JobQualification::query()->count())->toBe(1);
});
