<?php

use App\Enums\DecisionSource;
use App\Enums\DismissalReason;
use App\Enums\JobDisposition;
use App\Models\Application;
use App\Models\CareerProfile;
use App\Models\JobDecision;
use App\Models\JobPosting;
use App\Support\JobDecisions\JobDecisionException;
use App\Support\JobDecisions\RecordJobDecision;
use App\Support\JobDecisions\RecordJobDecisionBatch;

function decisionJob(array $attributes = []): JobPosting
{
    return JobPosting::factory()->create($attributes);
}

function decide(JobPosting $job, JobDisposition $to, DecisionSource $source = DecisionSource::User, ?DismissalReason $reason = null, ?string $note = null, ?string $rule = null): ?JobDecision
{
    return app(RecordJobDecision::class)->record($job, $to, $source, $reason, $note, $rule);
}

it('defaults every new job to the inbox, with no decision recorded', function () {
    $job = decisionJob()->fresh();

    expect($job->disposition)->toBe(JobDisposition::New)
        ->and($job->disposition_source)->toBeNull()
        ->and($job->dispositioned_at)->toBeNull()
        ->and($job->pursued_at)->toBeNull()
        ->and($job->decisions)->toHaveCount(0);
});

it('records a decision on the job and appends a history row with who, why and what it replaced', function () {
    $job = decisionJob();

    $decision = decide($job, JobDisposition::Dismissed, reason: DismissalReason::Seniority, note: '  Too junior  ');

    $job->refresh();
    expect($job->disposition)->toBe(JobDisposition::Dismissed)
        ->and($job->disposition_source)->toBe(DecisionSource::User)
        ->and($job->disposition_reason)->toBe('seniority')
        ->and($job->dispositioned_at)->not->toBeNull()
        ->and($decision->from_disposition)->toBe(JobDisposition::New)
        ->and($decision->to_disposition)->toBe(JobDisposition::Dismissed)
        ->and($decision->reason)->toBe('seniority')
        ->and($decision->note)->toBe('Too junior')
        ->and($decision->source)->toBe(DecisionSource::User);
});

it('does nothing for a decision that would change nothing', function () {
    $job = decisionJob();
    decide($job, JobDisposition::Shortlisted);
    $stamp = $job->fresh()->dispositioned_at;

    $again = decide($job->fresh(), JobDisposition::Shortlisted);

    expect($again)->toBeNull()->and(JobDecision::count())->toBe(1)->and($job->fresh()->dispositioned_at->equalTo($stamp))->toBeTrue();
});

it('allows the planned moves and refuses the rest', function (JobDisposition $from, JobDisposition $to, bool $allowed) {
    $job = decisionJob();
    // Put the job in `from` through the service itself, so the test follows real history.
    $path = [JobDisposition::New->value => [], JobDisposition::Shortlisted->value => [JobDisposition::Shortlisted], JobDisposition::Pursued->value => [JobDisposition::Pursued],
        JobDisposition::Dismissed->value => [JobDisposition::Dismissed]][$from->value];
    foreach ($path as $step) {
        decide($job->fresh(), $step, reason: $step === JobDisposition::Dismissed ? DismissalReason::Other : null);
    }

    $attempt = fn () => decide($job->fresh(), $to, reason: $to === JobDisposition::Dismissed ? DismissalReason::Other : null);

    $allowed ? expect($attempt())->not->toBeNull() : expect($attempt)->toThrow(JobDecisionException::class);
})->with([
    'inbox to shortlist' => [JobDisposition::New, JobDisposition::Shortlisted, true],
    'inbox to pursue' => [JobDisposition::New, JobDisposition::Pursued, true],
    'shortlist to pursue' => [JobDisposition::Shortlisted, JobDisposition::Pursued, true],
    'shortlist to inbox' => [JobDisposition::Shortlisted, JobDisposition::New, true],
    'dismissed to inbox' => [JobDisposition::Dismissed, JobDisposition::New, true],
    'dismissed to shortlist' => [JobDisposition::Dismissed, JobDisposition::Shortlisted, true],
    'dismissed straight to pursue' => [JobDisposition::Dismissed, JobDisposition::Pursued, false],
    'pursued back to shortlist' => [JobDisposition::Pursued, JobDisposition::Shortlisted, true],
]);

