<?php

namespace App\Support\Clarification;

use App\Models\CareerProfile;

/**
 * The freshness signal between approved evidence and everything built from
 * the fact corpus. Bump it whenever a CareerFact is created or changed by
 * approved evidence; JobMatch rows remember the value they were built at,
 * and TailoredResumePipeline reuses a match only while it still equals the
 * profile's current value. Changing the corpus therefore re-runs matching
 * (and so Selection, whose checkpoint fingerprint includes the candidate
 * payload) instead of silently reusing a match that predates the evidence.
 */
final class EvidenceRevision
{
    public static function current(CareerProfile $profile): int
    {
        return (int) CareerProfile::query()->whereKey($profile->id)->value('evidence_revision');
    }

    public static function bump(CareerProfile $profile): int
    {
        CareerProfile::query()->whereKey($profile->id)->increment('evidence_revision');

        return self::current($profile);
    }
}
