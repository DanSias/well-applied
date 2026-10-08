<?php

namespace App\Support\JobDiscovery;

/**
 * Loads and validates the named `search_profiles` section of
 * config/job_discovery.php into immutable SearchProfile values.
 *
 * Deliberately a plain static class, not a container-bound service:
 * profiles are config data, not injected collaborators, so this mirrors
 * DiscoverySearchCriteria::fromConfig() — config is re-read on every
 * call (no caching, so tests can override the section per-test), and a
 * whole-section load is the only shape needed. Nothing in V1 calls this
 * yet — profiles are inert until a later phase translates them into
 * provider searches (Hirebase's first); see SearchProfile.
 *
 * Validation is strict on purpose: a profile will one day seed a live
 * provider query, so a typo'd field name or a half-configured entry
 * should fail HERE, with a message that names the profile identifier
 * and the exact offending field, rather than surfacing later as a
 * confusing provider failure. All failures throw
 * SearchProfileConfigurationException.
 */
final class LoadSearchProfiles
{
    /** The only remote postures the V1 criteria understand —
     *  see FilterDiscoveredCandidates. Anything else is a typo. */
    private const REMOTE_PREFERENCES = ['remote_us', 'any'];

    /** Every field a profile entry may define. Unknown keys (e.g.
     *  'include_term') are rejected: a typo must fail loudly instead
     *  of being silently ignored and then "discovered" months later. */
    private const FIELDS = [
        'label',
        'include_terms',
        'exclude_terms',
        'remote_preference',
        'preferred_seniority',
        'allowed_locations',
        'employment_type',
        'description',
    ];

    /**
     * @return list<SearchProfile>
     */
    public static function all(): array
    {
        $section = config('job_discovery.search_profiles');

        if ($section === null) {
            return [];
        }

        if (! is_array($section)) {
            throw new SearchProfileConfigurationException(sprintf(
                'job_discovery.search_profiles must be an array of identifier => field entries, got %s.',
                get_debug_type($section),
            ));
        }

        $profiles = [];

        foreach ($section as $identifier => $entry) {
            if (! is_string($identifier) || $identifier === '') {
                throw new SearchProfileConfigurationException(sprintf(
                    'Every search profile must be keyed by a non-empty string identifier, got %s.',
                    (string) var_export($identifier, true),
                ));
            }

            $profiles[] = self::fromEntry($identifier, $entry);
        }

        return $profiles;
    }

    public static function byId(string $identifier): SearchProfile
    {
        $profiles = self::all();

        foreach ($profiles as $profile) {
            if ($profile->identifier === $identifier) {
                return $profile;
            }
        }

        $configured = array_map(static fn (SearchProfile $profile): string => $profile->identifier, $profiles);

        throw new SearchProfileConfigurationException(sprintf(
            'Unknown search profile "%s". Configured profiles: %s.',
            $identifier,
            $configured === [] ? '(none)' : implode(', ', $configured),
        ));
    }

    private static function fromEntry(string $identifier, mixed $entry): SearchProfile
    {
        if (! is_array($entry)) {
            throw new SearchProfileConfigurationException(sprintf(
                'Search profile "%s" must define an array of fields, got %s.',
                $identifier,
                get_debug_type($entry),
            ));
        }

        $unknown = array_values(array_diff(array_keys($entry), self::FIELDS));

        if ($unknown !== []) {
            throw new SearchProfileConfigurationException(sprintf(
                'Search profile "%s" has unknown field(s) %s. Allowed fields: %s.',
                $identifier,
                implode(', ', $unknown),
                implode(', ', self::FIELDS),
            ));
        }

        return new SearchProfile(
            identifier: $identifier,
            label: self::required('label', $entry, $identifier),
            includeTerms: self::terms('include_terms', $entry, $identifier, required: true),
            excludeTerms: self::terms('exclude_terms', $entry, $identifier, required: true),
            preferredSeniority: self::optional('preferred_seniority', $entry, $identifier),
            remotePreference: self::remotePreference($entry, $identifier),
            allowedLocations: self::terms('allowed_locations', $entry, $identifier, required: false),
            employmentType: self::optional('employment_type', $entry, $identifier),
            description: self::optional('description', $entry, $identifier),
        );
    }

