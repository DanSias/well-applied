<?php

use App\Enums\SubmissionEventSource;
use App\Enums\SubmissionEventType;
use App\Models\Submission;
use App\Models\SubmissionEvent;
use App\Support\Submissions\RecordSubmissionEvent;
use App\Support\Submissions\SubmissionException;
use Illuminate\Support\Carbon;

function events(): RecordSubmissionEvent
{
    return app(RecordSubmissionEvent::class);
}

function refused(callable $fn, string $reason): void
{
    try {
        $fn();
    } catch (SubmissionException $e) {
        expect($e->reason)->toBe($reason);

        return;
    }

    throw new RuntimeException("Expected a refusal with reason {$reason}.");
}

beforeEach(fn () => $this->submission = Submission::factory()->create(['submitted_at' => now()->subWeek()]));

it('appends an event with its own time apart from when it was recorded', function () {
    $when = now()->subDays(3)->startOfSecond();

    $event = events()->record($this->submission, SubmissionEventType::ConfirmationReceived, $when, note: ' Got the automated email. ');

    expect($event->occurred_at->equalTo($when))->toBeTrue()
        ->and($event->created_at->isAfter($when))->toBeTrue()
        ->and($event->note)->toBe('Got the automated email.')
        ->and($event->source)->toBe(SubmissionEventSource::User)
        ->and($this->submission->events()->count())->toBe(1);
});

it('keeps history append-only', function () {
    $event = events()->record($this->submission, SubmissionEventType::Interview, now()->subDay());

    expect(fn () => $event->update(['type' => SubmissionEventType::Rejected]))->toThrow(LogicException::class)
        ->and(fn () => $event->delete())->toThrow(LogicException::class)
        ->and(fn () => SubmissionEvent::query()->whereKey($event->id)->first()->forceFill(['note' => 'x'])->save())->toThrow(LogicException::class)
        ->and($event->fresh()->type)->toBe(SubmissionEventType::Interview);
});

it('accepts every supported type, each ending on a submission of its own', function () {
    foreach (SubmissionEventType::cases() as $i => $type) {
        if ($type === SubmissionEventType::RecordedInError) {
            continue;
        }

        $submission = $type->isTerminal() ? Submission::factory()->create(['submitted_at' => now()->subWeek()]) : $this->submission;

        events()->record($submission, $type, now()->subDays(6)->addMinutes($i));
    }

    expect(SubmissionEvent::count())->toBe(count(SubmissionEventType::cases()) - 1);
});

describe('after the application has ended', function () {
    it('refuses a second ending and anything dated after the first', function (SubmissionEventType $ending) {
        events()->record($this->submission, $ending, now()->subDays(3));

        refused(fn () => events()->record($this->submission, SubmissionEventType::Rejected, now()->subDays(2)), 'already_ended');
        refused(fn () => events()->record($this->submission, SubmissionEventType::Interview, now()->subDays(2)), 'already_ended');
        refused(fn () => events()->record($this->submission, SubmissionEventType::FollowUpSent, now()->subDays(1)), 'already_ended');
        expect($this->submission->events()->count())->toBe(1);
    })->with([SubmissionEventType::Rejected, SubmissionEventType::Withdrawn, SubmissionEventType::OfferAccepted, SubmissionEventType::OfferDeclined]);

    it('still lets earlier history be filled in, on or before the ending', function () {
        events()->record($this->submission, SubmissionEventType::Rejected, now()->subDays(3)->startOfSecond());

        events()->record($this->submission, SubmissionEventType::Interview, now()->subDays(5));
        events()->record($this->submission, SubmissionEventType::ConfirmationReceived, now()->subDays(3)->startOfSecond());

        expect($this->submission->events()->count())->toBe(3);
    });

    it('refuses an earlier-dated ending too, so two endings never sit side by side', function () {
        events()->record($this->submission, SubmissionEventType::Rejected, now()->subDays(3));

        refused(fn () => events()->record($this->submission, SubmissionEventType::Withdrawn, now()->subDays(5)), 'already_ended');
    });

    it('still lets a mistaken record be marked as one', function () {
        events()->record($this->submission, SubmissionEventType::OfferAccepted, now()->subDays(3));

        events()->record($this->submission, SubmissionEventType::RecordedInError, now());

        expect($this->submission->events()->count())->toBe(2);
    });

    it('does not block an offer being accepted or declined after it was received', function () {
        events()->record($this->submission, SubmissionEventType::OfferReceived, now()->subDays(4));

        events()->record($this->submission, SubmissionEventType::OfferAccepted, now()->subDays(2));

        expect($this->submission->events()->count())->toBe(2);
    });
});

