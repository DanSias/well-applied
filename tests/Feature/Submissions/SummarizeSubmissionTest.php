<?php

use App\Enums\SubmissionEventType as T;
use App\Enums\SubmissionPhase as P;
use App\Models\Submission;
use App\Models\SubmissionEvent;
use App\Support\Submissions\SummarizeSubmission;

/**
 * @param  list<array{0: T, 1: int}>  $events  type and days after the submission it happened, in the order recorded
 */
function summaryFor(array $events)
{
    $submission = Submission::factory()->create(['submitted_at' => now()->subDays(60)]);

    foreach ($events as [$type, $day]) {
        SubmissionEvent::factory()->create(['submission_id' => $submission->id, 'type' => $type, 'occurred_at' => $submission->submitted_at->copy()->addDays($day)]);
    }

    return app(SummarizeSubmission::class)->forSubmission($submission);
}

it('is Submitted and active with no events, inferring nothing', function () {
    $s = summaryFor([]);

    expect($s->phase)->toBe(P::Submitted)->and($s->active)->toBeTrue()->and($s->confirmed)->toBeFalse()
        ->and($s->recordedInError)->toBeFalse()->and($s->latestEvent)->toBeNull()->and($s->conflictingOutcomes)->toBeFalse();
});

it('derives the phase from the furthest stage reached', function (array $events, P $phase, bool $active) {
    $s = summaryFor($events);

    expect($s->phase)->toBe($phase)->and($s->active)->toBe($active);
})->with([
    'confirmed' => [[[T::ConfirmationReceived, 1]], P::Confirmed, true],
    'interviewing' => [[[T::ConfirmationReceived, 1], [T::Interview, 5]], P::Interviewing, true],
    'interview without a recorded confirmation' => [[[T::Interview, 5]], P::Interviewing, true],
    'offer received' => [[[T::Interview, 5], [T::OfferReceived, 9]], P::OfferReceived, true],
    'follow-up only changes nothing' => [[[T::FollowUpSent, 3]], P::Submitted, true],
    'rejected' => [[[T::Interview, 5], [T::Rejected, 8]], P::Rejected, false],
    'withdrawn' => [[[T::ConfirmationReceived, 1], [T::Withdrawn, 4]], P::Withdrawn, false],
    'offer accepted' => [[[T::OfferReceived, 9], [T::OfferAccepted, 11]], P::OfferAccepted, false],
    'offer declined' => [[[T::OfferReceived, 9], [T::OfferDeclined, 11]], P::OfferDeclined, false],
]);

it('keeps the furthest stage when a confirmation is recorded late', function () {
    // Recorded interview first, then a confirmation that actually arrived on day 1.
    $s = summaryFor([[T::Interview, 6], [T::ConfirmationReceived, 1]]);

    expect($s->phase)->toBe(P::Interviewing)->and($s->confirmed)->toBeTrue();
});

it('orders events by when they happened, not when they were recorded', function () {
    $s = summaryFor([[T::Rejected, 9], [T::Interview, 4], [T::ConfirmationReceived, 1]]);

    expect($s->latestEvent->type)->toBe(T::Rejected)->and($s->phase)->toBe(P::Rejected);
});

it('breaks a tie in time by the order recorded', function () {
    $s = summaryFor([[T::Interview, 5], [T::ConfirmationReceived, 5]]);

    expect($s->latestEvent->type)->toBe(T::ConfirmationReceived)->and($s->phase)->toBe(P::Interviewing);
});

it('counts repeated interviews as one phase', function () {
    $s = summaryFor([[T::Interview, 3], [T::Interview, 7], [T::Interview, 10]]);

    expect($s->phase)->toBe(P::Interviewing)->and($s->interviews)->toBe(3);
});

it('counts follow-ups without letting them move the phase', function () {
    $s = summaryFor([[T::ConfirmationReceived, 1], [T::FollowUpSent, 7], [T::FollowUpSent, 14]]);

    expect($s->phase)->toBe(P::Confirmed)->and($s->followUps)->toBe(2)->and($s->latestEvent->type)->toBe(T::FollowUpSent);
});

it('lets an ending stand over later and later-recorded non-endings', function () {
    $s = summaryFor([[T::Rejected, 5], [T::Interview, 9], [T::OfferReceived, 10]]);

    expect($s->phase)->toBe(P::Rejected)->and($s->active)->toBeFalse();
});

it('lets the latest ending win when endings conflict, and says so', function () {
    $s = summaryFor([[T::Rejected, 5], [T::Withdrawn, 8]]);
    $reverse = summaryFor([[T::Withdrawn, 8], [T::Rejected, 5]]);

    expect($s->phase)->toBe(P::Withdrawn)->and($s->conflictingOutcomes)->toBeTrue()
        ->and($reverse->phase)->toBe(P::Withdrawn)->and($reverse->conflictingOutcomes)->toBeTrue();
});

it('does not call the same ending twice a conflict', function () {
    expect(summaryFor([[T::Rejected, 5], [T::Rejected, 6]])->conflictingOutcomes)->toBeFalse();
});

it('makes a record marked in error final, whatever else was recorded', function () {
    $s = summaryFor([[T::ConfirmationReceived, 1], [T::Interview, 4], [T::RecordedInError, 6], [T::OfferReceived, 2]]);

    expect($s->phase)->toBe(P::RecordedInError)->and($s->recordedInError)->toBeTrue()->and($s->active)->toBeFalse()
        ->and($s->confirmed)->toBeFalse()->and($s->latestEvent->type)->toBe(T::RecordedInError);
});

it('is the same regardless of the order events were recorded in', function () {
    $a = summaryFor([[T::ConfirmationReceived, 1], [T::Interview, 4], [T::OfferReceived, 7]]);
    $b = summaryFor([[T::OfferReceived, 7], [T::ConfirmationReceived, 1], [T::Interview, 4]]);

    expect([$a->phase, $a->confirmed, $a->interviews])->toBe([$b->phase, $b->confirmed, $b->interviews])
        ->and($a->latestEvent->type)->toBe($b->latestEvent->type);
});

it('stores no status anywhere', function () {
    expect(Schema::hasColumn('submissions', 'status'))->toBeFalse()->and(Schema::hasColumn('submissions', 'phase'))->toBeFalse();
});
