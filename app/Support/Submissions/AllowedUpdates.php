<?php

namespace App\Support\Submissions;

use App\Enums\SubmissionEventType;
use App\Enums\SubmissionPhase;

/**
 * Which updates it makes sense to offer for an application as it stands: a single place, derived from the same summary
 * the status comes from, so the screen never offers what the state rules out. This only chooses what to offer; the rules
 * themselves are enforced by RecordSubmissionEvent. An application that has ended, or was a mistake, offers none (a
 * mistake is corrected with `recorded_in_error`, which is a separate action and never listed here). Accepting or
 * declining is offered once an offer has been recorded.
 */
final class AllowedUpdates
{
    /**
     * @return list<SubmissionEventType>
     */
    public static function for(SubmissionSummary $summary): array
    {
        if (! $summary->active) {
            return [];
        }

        $offered = $summary->phase === SubmissionPhase::OfferReceived;

        return array_values(array_filter([
            $summary->confirmed ? null : SubmissionEventType::ConfirmationReceived,
            SubmissionEventType::FollowUpSent,
            SubmissionEventType::Interview,
            $offered ? null : SubmissionEventType::OfferReceived,
            $offered ? SubmissionEventType::OfferAccepted : null,
            $offered ? SubmissionEventType::OfferDeclined : null,
            SubmissionEventType::Rejected,
            SubmissionEventType::Withdrawn,
        ]));
    }
}
