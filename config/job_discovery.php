<?php

return [

    /*
    | The V1 deterministic discovery criteria — see App\Support\
    | JobDiscovery\DiscoverySearchCriteria::fromConfig() and
    | App\Support\JobDiscovery\FilterDiscoveredCandidates. A plain
    | config file, not a database-backed settings table: this is a
    | single-user, single-search-profile system with no settings UI
    | anywhere in the app today, so a config file is the smallest
    | maintainable representation — see docs/job-discovery.md "Search
    | configuration location". Edit this file (not adapter code) to
    | change what discovery looks for.
    |
    | DISCOVERY filters only — never fit scoring. `keywords` includes
    | role/technology terms matched against the title in one OR-matched
    | list (see DiscoverySearchCriteria's own docblock for why), tuned
    | to this user's actual target profile: Senior/Staff full-stack,
    | backend/API, data-heavy, developer tooling, and AI-enabled
    | product engineering, remote US.
    */
    'search' => [
        'keywords' => [
            'software engineer', 'full stack', 'full-stack', 'backend',
            'back-end', 'back end', 'api engineer', 'platform engineer',
            'developer tools', 'developer experience', 'data engineer',
            'data pipeline', 'analytics engineer',
        ],

        'excluded_keywords' => [
            'intern', 'internship', 'entry level', 'entry-level',
            'junior', 'jr.', 'new grad', 'new graduate',
        ],

        /*
        | TITLE-ONLY role-identity exclusions: matched against the
        | candidate's TITLE, never its description. Deliberately
        | separate from `excluded_keywords` (which checks
        | title+description) because DevOps/SRE terminology in a
        | job DESCRIPTION is common in legitimate product-engineering
        | roles and must not reject them. Keep this list narrow —
        | obvious SRE/Site Reliability/primarily-DevOps role
        | identities only, per the observed false positive
        | "Principal Software Engineer, DevOps" that downstream
        | analysis could not support.
        */
        'excluded_title_keywords' => [
            'site reliability', 'sre', 'devops',
        ],

        // 'remote_us' or 'any' — see FilterDiscoveredCandidates.
        'remote_preference' => 'remote_us',

        // Fallback location-text matching only, used when a
        // candidate's remote_status is unknown.
        'allowed_locations' => ['United States', 'US', 'USA', 'Remote'],

        // Null = don't filter by employment type. A candidate's own
        // employment_type is only ever checked when both this AND the
        // candidate's own value are non-null.
        'employment_type' => null,
    ],

    /*
    | Automatic scheduling — see bootstrap/app.php's withSchedule(),
    | App\Support\JobDiscovery\DiscoveryScheduleIsDue and
    | docs/job-discovery.md "Scheduling / orchestration". The schedule
    | checks hourly but only runs the providers that are individually
    | due, judged from each provider's own latest attempt:
    |
    |   - stale_after_hours: a provider whose last attempt SUCCEEDED
    |     is due again once that attempt is this old (the daily
    |     cadence, and "catch up if the app wasn't running").
    |   - retry_after_hours: a provider whose last attempt FAILED is
    |     retried once that attempt is this old — bounded, so a broken
    |     or quota-exhausted provider is never hammered hourly, yet
    |     always eventually retried so transient failures self-heal.
    |
    | One provider failing never re-runs the healthy ones.
    */
    'schedule' => [
        'stale_after_hours' => (int) env('JOB_DISCOVERY_STALE_AFTER_HOURS', 23),
        'retry_after_hours' => (int) env('JOB_DISCOVERY_RETRY_AFTER_HOURS', 6),
    ],

];
