<?php

namespace App\Enums;

/**
 * Why the global discovery filter turned a candidate away. One primary reason per rejected candidate; the codes are stored
 * (discovery_rejections.reason_code, discovery_provider_attempts.rejection_counts) so they must stay stable.
 */
enum DiscoveryRejectionReason: string
{
    case TitleNotMatching = 'title_not_matching';
    case ExcludedTitleRole = 'excluded_title_role';
    case ExcludedSeniority = 'excluded_seniority';
    case LocationNotAllowed = 'location_not_allowed';
    case WorkArrangementNotAllowed = 'work_arrangement_not_allowed';
    case EmploymentTypeNotAllowed = 'employment_type_not_allowed';

    public function label(): string
    {
        return match ($this) {
            self::TitleNotMatching => 'Title is not a target role',
            self::ExcludedTitleRole => 'Excluded role type',
            self::ExcludedSeniority => 'Junior or intern role',
            self::LocationNotAllowed => 'Location not eligible',
            self::WorkArrangementNotAllowed => 'On-site or hybrid',
            self::EmploymentTypeNotAllowed => 'Employment type',
        };
    }
}
