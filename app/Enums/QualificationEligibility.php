<?php

namespace App\Enums;

/**
 * What the deterministic gate could establish about practical eligibility. Only explicit, reliable evidence makes a job
 * Ineligible; anything unclear is Unknown, and a posting too thin to judge is InsufficientInformation. Neither of those
 * is ever a rejection.
 */
enum QualificationEligibility: string
{
    case Eligible = 'eligible';
    case Ineligible = 'ineligible';
    case Unknown = 'unknown';
    case InsufficientInformation = 'insufficient_information';

    public function label(): string
    {
        return match ($this) {
            self::Eligible => 'Eligible',
            self::Ineligible => 'Not eligible',
            self::Unknown => 'Eligibility unclear',
            self::InsufficientInformation => 'Not enough information',
        };
    }

    /** Whether a model should be asked about this job at all. */
    public function allowsModel(): bool
    {
        return $this === self::Eligible || $this === self::Unknown;
    }
}