    /**
     * Required scalar field: present, string, non-empty after trim.
     *
     * @param  array<string, mixed>  $entry
     */
    private static function required(string $field, array $entry, string $identifier): string
    {
        if (! array_key_exists($field, $entry)) {
            return self::throwMissing($identifier, $field, $entry);
        }

        $value = $entry[$field];

        if (! is_string($value) || trim($value) === '') {
            throw new SearchProfileConfigurationException(sprintf(
                'Search profile "%s" field "%s" must be a non-empty string, got %s.',
                $identifier,
                $field,
                get_debug_type($value),
            ));
        }

        return trim($value);
    }

    /**
     * List field of non-empty strings. Whitespace-only entries are
     * dropped (harmless noise, not a misconfiguration). A required
     * list must have at least one entry left; an optional one defaults
     * to [] when absent or null — both map to the VO's "no terms"
     * semantics.
     *
     * @param  array<string, mixed>  $entry
     * @return list<string>
     */
    private static function terms(string $field, array $entry, string $identifier, bool $required): array
    {
        if (! array_key_exists($field, $entry)) {
            if ($required) {
                return self::throwMissing($identifier, $field, $entry);
            }

            return [];
        }

        $value = $entry[$field];

        if ($value === null) {
            if ($required) {
                throw new SearchProfileConfigurationException(sprintf(
                    'Search profile "%s" field "%s" is required and must be a list of strings, got null.',
                    $identifier,
                    $field,
                ));
            }

            return [];
        }

        if (! is_array($value)) {
            throw new SearchProfileConfigurationException(sprintf(
                'Search profile "%s" field "%s" must be a list of strings, got %s.',
                $identifier,
                $field,
                get_debug_type($value),
            ));
        }

        $terms = [];

        foreach ($value as $term) {
            if (! is_string($term)) {
                throw new SearchProfileConfigurationException(sprintf(
                    'Search profile "%s" field "%s" must contain only strings, got %s.',
                    $identifier,
                    $field,
                    get_debug_type($term),
                ));
            }

            $term = trim($term);

            if ($term !== '') {
                $terms[] = $term;
            }
        }

        if ($required && $terms === []) {
            throw new SearchProfileConfigurationException(sprintf(
                'Search profile "%s" field "%s" must contain at least one non-empty entry.',
                $identifier,
                $field,
            ));
        }

        return $terms;
    }

    /**
     * Optional scalar field: absent or explicit null → null (no
     * preference); a whitespace-only string also normalizes to null;
     * any other non-string is a misconfiguration.
     *
     * @param  array<string, mixed>  $entry
     */
    private static function optional(string $field, array $entry, string $identifier): ?string
    {
        $value = array_key_exists($field, $entry) ? $entry[$field] : null;

        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new SearchProfileConfigurationException(sprintf(
                'Search profile "%s" field "%s" must be a string or null, got %s.',
                $identifier,
                $field,
                get_debug_type($value),
            ));
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private static function remotePreference(array $entry, string $identifier): string
    {
        $field = 'remote_preference';

        if (! array_key_exists($field, $entry)) {
            return self::throwMissing($identifier, $field, $entry);
        }

        $value = $entry[$field];

        if (! is_string($value) || ! in_array($value, self::REMOTE_PREFERENCES, true)) {
            throw new SearchProfileConfigurationException(sprintf(
                'Search profile "%s" field "%s" must be either "remote_us" or "any", got %s.',
                $identifier,
                $field,
                is_string($value) ? sprintf('"%s"', $value) : get_debug_type($value),
            ));
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private static function throwMissing(string $identifier, string $field, array $entry): never
    {
        $defined = array_keys($entry);

        throw new SearchProfileConfigurationException(sprintf(
            'Search profile "%s" is missing required field "%s". Currently defined: %s.',
            $identifier,
            $field,
            $defined === [] ? '(none)' : implode(', ', $defined),
        ));
    }
}
