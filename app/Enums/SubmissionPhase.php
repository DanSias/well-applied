<?php

namespace App\Enums;

/** Where a submission stands, derived from its events and never stored. See SummarizeSubmission for the rules. */
enum SubmissionPhase: string
{
    case Submitted = 'submitted';
    case Confirmed = 'confirmed';
    case Interviewing = 'interviewing';
    case OfferReceived = 'offer_received';
    case Rejected = 'rejected';
    case Withdrawn = 'withdrawn';
    case OfferDeclined = 'offer_declined';
    case OfferAccepted = 'offer_accepted';
    case RecordedInError = 'recorded_in_error';

    /** Whether the application is still in flight: not ended, and not a mistaken record. */
    public function isActive(): bool
    {
        return match ($this) {
            self::Submitted, self::Confirmed, self::Interviewing, self::OfferReceived => true,
            default => false,
        };
    }
}