it('requires a reason to dismiss, and stores one only for a dismissal', function () {
    $job = decisionJob();

    expect(fn () => decide($job, JobDisposition::Dismissed))->toThrow(JobDecisionException::class, 'needs a reason')
        ->and($job->fresh()->disposition)->toBe(JobDisposition::New)->and(JobDecision::count())->toBe(0);

    decide($job, JobDisposition::Dismissed, reason: DismissalReason::Location);
    decide($job->fresh(), JobDisposition::New, reason: DismissalReason::Location);

    expect($job->fresh()->disposition_reason)->toBeNull();
});

it('stamps pursued_at when pursued and clears it when the job leaves pursued', function () {
    $job = decisionJob();
    decide($job, JobDisposition::Pursued);

    expect($job->fresh()->pursued_at)->not->toBeNull();

    decide($job->fresh(), JobDisposition::Shortlisted);

    expect($job->fresh()->pursued_at)->toBeNull();
});

it('lets a rule dismiss or shortlist an untouched inbox job and records the rule, but never pursue', function () {
    $job = decisionJob();

    expect(fn () => decide($job, JobDisposition::Pursued, DecisionSource::Rule, rule: 'r'))->toThrow(JobDecisionException::class, 'Only a person')
        ->and(fn () => decide($job, JobDisposition::Shortlisted, DecisionSource::Rule))->toThrow(JobDecisionException::class, 'name the rule');

    $decision = decide($job, JobDisposition::Shortlisted, DecisionSource::Rule, rule: 'strong-match');

    expect($decision->source)->toBe(DecisionSource::Rule)->and($decision->rule)->toBe('strong-match')->and($job->fresh()->disposition)->toBe(JobDisposition::Shortlisted);
});

it('never lets a rule overwrite a decision a person made, even after the person restored the job', function () {
    $dismissed = decisionJob();
    decide($dismissed, JobDisposition::Dismissed, reason: DismissalReason::Company);

    expect(fn () => decide($dismissed->fresh(), JobDisposition::Shortlisted, DecisionSource::Rule, rule: 'r'))->toThrow(JobDecisionException::class, 'already decided');

    // Restored to the inbox: the person still decided on it once, so the rule still keeps its hands off.
    decide($dismissed->fresh(), JobDisposition::New);

    expect(fn () => decide($dismissed->fresh(), JobDisposition::Dismissed, DecisionSource::Rule, DismissalReason::Other, rule: 'r'))->toThrow(JobDecisionException::class)
        ->and($dismissed->fresh()->disposition)->toBe(JobDisposition::New);

    $shortlisted = decisionJob();
    decide($shortlisted, JobDisposition::Shortlisted);

    expect(fn () => decide($shortlisted->fresh(), JobDisposition::Dismissed, DecisionSource::Rule, DismissalReason::Other, rule: 'r'))->toThrow(JobDecisionException::class);
});

it('undoes the latest decision by restoring what it replaced, and records the undo', function () {
    $job = decisionJob();
    $dismissal = decide($job, JobDisposition::Dismissed, reason: DismissalReason::Compensation);

    $undo = app(RecordJobDecision::class)->undo($job->fresh(), $dismissal->id);

    expect($job->fresh()->disposition)->toBe(JobDisposition::New)
        ->and($job->fresh()->disposition_reason)->toBeNull()
        ->and($undo->to_disposition)->toBe(JobDisposition::New)->and($undo->details)->toBe(['undo_of' => $dismissal->id])
        ->and($job->decisions)->toHaveCount(2)
        // The original row is untouched.
        ->and(JobDecision::find($dismissal->id)->to_disposition)->toBe(JobDisposition::Dismissed);
});

it('restores a dismissal with its original reason when an undo reverses a restore', function () {
    $job = decisionJob();
    decide($job, JobDisposition::Dismissed, reason: DismissalReason::Duplicate);
    $restore = decide($job->fresh(), JobDisposition::New);

    app(RecordJobDecision::class)->undo($job->fresh(), $restore->id);

    expect($job->fresh()->disposition)->toBe(JobDisposition::Dismissed)->and($job->fresh()->disposition_reason)->toBe('duplicate');
});

it('refuses to undo a decision that is no longer the latest, or one that was already undone', function () {
    $job = decisionJob();
    $first = decide($job, JobDisposition::Shortlisted);
    decide($job->fresh(), JobDisposition::Pursued);

    expect(fn () => app(RecordJobDecision::class)->undo($job->fresh(), $first->id))->toThrow(JobDecisionException::class, 'can no longer be undone');

    $other = decisionJob();
    $decision = decide($other, JobDisposition::Shortlisted);
    app(RecordJobDecision::class)->undo($other->fresh(), $decision->id);

    expect(fn () => app(RecordJobDecision::class)->undo($other->fresh(), $decision->id))->toThrow(JobDecisionException::class)
        ->and($other->fresh()->disposition)->toBe(JobDisposition::New);
});

