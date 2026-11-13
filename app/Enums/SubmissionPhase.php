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

    /** The short label shown to the person; the user-facing word for a submission is "application". */
    public function label(): string
    {
        return match ($this) {
            self::Submitted => 'Applied',
            self::Confirmed => 'Confirmed',
            self::Interviewing => 'Interviewing',
            self::OfferReceived => 'Offer received',
            self::Rejected => 'Rejected',
            self::Withdrawn => 'Withdrawn',
            self::OfferDeclined => 'Offer declined',
            self::OfferAccepted => 'Offer accepted',
            self::RecordedInError => 'Recorded in error',
        };
    }

    /** Whether the application is still in flight: not ended, and not a mistaken record. */
    public function isActive(): bool
    {
        return match ($this) {
            self::Submitted, self::Confirmed, self::Interviewing, self::OfferReceived => true,
            default => false,
        };
    }
}
