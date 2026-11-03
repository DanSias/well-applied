<?php

namespace App\Support\Clarification;

use App\Models\CareerProfile;
use App\Models\PresentationPreference;

/**
 * Read side of PresentationPreference: how the user wants existing
 * experience emphasized. Returned as plain data (not models) so a future
 * Resume Profile can supply its own list to Selection through the same shape;
 * profile-specific filtering belongs here, not in the prompt.
 */
final class ActivePresentationPreferences
{
    /**
     * @return array<int, array{topic_key: string, topic: string, guidance: string}>
     */
    public static function forProfile(CareerProfile $profile): array
    {
        return PresentationPreference::query()
            ->where('career_profile_id', $profile->id)
            ->active()
            ->orderBy('id')
            ->get()
            ->map(fn (PresentationPreference $p) => ['topic_key' => $p->topic_key, 'topic' => $p->topic, 'guidance' => $p->guidance])
            ->all();
    }
}
