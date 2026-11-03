<?php

namespace App\Support\Clarification;

use App\Models\CareerFact;
use App\Models\CareerProfile;
use App\Models\Evidence;

/**
 * The responsibility scopes the user selected when confirming a fact, read
 * from the newest clarification-origin evidence row (each revision of a fact
 * appends its own, so the latest declaration is the one in force). A fact
 * that did not come from a clarification has no declared scope; the claim
 * checker then falls back to what its own statement says.
 */
final class DeclaredScopes
{
    /**
     * @param  array<int, string>  $factKeys
     * @return array<string, array<int, string>> fact key => scope values
     */
    public static function forFacts(CareerProfile $profile, array $factKeys): array
    {
        $idsByKey = CareerFact::query()
            ->where('career_profile_id', $profile->id)
            ->whereIn('key', $factKeys)
            ->pluck('key', 'id');

        if ($idsByKey->isEmpty()) {
            return [];
        }

        $declared = [];

        Evidence::query()
            ->whereIn('career_fact_id', $idsByKey->keys())
            ->orderBy('id')
            ->get(['career_fact_id', 'metadata'])
            ->each(function (Evidence $evidence) use ($idsByKey, &$declared): void {
                $metadata = $evidence->metadata ?? [];

                if (($metadata['origin'] ?? null) === 'clarification' && is_array($metadata['scopes'] ?? null)) {
                    $declared[(string) $idsByKey[$evidence->career_fact_id]] = array_values(array_map('strval', $metadata['scopes']));
                }
            });

        return $declared;
    }
}
