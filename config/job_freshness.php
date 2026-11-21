<?php

/*
|--------------------------------------------------------------------------
| Provider-supported job freshness (B3)
|--------------------------------------------------------------------------
|
| OFF by default. When off, nothing about how a job is shown, listed or counted changes. When on, a job whose provider-stated expiry has passed (or whose closure
| the policy has confirmed) is marked non-actionable in the places that offer jobs for action. Nothing is ever deleted, and no pursued job or job with an Application
| is changed by this: it is only labelled. Writing a status is a separate, later gate (see docs/job-freshness.md); this milestone writes nothing.
*/

return [
    'enabled' => (bool) env('JOB_FRESHNESS_ENABLED', false),

    // How many consecutive, separate "closed" observations confirm a closure, and how far apart they must be (so one run can not confirm itself).
    'confirm_closed_count' => 2,
    'min_gap_hours' => 6,

    // How many consecutive, separate "active" observations reopen a job that was confirmed closed. Discovery data never reopens anything.
    'reopen_active_count' => 2,

    // Observations kept per job in discovery_metadata.liveness.
    'observation_cap' => 10,

    // Documented provider endpoints the later (separately approved) checker would use. NEVER called by this milestone; not yet verified against the live services.
    'jobicy_status_url' => env('JOB_FRESHNESS_JOBICY_STATUS_URL', 'https://jobicy.com/api/jobs/status'),

    // The provider freshness pilot (docs/job-freshness-pilot-plan.md). `live_enabled` is a LITERAL false on purpose: no environment variable turns it on; going live needs a separate
    // authorization and a code change. `approved_manifest_sha256` names the one approved sample (the SHA-256 of the manifest file); any change to the sample needs a new authorization.
    'pilot' => [
        'live_enabled' => false,
        'approved_manifest_sha256' => '00a9ecd46db41816e6acfc4f31e6c22e6cb6923d9dcbc1a1a04c442c4b6991c9',
    ],

    // Explicit marker for the isolated freshness UI demo (see app/Support/JobFreshness/Demo). Never set in a normal environment.
    'demo' => (bool) env('FRESHNESS_DEMO', false),
];
