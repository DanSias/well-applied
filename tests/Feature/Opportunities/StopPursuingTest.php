<?php

use App\Enums\DecisionSource;
use App\Enums\JobDisposition;
use App\Models\Application;
use App\Models\CareerProfile;
use App\Models\JobAnalysis;
use App\Models\JobDecision;
use App\Models\JobPosting;
use App\Models\Submission;
use App\Support\JobDecisions\RecordJobDecision;
use Inertia\Testing\AssertableInertia as Assert;

function stopProfile(): CareerProfile
{
    return CareerProfile::query()->oldest('id')->first() ?? CareerProfile::factory()->create();
}

function pursuedJob(array $attributes = []): JobPosting
{
    $job = JobPosting::factory()->create(['career_profile_id' => stopProfile()->id] + $attributes);
    app(RecordJobDecision::class)->record($job, JobDisposition::Pursued, DecisionSource::User);

    return $job->fresh();
}

it('stops pursuing with a reason, leaves for the list, and records why in the decision history', function () {
    $job = pursuedJob(['company' => 'Deepgram']);

    $this->post(route('opportunities.stop', $job), ['reason' => 'posting_closed'])->assertRedirect(route('opportunities.index'));

    $fresh = $job->fresh();
    $decision = JobDecision::query()->where('job_posting_id', $job->id)->latest('id')->first();
    expect($fresh->disposition)->toBe(JobDisposition::Dismissed)
        ->and($fresh->disposition_reason)->toBe('posting_closed')
        ->and($fresh->pursued_at)->toBeNull()
        ->and($decision->from_disposition)->toBe(JobDisposition::Pursued)
        ->and($decision->to_disposition)->toBe(JobDisposition::Dismissed)
        ->and($decision->reason)->toBe('posting_closed')
        ->and($decision->source)->toBe(DecisionSource::User)
        ->and(session('decision_result'))->toMatchArray(['to' => 'dismissed', 'changed' => [$job->id], 'stopped_pursuing' => ['company' => 'Deepgram', 'title' => $job->title]])
        ->and(session('decision_result')['decision_ids'])->toBe([$job->id => $decision->id]);
});

it('keeps an optional note for another reason', function () {
    $job = pursuedJob();

    $this->post(route('opportunities.stop', $job), ['reason' => 'other', 'note' => ' Moved cities '])->assertRedirect(route('opportunities.index'));

    expect(JobDecision::latest('id')->first()->note)->toBe('Moved cities')->and($job->fresh()->disposition_reason)->toBe('other');
});

it('stops without a reason, recording none, and Undo still restores pursuit', function () {
    $job = pursuedJob();

    $this->post(route('opportunities.stop', $job), [])->assertRedirect(route('opportunities.index'))->assertSessionHasNoErrors();

    $decision = JobDecision::latest('id')->first();
    expect($job->fresh()->disposition)->toBe(JobDisposition::Dismissed)
        ->and($job->fresh()->disposition_reason)->toBeNull()
        ->and($decision->reason)->toBeNull();

    $this->post(route('jobs.decisions.undo'), ['decisions' => [$job->id => $decision->id]])->assertSessionHasNoErrors();
    expect($job->fresh()->disposition)->toBe(JobDisposition::Pursued);
});

it('still requires a reason to dismiss a job that is not being stopped', function () {
    $job = JobPosting::factory()->create(['career_profile_id' => stopProfile()->id]);

    $this->post(route('jobs.decision.store', $job), ['disposition' => 'dismissed'])->assertSessionHasErrors('reason');
});

it('accepts only the offered reasons when one is given', function () {
    $job = pursuedJob();

    $this->post(route('opportunities.stop', $job), ['reason' => 'seniority'])->assertSessionHasErrors('reason');
    $this->post(route('opportunities.stop', $job), ['reason' => 'nonsense'])->assertSessionHasErrors('reason');

    expect($job->fresh()->disposition)->toBe(JobDisposition::Pursued)->and(JobDecision::where('to_disposition', 'dismissed')->count())->toBe(0);
});

it('drops the opportunity from the active list, keeps it in the existing-work review, and keeps its page a redirect', function () {
    $job = pursuedJob();
    Application::factory()->create(['job_posting_id' => $job->id]);
    $this->post(route('opportunities.stop', $job), ['reason' => 'no_longer_interested']);

    $this->get(route('opportunities.index'))->assertInertia(fn (Assert $page) => $page
        ->where('opportunities', [])
        ->has('review', 1)
        ->where('review.0.job_id', $job->id)
        ->where('review.0.disposition', 'dismissed'));
    $this->get(route('opportunities.show', $job))->assertRedirect(route('jobs.show', $job));
});

