<?php

namespace App\Support\Clarification;

use App\Models\CareerLimitation;
use App\Models\CareerProfile;

/**
 * Read side of CareerLimitation: the words a resume must not claim.
 */
final class ActiveLimitations
{
    /**
     * @return array<int, string>
     */
    public static function terms(CareerProfile $profile): array
    {
        return CareerLimitation::query()
            ->where('career_profile_id', $profile->id)
            ->active()
            ->get()
            ->flatMap(fn (CareerLimitation $limitation) => $limitation->terms)
            ->map(fn (string $term) => trim($term))
            ->filter(fn (string $term) => $term !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Whole-phrase, case-insensitive match of any limited term inside
     * $text, so "Rails" never matches "trails".
     *
     * @param  array<int, string>  $limitedTerms
     */
    public static function matches(string $text, array $limitedTerms): bool
    {
        foreach ($limitedTerms as $term) {
            if (preg_match('/(?<![\w])'.preg_quote($term, '/').'(?![\w])/iu', $text) === 1) {
                return true;
            }
        }

        return false;
    }
}