describe('a calendar date entered for the event', function () {
    it('is kept exactly, whatever timezone it was entered from', function (string $tz) {
        $day = now()->subDays(2)->toDateString();
        $at = Carbon::parse($day, $tz)->startOfDay();

        $event = events()->record($this->submission, SubmissionEventType::ConfirmationReceived, $at, occurredOn: $day);

        expect($event->fresh()->occurred_on)->toBe($day)->and($event->fresh()->displayDate())->toBe($day);
    })->with(['Pacific/Honolulu', 'America/New_York', 'UTC', 'Europe/Berlin', 'Asia/Tokyo', 'Pacific/Kiritimati']);

    it('is judged against the submission by day, so the same day from another timezone is not "before"', function () {
        $submission = Submission::factory()->create(['submitted_at' => '2026-11-13 05:00:00', 'submitted_on' => '2026-11-13']);

        // Nov 13 in Tokyo starts at 15:00 UTC on Nov 12, an instant before the submission's, yet the same day.
        $event = events()->record($submission, SubmissionEventType::ConfirmationReceived, Carbon::parse('2026-11-13', 'Asia/Tokyo')->startOfDay(), occurredOn: '2026-11-13');

        expect($event->occurred_on)->toBe('2026-11-13');
        refused(fn () => events()->record($submission, SubmissionEventType::FollowUpSent, Carbon::parse('2026-11-12', 'Asia/Tokyo')->startOfDay(), occurredOn: '2026-11-12'), 'before_submission');
    });

    it('refuses a date that is not a real calendar date', function (string $date) {
        refused(fn () => events()->record($this->submission, SubmissionEventType::FollowUpSent, now()->subDay(), occurredOn: $date), 'occurred_on_invalid');
    })->with(['2026-02-30', '2026-13-01', 'yesterday']);

    it('falls back, documented, to the instant\'s date for an event recorded before dates were kept', function () {
        $event = SubmissionEvent::factory()->create(['submission_id' => $this->submission->id, 'occurred_at' => '2026-11-10 05:00:00', 'occurred_on' => null]);

        expect($event->displayDate())->toBe('2026-11-10');
    });
});

it('refuses sources nothing produces yet, so nothing is inferred', function (SubmissionEventSource $source) {
    refused(fn () => events()->record($this->submission, SubmissionEventType::ConfirmationReceived, now()->subDay(), $source), 'source_unsupported');
    expect(SubmissionEvent::count())->toBe(0);
})->with([SubmissionEventSource::Email, SubmissionEventSource::Automation]);

it('refuses a time before the submission, or in the future, but allows a scheduled interview', function () {
    refused(fn () => events()->record($this->submission, SubmissionEventType::Rejected, $this->submission->submitted_at->copy()->subMinute()), 'before_submission');
    refused(fn () => events()->record($this->submission, SubmissionEventType::Rejected, now()->addDay()), 'occurred_in_future');

    $scheduled = events()->record($this->submission, SubmissionEventType::Interview, now()->addDays(3));

    expect($scheduled->occurred_at->isFuture())->toBeTrue()->and(SubmissionEvent::count())->toBe(1);
});