it('keeps history append-only', function () {
    $job = decisionJob();
    $decision = decide($job, JobDisposition::Shortlisted);

    expect(fn () => $decision->update(['note' => 'x']))->toThrow(LogicException::class)
        ->and(fn () => $decision->delete())->toThrow(LogicException::class);
});

it('judges a decision against the job as it is now, not the stale copy the caller holds', function () {
    $job = decisionJob();
    $stale = JobPosting::find($job->id);
    decide($job, JobDisposition::Shortlisted);

    // The stale copy still says "new"; the service must see "shortlisted" and treat a repeat as a no-op.
    expect(decide($stale, JobDisposition::Shortlisted))->toBeNull()->and(JobDecision::count())->toBe(1);
});

it('applies a bulk decision per job and reports exactly what happened to each', function () {
    $profile = CareerProfile::factory()->create();
    [$a, $b, $c] = [decisionJob(['career_profile_id' => $profile->id]), decisionJob(['career_profile_id' => $profile->id]), decisionJob(['career_profile_id' => $profile->id])];
    $foreign = decisionJob();
    decide($b, JobDisposition::Shortlisted);
    decide($c, JobDisposition::Dismissed, reason: DismissalReason::Other);

    $result = app(RecordJobDecisionBatch::class)->record([$a->id, $b->id, $c->id, $foreign->id, 999999], JobDisposition::Shortlisted);

    expect($result['changed'])->toBe([$a->id, $c->id])
        ->and($result['unchanged'])->toBe([$b->id])
        ->and($result['not_found'])->toBe([$foreign->id, 999999])
        ->and($result['failed'])->toBe([])
        ->and($a->fresh()->disposition)->toBe(JobDisposition::Shortlisted)
        ->and($foreign->fresh()->disposition)->toBe(JobDisposition::New);

    // Repeating it is harmless.
    $again = app(RecordJobDecisionBatch::class)->record([$a->id, $b->id, $c->id], JobDisposition::Shortlisted);

    expect($again['changed'])->toBe([])->and($again['unchanged'])->toBe([$a->id, $b->id, $c->id])->and(JobDecision::count())->toBe(4);
});

it('reports a job a bulk decision refused without stopping the others', function () {
    $profile = CareerProfile::factory()->create();
    $dismissed = decisionJob(['career_profile_id' => $profile->id]);
    $fresh = decisionJob(['career_profile_id' => $profile->id]);
    decide($dismissed, JobDisposition::Dismissed, reason: DismissalReason::Other);

    $result = app(RecordJobDecisionBatch::class)->record([$dismissed->id, $fresh->id], JobDisposition::Pursued);

    expect($result['failed'])->toHaveCount(1)->and($result['failed'][0]['id'])->toBe($dismissed->id)
        ->and($result['changed'])->toBe([$fresh->id])->and($fresh->fresh()->disposition)->toBe(JobDisposition::Pursued);
});

it('undoes a bulk decision job by job, only where each is still the latest', function () {
    $profile = CareerProfile::factory()->create();
    [$a, $b] = [decisionJob(['career_profile_id' => $profile->id]), decisionJob(['career_profile_id' => $profile->id])];
    $done = app(RecordJobDecisionBatch::class)->record([$a->id, $b->id], JobDisposition::Shortlisted);
    decide($b->fresh(), JobDisposition::Pursued);

    $undone = app(RecordJobDecisionBatch::class)->undo($done['decision_ids']);

    expect($undone['changed'])->toBe([$a->id])->and($undone['failed'])->toHaveCount(1)->and($undone['failed'][0]['id'])->toBe($b->id)
        ->and($a->fresh()->disposition)->toBe(JobDisposition::New)->and($b->fresh()->disposition)->toBe(JobDisposition::Pursued);
});

it('leaves resume and application records untouched when a decision is made', function () {
    $job = decisionJob();
    $application = Application::factory()->create(['job_posting_id' => $job->id]);
    $before = $application->fresh()->toArray();

    decide($job, JobDisposition::Dismissed, reason: DismissalReason::Other);

    expect($application->fresh()->toArray())->toBe($before);
});
