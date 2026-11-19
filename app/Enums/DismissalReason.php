<?php

namespace App\Enums;

/** Why a job was dismissed. A small fixed set, so dismissals can be reviewed and counted later. */
enum DismissalReason: string
{
    case Seniority = 'seniority';
    case TechnologyStack = 'technology_stack';
    case Location = 'location';
    case Compensation = 'compensation';
    case Company = 'company';
    case Duplicate = 'duplicate';
    case Other = 'other';

    // Why someone stopped pursuing an opportunity (see StopPursuingRequest). Kept apart from the reasons for passing on
    // a discovered job, so the Jobs page's dismissal list stays about jobs and nothing here is a misleading stand-in.
    case PostingClosed = 'posting_closed';
    case NoLongerInterested = 'no_longer_interested';
    case NotAGoodFit = 'not_a_good_fit';

    /** The reasons offered when ending pursuit; `other` is shared with plain dismissal. */
    public function endsPursuit(): bool
    {
        return in_array($this, [self::PostingClosed, self::NoLongerInterested, self::NotAGoodFit], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Seniority => 'Seniority',
            self::TechnologyStack => 'Technology stack',
            self::Location => 'Location',
            self::Compensation => 'Compensation',
            self::Company => 'Company',
            self::Duplicate => 'Duplicate',
            self::Other => 'Other',
            self::PostingClosed => 'Applications closed',
            self::NoLongerInterested => 'No longer interested',
            self::NotAGoodFit => 'Not a good fit',
        };
    }
}
