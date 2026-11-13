<?php

namespace App\Support\Submissions;

use App\Enums\SubmissionEventType;
use App\Enums\SubmissionPhase;
use App\Models\Submission;
use App\Models\SubmissionEvent;
use Illuminate\Support\Collection;

/**
 * Where a submission stands, derived from its events on every call and never stored (there is no status column to
 * drift out of step with the history). Deterministic, so it can be tested exhaustively:
 *
 * 1. Events are ordered by the day they happened (the date entered), then the instant, then when they were recorded
 *    (`id`), so a late entry takes its true place and never overrides something that happened after it.
 * 2. `recorded_in_error` on any event makes the whole submission a mistaken record: phase RecordedInError, not active,
 *    not confirmed, whatever else was recorded. Nothing can undo it, and nothing more can be recorded after it.
 * 3. Otherwise, if any ending was recorded (rejected, withdrawn, offer accepted, offer declined), the latest ending by
 *    the order above is the phase, and the submission is no longer active. A later-recorded earlier event, or a stray
 *    later interview, never reopens it. Two different kinds of ending are flagged as conflicting; the latest stands.
 * 4. Otherwise the phase is the furthest stage reached, not the latest event: offer received, then interviewing
 *    (any interview), then confirmed, then submitted. So a confirmation that arrives late never moves an interviewing
 *    application back to confirmed. Repeated interviews are one phase, counted.
 * 5. A follow-up changes nothing about the phase; it is only counted.
 * 6. `confirmed` is simply whether a confirmation was recorded; `latestEvent` is the last event by the order above.
 *
 * What it can never do is infer anything: a submission with no events is Submitted, and only recorded events move it.
 */
final class SummarizeSubmission
{
    public function forSubmission(Submission $submission): SubmissionSummary
    {
        return $this->fromEvents($submission->events()->get());
    }

    /**
     * @param  Collection<int, SubmissionEvent>  $events
     */
    public function fromEvents(Collection $events): SubmissionSummary
    {
        $events = $events->sortBy(fn (SubmissionEvent $e) => [$e->displayDate(), $e->occurred_at->getTimestamp(), $e->id])->values();
        $of = fn (SubmissionEventType $type) => $events->filter(fn (SubmissionEvent $e) => $e->type === $type);
        $interviews = $of(SubmissionEventType::Interview)->count();
        $followUps = $of(SubmissionEventType::FollowUpSent)->count();
        $error = $of(SubmissionEventType::RecordedInError);

        if ($error->isNotEmpty()) {
            return new SubmissionSummary(SubmissionPhase::RecordedInError, false, true, false, $interviews, $followUps, false, $error->last());
        }

        $confirmed = $of(SubmissionEventType::ConfirmationReceived)->isNotEmpty();
        $endings = $events->filter(fn (SubmissionEvent $e) => $e->type->isTerminal());
        $latest = $events->last();

        if ($endings->isNotEmpty()) {
            $phase = SubmissionPhase::from($endings->last()->type->value);

            return new SubmissionSummary($phase, false, false, $confirmed, $interviews, $followUps, $endings->pluck('type')->unique()->count() > 1, $latest);
        }

        $phase = match (true) {
            $of(SubmissionEventType::OfferReceived)->isNotEmpty() => SubmissionPhase::OfferReceived,
            $interviews > 0 => SubmissionPhase::Interviewing,
            $confirmed => SubmissionPhase::Confirmed,
            default => SubmissionPhase::Submitted,
        };

        return new SubmissionSummary($phase, true, false, $confirmed, $interviews, $followUps, false, $latest);
    }
}
