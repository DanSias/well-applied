<?php

namespace App\Enums;

/**
 * Things that can happen to a submission. These are events, not statuses: an interview can repeat, a confirmation
 * can arrive late, and how an application stands is derived from all of them (see SummarizeSubmission).
 */
enum SubmissionEventType: string
{
    case ConfirmationReceived = 'confirmation_received';
    case FollowUpSent = 'follow_up_sent';
    case Interview = 'interview';
    case Rejected = 'rejected';
    case Withdrawn = 'withdrawn';
    case OfferReceived = 'offer_received';
    case OfferAccepted = 'offer_accepted';
    case OfferDeclined = 'offer_declined';
    case RecordedInError = 'recorded_in_error';

    /** An event that ends the application: nothing is pending after it. */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Rejected, self::Withdrawn, self::OfferAccepted, self::OfferDeclined], true);
    }
}
