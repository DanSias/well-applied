<?php

use App\Enums\SubmissionEventSource;
use App\Enums\SubmissionEventType;
use App\Models\Submission;
use App\Models\SubmissionEvent;
use App\Support\Submissions\RecordSubmissionEvent;
use App\Support\Submissions\SubmissionException;

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

it('accepts every supported type', function () {
    foreach (SubmissionEventType::cases() as $i => $type) {
        if ($type === SubmissionEventType::RecordedInError) {
            continue;
        }

        events()->record($this->submission, $type, now()->subDays(6)->addMinutes($i));
    }

    expect($this->submission->events()->count())->toBe(count(SubmissionEventType::cases()) - 1);
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
