<?php

return [

    /*
    | The V1 deterministic discovery criteria — see App\Support\
    | JobDiscovery\DiscoverySearchCriteria::fromConfig() and
    | App\Support\JobDiscovery\FilterDiscoveredCandidates. A plain
    | config file, not a database-backed settings table: this is a
    | single-user job-search tool with no settings UI
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
    | Named, reusable discovery SEARCH PROFILES — one entry per role
    | family Career Toolkit should discover. Loaded (and strictly
    | validated — typos and malformed values fail loudly) by
    | App\Support\JobDiscovery\LoadSearchProfiles into immutable
    | App\Support\JobDiscovery\SearchProfile values.
    |
    | INERT IN V1: nothing in discovery reads these yet. The `search`
    | section above remains the only ACTIVE filter. Profiles exist so a
    | later phase can translate one family into any provider's native
    | search (Hirebase's first) without changing what V1 matches.
    |
    | Field rules (violations throw SearchProfileConfigurationException):
    |   - REQUIRED: label (non-empty string),
    |     include_terms (list with at least one non-empty entry),
    |     exclude_terms (list; empty = excludes nothing),
    |     remote_preference ('remote_us' or 'any').
    |   - OPTIONAL (absent or explicit null = "no preference"):
    |     preferred_seniority, allowed_locations (location text only,
    |     same fallback semantics as `search.allowed_locations`),
    |     employment_type, description.
    |   - Unknown field names (typos) are REJECTED, not ignored.
    |
    | DISCOVERY breadth, not hiring: include_terms name the whole kind
    | of role a family covers and stay deliberately broad.
    | exclude_terms only drop roles the family clearly does not cover —
    | never role-identity terms like 'devops'/'sre': V1 keeps those
    | TITLE-ONLY in `excluded_title_keywords` above precisely because
    | DevOps/SRE language in a job DESCRIPTION is common in legitimate
    | product-engineering roles, and profiles must not regress that.
    */
    'search_profiles' => [
        'software_engineering' => [
            'label' => 'Software Engineering',
            'description' => 'Senior/Staff product engineering: full-stack, backend/API, data-heavy services, developer tooling and AI-enabled features.',
            'include_terms' => [
                'software engineer', 'full stack', 'full-stack', 'fullstack',
                'backend', 'back end', 'back-end', 'api engineer',
                'platform engineer', 'developer tools', 'developer experience',
                'data engineer', 'data pipeline', 'analytics engineer',
            ],
            'exclude_terms' => [
                'intern', 'internship', 'entry level', 'entry-level',
                'junior', 'jr.', 'new grad', 'new graduate',
            ],
            'preferred_seniority' => 'Senior',
            'remote_preference' => 'remote_us',
            'allowed_locations' => ['United States', 'US', 'USA', 'Remote'],
            'employment_type' => null,
        ],

        'data_analytics' => [
            'label' => 'Data & Analytics',
            'description' => 'Data-heavy product and platform roles: data engineering, analytics engineering, data and product analytics.',
            'include_terms' => [
                'data engineer', 'data engineering', 'data analyst',
                'data analytics', 'data analysis', 'data pipeline',
                'analytics engineer', 'analytics engineering',
                'product analytics', 'data platform',
            ],
            'exclude_terms' => [
                'intern', 'internship', 'entry level', 'entry-level',
                'junior', 'jr.', 'new grad', 'new graduate',
                'data entry',
            ],
            'preferred_seniority' => 'Senior',
            'remote_preference' => 'remote_us',
            'allowed_locations' => ['United States', 'US', 'USA', 'Remote'],
            'employment_type' => null,
        ],

        'marketing_technology' => [
            'label' => 'Marketing Technology',
            'description' => 'Marketing and growth ENGINEERING: building and operating marketing tooling, automations and growth platforms — technical roles, not campaign operations.',
            'include_terms' => [
                'marketing engineer', 'marketing technology', 'marketing tech',
                'martech', 'growth engineer', 'growth engineering',
                'marketing automation',
            ],
            'exclude_terms' => [
                'intern', 'internship', 'entry level', 'entry-level',
                'junior', 'jr.', 'new grad', 'new graduate',
                'marketing coordinator', 'marketing assistant',
                'content marketer', 'social media',
            ],
            'preferred_seniority' => 'Senior',
            'remote_preference' => 'remote_us',
            'allowed_locations' => ['United States', 'US', 'USA', 'Remote'],
            'employment_type' => null,
        ],
    ],

    /*
    | Eightfold-hosted public career sites — ONE provider
    | (App\Support\JobDiscovery\Providers\EightfoldDiscoveryProvider)
    | with per-employer tenant configuration. Eightfold's candidate-facing
    | careers interface exposes unauthenticated JSON at /api/pcsx/search and
    | /api/pcsx/position_details; each employer is a (host, domain) pair.
    | The endpoints are undocumented, and a tenant can switch them off
    | ("PCSX is not enabled"), so every tenant is verified, bounded and
    | isolated. robots.txt on every verified host explicitly allows /careers
    | and /api/pcsx; Eightfold's published terms were not reviewed.
    |
    |   - enabled:   master switch for the whole provider. Off = the
    |                orchestrator does not register it at all.
    |   - scheduled: whether the hourly `discovery:run --due` schedule may
    |                run it. OFF until a first manual run has been reviewed.
    |   - budget:    per-tenant request ceilings and pacing (a tenant entry
    |                may override any key in its own `budget`).
    |   - tenants:   slug => company/host/domain/enabled. `slug` scopes the
    |                source ID (`slug:positionId`). Add an employer by adding
    |                an entry; nothing else changes. `salary` is set ONLY
    |                where a tenant's pay field has known semantics.
    |
    | Microsoft is configured but disabled: it returned HTTP 429 after only a
    | handful of requests during the feasibility spike.
    */
    'eightfold' => [
        'enabled' => (bool) env('EIGHTFOLD_DISCOVERY_ENABLED', true),
        'scheduled' => (bool) env('EIGHTFOLD_DISCOVERY_SCHEDULED', false),

        'budget' => [
            // Minimum gap between requests to one tenant.
            'pause_ms' => 1500,
            // Search calls per tenant per run (queries x pages).
            'max_search_requests' => 12,
            'max_pages_per_query' => 2,
            // Uncached position_details calls per tenant per run.
            'max_detail_requests' => 20,
            // Total tries per request for 429 / 408 / 5xx / connection errors.
            'max_attempts' => 3,
            'backoff_seconds' => 2,
            // A Retry-After longer than this is not waited out: the tenant stops.
            'max_retry_after_seconds' => 60,
            'timeout_seconds' => 20,
            // Details are cached per tenant + position + posting version.
            'detail_cache_hours' => 168,
        ],

        'tenants' => [
            'lockheed' => [
                'company' => 'Lockheed Martin',
                'host' => 'lockheedmartin.eightfold.ai',
                'domain' => 'lockheedmartin.com',
                'enabled' => (bool) env('EIGHTFOLD_LOCKHEED_ENABLED', true),
                // `efcustomTextCustpayrange` is the posting's full-time annual
                // range for its single primary location. Normalized only for
                // single-location postings with a lone "$a - $b" range.
                'salary' => ['field' => 'efcustomTextCustpayrange', 'currency' => 'USD', 'interval' => 'year'],
            ],
            'nvidia' => [
                'company' => 'NVIDIA',
                'host' => 'nvidia.eightfold.ai',
                'domain' => 'nvidia.com',
                'enabled' => (bool) env('EIGHTFOLD_NVIDIA_ENABLED', true),
            ],
            'starbucks' => [
                'company' => 'Starbucks',
                'host' => 'starbucks.eightfold.ai',
                'domain' => 'starbucks.com',
                'enabled' => (bool) env('EIGHTFOLD_STARBUCKS_ENABLED', true),
            ],
            'microsoft' => [
                'company' => 'Microsoft',
                'host' => 'apply.careers.microsoft.com',
                'domain' => 'microsoft.com',
                'enabled' => (bool) env('EIGHTFOLD_MICROSOFT_ENABLED', false),
                // Observed 429s: if enabled, pace it more slowly.
                'budget' => ['pause_ms' => 4000, 'max_search_requests' => 6, 'max_detail_requests' => 10],
            ],
        ],
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
