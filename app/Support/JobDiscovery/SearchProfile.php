<?php

namespace App\Support\JobDiscovery;

/**
 * A named, reusable discovery search profile: a description of the
 * KIND of jobs Well Applied should discover for one role family,
 * defined purely in the `search_profiles` section of
 * config/job_discovery.php and loaded (and validated) by
 * App\Support\JobDiscovery\LoadSearchProfiles. No provider ever sees
 * this object — it describes what each provider should be asked to
 * find, independently of which provider is answering.
 *
 * Deliberately distinct from DiscoverySearchCriteria: that class is
 * the ACTIVE V1 post-retrieval filter (candidate matching against
 * title/description), while a SearchProfile describes a role family
 * in provider-neutral terms so the same family can later be translated
 * into any provider's native search (Hirebase's first).
 *
 * Inert by design: nothing in discovery reads profiles yet. The V1
 * `search` criteria remain the only active filter, and
 * App\Support\JobDiscovery\RunJobDiscovery and
 * App\Support\JobDiscovery\FilterDiscoveredCandidates are unaffected
 * by profiles' existence.
 *
 * readonly on purpose, following DiscoveredJobCandidate: a profile is
 * a snapshot of config taken at load time and is never mutated
 * afterward.
 */
final readonly class SearchProfile
{
    /**
     * @param  string[]  $includeTerms  Discovery-oriented terms describing the role family —
     *                                  deliberately broad, not over-restrictive: a profile
     *                                  should name the whole kind of role it targets, not a
     *                                  narrow phrase. Never a hiring criterion.
     * @param  string[]  $excludeTerms  Terms that clearly identify jobs the family does NOT
     *                                  cover (junior/intern roles, adjacent non-technical
     *                                  roles). Empty means "exclude nothing", mirroring how
     *                                  FilterDiscoveredCandidates treats empty exclusion
     *                                  lists. NOTE: never role-identity terms like
     *                                  'devops'/'sre' — V1 keeps those TITLE-ONLY
     *                                  (excluded_title_keywords) precisely because
     *                                  infrastructure terminology in job DESCRIPTIONS is
     *                                  common in legitimate product-engineering roles; a
     *                                  profile must not regress that design.
     * @param  string[]  $allowedLocations  Location TEXT only, mirroring the semantics of
     *                                      `search.allowed_locations`: a fallback consulted
     *                                      only when a candidate's structured remote status
     *                                      is unknown — never an override. Empty means "no
     *                                      location text check".
     */
    public function __construct(
        /** Unique identifier — the profile's key in the `search_profiles` config section. */
        public string $identifier,

        /** Human-readable name for logs and future UI; never used for matching. */
        public string $label,

        public array $includeTerms,

        public array $excludeTerms,

        /** Soft preference (e.g. "Senior") — a hint to a future provider-query
         *  translation, never a hard filter on its own. Null = no preference. */
        public ?string $preferredSeniority,

        /** One of "remote_us" / "any" — the exact values the V1 criteria support
         *  (see FilterDiscoveredCandidates); a typo is rejected at load time. */
        public string $remotePreference,

        public array $allowedLocations,

        /** Soft preference (e.g. "full_time"); only ever checked when both the
         *  profile and the candidate specify a value, mirroring V1's null semantics. */
        public ?string $employmentType,

        /** Optional semantic description — prose for documentation or future
         *  semantic matching. Null when the config section omits it. */
        public ?string $description,
    ) {}
}
