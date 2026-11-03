<?php

namespace App\Support\ResumeProfile;

use App\Enums\ResumeProfileStatus;
use App\Models\CareerProfile;
use App\Models\ResumeProfile;
use App\Support\JobDiscovery\DiscoverySearchCriteria;
use App\Support\JobDiscovery\SearchProfile;
use InvalidArgumentException;

/**
 * The configured Resume Profile definitions (config/resume_profiles.php) and
 * the one place a stored ResumeProfile row is created from one. Adding a
 * category later is a config entry; nothing here is specific to Full-Stack.
 */
final class ProfileDefinitions
{
    /**
     * @return array<string, mixed>
     */
    public static function config(string $slug): array
    {
        $definition = config("resume_profiles.profiles.{$slug}");

        if (! is_array($definition)) {
            throw new InvalidArgumentException("Unknown Resume Profile [{$slug}].");
        }

        return $definition;
    }

    /**
     * @return array<int, string>
     */
    public static function slugs(): array
    {
        return array_keys((array) config('resume_profiles.profiles'));
    }

    public static function ensure(CareerProfile $careerProfile, string $slug): ResumeProfile
    {
        $definition = self::config($slug);

        return ResumeProfile::query()->firstOrCreate(
            ['career_profile_id' => $careerProfile->id, 'slug' => $slug],
            ['name' => $definition['name'], 'role_family' => $definition['role_family'], 'purpose' => $definition['purpose'], 'status' => ResumeProfileStatus::Draft, 'revision' => 1, 'evidence_revision' => 0],
        );
    }

    /**
     * The profile's search, with any per-profile overrides in `search_parameters` applied.
     *
     * @return array<string, mixed>
     */
    public static function search(ResumeProfile $profile): array
    {
        return array_replace((array) self::config($profile->slug)['search'], (array) ($profile->search_parameters ?? []));
    }

    public static function searchProfile(ResumeProfile $profile): SearchProfile
    {
        $s = self::search($profile);

        return new SearchProfile(
            $profile->role_family, (string) $s['label'], (array) $s['include_terms'], (array) $s['exclude_terms'], $s['preferred_seniority'] ?? null,
            (string) $s['remote_preference'], (array) $s['allowed_locations'], $s['employment_type'] ?? null, $profile->purpose,
        );
    }

    public static function criteria(ResumeProfile $profile): DiscoverySearchCriteria
    {
        $s = self::search($profile);

        return new DiscoverySearchCriteria(
            (array) $s['include_terms'], (array) $s['exclude_terms'], (array) ($s['excluded_title_keywords'] ?? []),
            (string) $s['remote_preference'], (array) $s['allowed_locations'], $s['employment_type'] ?? null,
        );
    }
}