it('preserves the job, its analyses, applications and recorded submissions', function () {
    $job = pursuedJob();
    $analysis = JobAnalysis::factory()->for($job, 'jobPosting')->create();
    $application = Application::factory()->create(['job_posting_id' => $job->id]);
    $submission = Submission::factory()->create(['job_posting_id' => $job->id]);
    $before = $submission->fresh()->only(['submitted_at', 'notes']);

    $this->post(route('opportunities.stop', $job), ['reason' => 'posting_closed'])->assertRedirect();

    expect(JobPosting::find($job->id))->not->toBeNull()
        ->and(JobAnalysis::find($analysis->id))->not->toBeNull()
        ->and(Application::find($application->id))->not->toBeNull()
        ->and(Submission::find($submission->id)->only(['submitted_at', 'notes']))->toEqual($before)
        ->and(JobPosting::count())->toBe(1);
});

it('undoes back to pursued, keeping when pursuit began and writing no duplicate records', function () {
    $job = pursuedJob();
    $began = $job->pursued_at;
    $this->travel(2)->days();
    $this->post(route('opportunities.stop', $job), ['reason' => 'not_a_good_fit']);
    $decisionId = session('decision_result')['decision_ids'][$job->id];

    $this->post(route('jobs.decisions.undo'), ['decisions' => [$job->id => $decisionId]])->assertSessionHasNoErrors();

    $fresh = $job->fresh();
    expect($fresh->disposition)->toBe(JobDisposition::Pursued)
        ->and($fresh->pursued_at->equalTo($began))->toBeTrue()
        ->and($fresh->disposition_reason)->toBeNull()
        ->and(JobPosting::count())->toBe(1)
        ->and(JobDecision::where('job_posting_id', $job->id)->count())->toBe(3)
        ->and(session('decision_result')['changed'])->toBe([$job->id]);
    $this->get(route('opportunities.show', $job))->assertOk();
});

it('refuses an undo once the job has moved on, and says so', function () {
    $job = pursuedJob();
    $this->post(route('opportunities.stop', $job), ['reason' => 'posting_closed']);
    $decisionId = session('decision_result')['decision_ids'][$job->id];
    app(RecordJobDecision::class)->record($job->fresh(), JobDisposition::Shortlisted, DecisionSource::User);

    $this->post(route('jobs.decisions.undo'), ['decisions' => [$job->id => $decisionId]]);

    expect(session('decision_result')['failed'])->toHaveCount(1)->and($job->fresh()->disposition)->toBe(JobDisposition::Shortlisted);
});

it('treats a repeated request as already done, without a second history row', function () {
    $job = pursuedJob();
    $this->post(route('opportunities.stop', $job), ['reason' => 'posting_closed']);
    $count = JobDecision::count();

    $this->post(route('opportunities.stop', $job), ['reason' => 'posting_closed'])->assertRedirect(route('opportunities.index'));

    expect(JobDecision::count())->toBe($count)->and(session('decision_result')['unchanged'])->toBe([$job->id]);
});

it('refuses a stale page when the job is no longer pursued, changing nothing', function () {
    $job = pursuedJob();
    app(RecordJobDecision::class)->record($job, JobDisposition::Shortlisted, DecisionSource::User);
    $count = JobDecision::count();

    $this->from(route('opportunities.index'))->post(route('opportunities.stop', $job), ['reason' => 'no_longer_interested'])
        ->assertRedirect(route('opportunities.index'))->assertSessionHasErrors('reason');

    expect($job->fresh()->disposition)->toBe(JobDisposition::Shortlisted)->and(JobDecision::count())->toBe($count);
});

it('does not let the stop-pursuing reasons dismiss a job that was never pursued', function () {
    $job = JobPosting::factory()->create(['career_profile_id' => stopProfile()->id]);

    $this->post(route('jobs.decision.store', $job), ['disposition' => 'dismissed', 'reason' => 'posting_closed']);

    expect(session('decision_result')['failed'])->toHaveCount(1)->and($job->fresh()->disposition)->toBe(JobDisposition::New);
});

it('answers a job from another profile as not found', function () {
    stopProfile();
    $other = JobPosting::factory()->create(['career_profile_id' => CareerProfile::factory()->create()->id]);

    $this->post(route('opportunities.stop', $other), ['reason' => 'posting_closed'])->assertNotFound();
});

it('can be pursued again after restoring, so a stopped opportunity is recoverable', function () {
    $job = pursuedJob();
    $this->post(route('opportunities.stop', $job), ['reason' => 'posting_closed']);

    $this->post(route('jobs.decision.store', $job), ['disposition' => 'new'])->assertSessionHasNoErrors();
    $this->post(route('jobs.decision.store', $job), ['disposition' => 'pursued'])->assertSessionHasNoErrors();

    expect($job->fresh()->disposition)->toBe(JobDisposition::Pursued);
    $this->get(route('opportunities.index'))->assertInertia(fn (Assert $page) => $page->has('opportunities', 1));
});