describe('retries', function () {
    it('return the event already written for the same key', function () {
        $first = events()->record($this->submission, SubmissionEventType::FollowUpSent, now()->subDay(), idempotencyKey: 'k1');
        $retry = events()->record($this->submission, SubmissionEventType::FollowUpSent, now()->subHours(5), idempotencyKey: 'k1');

        expect($retry->id)->toBe($first->id)->and(SubmissionEvent::count())->toBe(1);
    });

    it('refuse a key already used for a different kind of event', function () {
        events()->record($this->submission, SubmissionEventType::FollowUpSent, now()->subDay(), idempotencyKey: 'k2');

        refused(fn () => events()->record($this->submission, SubmissionEventType::Rejected, now()->subDay(), idempotencyKey: 'k2'), 'idempotency_key_reused');
    });

    it('return the same event for an identical entry sent twice without a key', function () {
        $when = now()->subDays(2);

        $a = events()->record($this->submission, SubmissionEventType::ConfirmationReceived, $when);
        $b = events()->record($this->submission, SubmissionEventType::ConfirmationReceived, $when->copy());

        expect($b->id)->toBe($a->id)->and(SubmissionEvent::count())->toBe(1);
    });

    it('still allow a repeat of the same kind at a different time, such as a second interview', function () {
        events()->record($this->submission, SubmissionEventType::Interview, now()->subDays(3));
        events()->record($this->submission, SubmissionEventType::Interview, now()->subDays(1));

        expect(SubmissionEvent::count())->toBe(2);
    });

    it('allow the same key on a different submission', function () {
        $other = Submission::factory()->create(['submitted_at' => now()->subWeek()]);

        events()->record($this->submission, SubmissionEventType::FollowUpSent, now()->subDay(), idempotencyKey: 'shared');
        events()->record($other, SubmissionEventType::FollowUpSent, now()->subDay(), idempotencyKey: 'shared');

        expect(SubmissionEvent::count())->toBe(2);
    });
});

describe('recorded in error', function () {
    it('is an event, so nothing is deleted', function () {
        events()->record($this->submission, SubmissionEventType::ConfirmationReceived, now()->subDays(2));
        $error = events()->record($this->submission, SubmissionEventType::RecordedInError, now()->subDay(), note: 'I never actually applied.');

        expect($this->submission->events()->count())->toBe(2)
            ->and($error->note)->toBe('I never actually applied.')
            ->and(Submission::count())->toBe(1);
    });

    it('closes the record to anything further', function () {
        events()->record($this->submission, SubmissionEventType::RecordedInError, now()->subDay());

        refused(fn () => events()->record($this->submission, SubmissionEventType::Interview, now()->subHour()), 'recorded_in_error');
        expect($this->submission->events()->count())->toBe(1);
    });

    it('is idempotent when recorded twice at the same time', function () {
        $when = now()->subDay();

        $a = events()->record($this->submission, SubmissionEventType::RecordedInError, $when);
        $b = events()->record($this->submission, SubmissionEventType::RecordedInError, $when->copy());

        expect($b->id)->toBe($a->id)->and($this->submission->events()->count())->toBe(1);
    });
});

describe('evidence', function () {
    it('keeps a self-reported or link reference', function () {
        $a = events()->record($this->submission, SubmissionEventType::ConfirmationReceived, now()->subDays(2), evidence: ['kind' => 'self_reported']);
        $b = events()->record($this->submission, SubmissionEventType::ConfirmationReceived, now()->subDays(1), evidence: ['kind' => 'url', 'reference' => 'https://example.com/thanks']);

        expect($a->evidence)->toBe(['kind' => 'self_reported'])->and($b->evidence)->toBe(['kind' => 'url', 'reference' => 'https://example.com/thanks']);
    });

    it('refuses kinds nothing produces yet, unknown keys, and bad links', function (array $evidence) {
        refused(fn () => events()->record($this->submission, SubmissionEventType::ConfirmationReceived, now()->subDay(), evidence: $evidence), 'evidence_invalid');
        expect(SubmissionEvent::count())->toBe(0);
    })->with([
        'email is reserved' => [['kind' => 'email', 'reference' => 'msg-1']],
        'automation is reserved' => [['kind' => 'automation']],
        'unknown kind' => [['kind' => 'screenshot']],
        'no kind' => [['reference' => 'x']],
        'extra keys' => [['kind' => 'self_reported', 'raw' => '<script>']],
        'link that is not http' => [['kind' => 'url', 'reference' => 'javascript:alert(1)']],
        'link missing' => [['kind' => 'url']],
    ]);

    it('treats empty evidence as none', function () {
        expect(events()->record($this->submission, SubmissionEventType::FollowUpSent, now()->subDay(), evidence: [])->evidence)->toBeNull();
    });
});
