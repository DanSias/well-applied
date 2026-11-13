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
        };
    }
}
