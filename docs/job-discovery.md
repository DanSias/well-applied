# Job discovery

Discovery pulls candidate postings from external providers, filters them with a small deterministic gate, and ingests the
survivors as `JobPosting` rows in the Jobs inbox. It never scores fit, never qualifies, analyzes or shortlists, and never
calls a model. Qualification (`docs/job-qualification.md`) is a separate, manual step that runs on postings already in
the inbox. The Eightfold provider has its own notes in `docs/eightfold-discovery.md`.

## Scheduling depends on a running scheduler process

Discovery is **not** unattended. `bootstrap/app.php` registers `discovery:run --due` hourly, and `routes/console.php`
registers `discovery:prune-rejections` daily, but Laravel only fires scheduled commands while a scheduler process is
running (`php artisan dev` starts `schedule:work`; so does running `php artisan schedule:work` directly). If nothing is
running the scheduler, nothing is discovered: in the development database there is a 26-day gap with no runs at all.
`php artisan discovery:run` runs every provider once, on demand.

`--due` runs only providers that are individually due (`DiscoveryScheduleIsDue`): never attempted; last attempt succeeded
and is older than `job_discovery.schedule.stale_after_hours` (23); or last attempt did not succeed and is older than
`retry_after_hours` (6). Eightfold and USAJOBS are opt-in: scheduled only when their `scheduled` flag is on (default
off).

### Scheduling overrides

The Sources page can pause a provider's automatic scheduling or switch on scheduling for an opt-in provider. The choice
is stored in `discovery_provider_settings` (one row per provider, `schedule_override` = `default` | `paused` |
`enabled`; no row means `default`). `DiscoveryProviderInventory::effectiveSchedule()` is the single place that resolves
it, and `DiscoveryScheduleIsDue`, Overview and Sources all read it:

| Provider | Override | Effective schedule |
|---|---|---|
| not registered, or not configured (Adzuna without both credentials; Eightfold off or with no tenants; USAJOBS without key and user agent) | any | `unavailable` |
| configured | `paused` | `paused` |
| configured | `enabled` | `automatic` |
| configured | `default` / none | the code default: `automatic` (Himalayas, Adzuna, Jobicy) or `manual_only` (Eightfold and USAJOBS unless their `scheduled` flag is on) |

- An override never bypasses configuration: an unconfigured provider stays unavailable and the controller refuses to
  store an override for it.
- `discovery:run --due` (and `--due --only=`) considers only `automatic` providers, then applies the usual cadence, so a
  provider that failed before being paused is retried on the 6-hour retry cadence as soon as it is resumed.
- `discovery:run` and `discovery:run --only=x` without `--due` are manual runs and ignore overrides entirely.
- Scheduling is separate from health. A paused provider is never "overdue" for being paused, but its latest failure and
  history stay visible; nothing here changes stored attempts.
- Concurrency: a change affects only the next scheduler selection. Overrides are read when `discovery:run --due` builds
  its list, so a pause made while a run is in progress does not cancel it (that run was already selected), and a provider
  paused just before a tick may still run if that tick had already selected it. There are no execution locks yet; two
  overlapping runs of the same provider (for example a manual run during a scheduled one) remain possible.

## Execution safety

Every way of running a provider (the hourly `--due` tick, `discovery:run`, `discovery:run --only=`, a Run Now from the
Sources page) goes through `RunJobDiscovery`, which takes a **per-provider lease** before it creates an attempt:

- It is a cache lock (`discovery:execution:{provider}`, atomic on the database cache store this app uses), taken without
  waiting. If another execution holds it, that provider is **skipped**: no attempt is recorded, so it can never read as a
  success. Other providers run independently. `withoutOverlapping()` on the scheduled event is no longer the only guard.
- It is a renewable lease, not a long fixed timeout. It is renewed on every outgoing HTTP request (one global request
  middleware, so no provider's request code changed) and once per candidate, and the running attempt's `updated_at` is
  touched with it. `job_discovery.execution.lease_seconds` (default 900) therefore only has to exceed the longest silent
  gap: one request with its retries, at most about three minutes (Eightfold: 3 tries x 20s plus up to two waits of 60s;
  the public feeds 3 tries x 30s). A crashed process stops renewing, so its lease expires within 15 minutes.
- **Ownership is an invariant, not a hint.** The same checkpoint that renews the lease runs before every outgoing HTTP
  request, before every candidate, right after retrieval and once more before the outcome is written. If the renewal
  fails (the lease expired, or another execution took it) the execution throws `LeaseLostException` and stops: nothing
  further is processed, the attempt is recorded as failed with category `lease_lost` (never `provider_error`, even if a
  provider or adapter swallowed the exception: the loss is sticky), and the lease the other owner now holds is left alone.
  A request already in flight cannot be recalled, but its response is discarded because the next checkpoint stops the run
  before anything is ingested. An attempt that looks complete but lost its lease on the way is also `lease_lost`, because
  another execution may have overlapped it. Candidates committed before the loss stay committed and are counted.
- The outcome write is conditional (under a row lock) on the attempt still being `running`, so it cannot overwrite what
  recovery already recorded; the run is settled only while it is still open.
- Release is owner-checked: an execution never frees a lease another owner has taken over. A queued request uses the
  owner token `queued-attempt-{id}`, so its job's failure handler can free exactly its own lease.
- Every provider uses the `Http` facade (including each Eightfold search and detail request, and every retry, which is a
  separate request), so the one global request middleware sees all of them; `ExecutionTransportTest` compares the number
  of requests with the number of checkpoints through each real provider client.
- If every requested provider is busy, no run is recorded at all (an empty successful run would be a lie).

CLI results: busy providers are listed ("Skipped, already running elsewhere"). If nothing ran because everything was busy,
`discovery:run --due` exits 0 (a scheduler tick that finds the work already underway is fine) and any other invocation
exits 1. Otherwise exit codes are unchanged.

### Queued requests and scheduled runs

A valid pending Run Now request takes precedence over a scheduled run of the same provider. The provider's lease is the
mutex for both sides: `RequestProviderRun` holds it while it checks and records the queued attempt, and a scheduled
execution (`discovery:run --due`) checks for a valid pending request after it takes the lease and defers (releasing it,
recording nothing). So whichever arrives first wins and there is no check-then-claim gap: a request made while a scheduled
run holds the provider is refused (`already_active`); a tick that starts after the request was recorded defers to it.
`DiscoveryScheduleIsDue` also leaves such a provider out of the due list. A manual `discovery:run` is an explicit
instruction and is not deferred (it still respects the lease). A request is *valid* while it is `queued`, younger than
`queued_wait_seconds` and, on the database queue, its job is still in the queue (or is younger than the orphan grace);
failed, recovered, expired and orphaned requests block nothing. A `not_started` attempt is ignored by the cadence logic,
so it never delays the next scheduled run to the retry cadence.

### Queue, worker and timeout contract

| Value | Where | Why |
|---|---|---|
| `retry_after` 7200s | `config/queue.php` (`DB_QUEUE_RETRY_AFTER`) | the queue hands a job it has held this long to another worker |
| job `$timeout` 3600s | `RunDiscoveryProviderJob` | below `retry_after`, so a job the worker kills is never redelivered while still alive; overrides the worker's `--timeout` (the dev worker is `queue:listen --tries=1 --timeout=0`, so the property is what applies) |
| run budget 3000s | `execution.max_queued_run_seconds` | a queued run stops itself (`deadline_exceeded`) before the worker would kill it, so the outcome is truthful |
| lease 900s | `execution.lease_seconds` | renewed at every checkpoint; the longest silent gap is one request with its retries (about 3 minutes) |
| job `$tries` 20, release 30s, `$maxExceptions` 1 | `RunDiscoveryProviderJob` | a busy provider is waited for about ten minutes; the job property overrides the worker's `--tries=1` |

Longest realistic run: one request is bounded (30s timeouts, at most three tries; Eightfold 20s with waits of at most
60s), and the number of requests is bounded by each provider's own budgets, but Eightfold's theoretical worst case
(3 tenants x 32 requests x about three minutes) exceeds an hour, so the 3000s budget is a real bound, not a formality: a
queued run that reaches it stops, keeps what it ingested and can be run again. CLI and scheduled runs are not bounded by
the budget. If the worker is killed (SIGKILL), the lease expires and `discovery:recover-stale` records `interrupted`
after one lease; the reserved job is redelivered after `retry_after` and finds its attempt no longer queued, so it does
nothing. Use **one** queue worker with the SQLite database queue: two workers popping at once hit `database is locked`.
Any worker that runs these jobs must allow them 3600s (`--timeout` of at least that, or `--timeout=0` as the dev command
does, with the job property doing the bounding) and a `retry_after` above it.

### Run Now

`POST /sources/{provider}/run` (`RequestProviderRun`) refuses a provider that is not registered and configured, or that
already has a live queued or running attempt (a short cache lock makes two simultaneous requests resolve to one). It records
a `Queued` `DiscoveryRun` and `DiscoveryProviderAttempt` (`started_at` is the request time until a worker starts it) and
dispatches `RunDiscoveryProviderJob`, then returns; no provider request happens in the web request. The job calls
`RunJobDiscovery::executeQueued()`, which takes the lease, then claims the attempt with one conditional UPDATE
(`queued` -> `running`), then runs the normal pipeline on that same row. So:

- delivered twice, or already recovered: the attempt is no longer queued and the job does nothing;
- provider busy: the job releases itself for 30s, up to 20 times (about ten minutes), then records the request as `not_started`;
- the queue refuses the dispatch: the request rows are removed, nothing stays "queued";
- worker killed: the job's failure handler records `interrupted` and frees only its own lease.

Run Now works for paused and manual-only providers (it is a manual run). It needs a running queue worker (`php artisan dev`
starts one); a request nobody picks up is shown as Queued, "waiting for a queue worker".

### Recovery

`discovery:recover-stale` (every five minutes) records a `running` attempt silent for a whole lease, and whose lease is not
held, as failed / `interrupted` (this also catches attempts left running by older CLI or scheduled runs), and a `queued` one
as failed / `not_started` in two cases. (1) It has waited longer than `queued_wait_seconds` (default 3600): one worker runs
jobs one at a time and another job may hold it for up to 3000s, so a healthy queue can make a request wait that long;
the scheduler defers to a request only while it is valid, so this is also the longest a dead worker delays a scheduled run.
(2) On the database queue, its job is no longer in the queue (cleared or lost) and it is older than
`queued_orphan_grace_seconds` (300s): it can never start, so it does not wait out the hour. A request whose worker is merely
slow to start is never marked while its job is still queued. Each update is conditional on the attempt still
being queued or running, so it is idempotent, never overwrites a real outcome and never touches a job posting; counters an
interrupted run had already written stay. The parent run is then settled from its attempts (an empty open run, silent for a
lease, becomes failed). Retrying is just another Run Now or the next scheduled retry.

### Scheduler heartbeat

`routes/console.php` runs `SchedulerHeartbeat::record()` every minute. The Sources summary reports `recent` (seen within
three minutes), `stale` or `unknown` (never seen) from that timestamp alone, and warns only when a provider is meant to be
automatic. It says nothing about queue workers or providers.

## Pipeline stages

| # | Stage | Where | Output |
|---|---|---|---|
| 1 | Retrieval | each provider's `retrieve()` | raw feed entries |
| 2 | Normalization | provider `normalize()` / `EightfoldPositionMapper::toCandidate()` | `DiscoveredJobCandidate`; entries missing id, title or company are dropped (counted, see Counters) |
| 3 | Provider prefilter | Eightfold only: `EightfoldPositionMapper::dropReason()` | drops rows before any description fetch (counted by reason) |
| 4 | Global filter | `FilterDiscoveredCandidates::evaluate()` | `FilterDecision`: accepted, or one primary rejection reason |
| 5 | Rejection log | `DiscoveryRejectionLog` | a `discovery_rejections` row per rejected candidate (never a `JobPosting`) |
| 6 | Canonical match | `RunJobDiscovery::resolveCanonicalMatch()` | optional ATS enrichment (only for companies in `known_ats_boards`) |
| 7 | Deduplication and ingestion | `IngestDiscoveredCandidate::ingest()` | `JobPosting` created or updated |

Deduplication is not filtering. A candidate that passes the filter and matches an existing job (same source identity,
same canonical identity, or same normalized application URL) updates that job and counts as **updated**, not rejected.
Identity conflicts are reported on the attempt and skipped. A rejected candidate never creates or updates a `JobPosting`.

## Provider retrieval limits

Retrieval limits are separate from filtering: a posting a provider never returns cannot be filtered or logged.

| Provider | Query | Cap | Notes |
|---|---|---|---|
| Himalayas | none (the feed has no keyword search) | 20 per call, offset 0 only | `limit=200` is silently capped by the feed |
| Adzuna | `what=software engineer` | one page of 50 | no location, remote or recency parameter; results are 500-character previews |
| Jobicy | `geo=usa` | 100 per call (the feed has returned 100 since early November, 200 before) | no pagination, no industry/tag filter |
| Eightfold | search profile include terms, `location=Remote` hint | per-tenant request budgets | `search_profiles` other than `software_engineering` are unused by any provider |

Provider pagination and widening queries are out of scope for the current milestone.

## Global filter: rule precedence

`FilterDiscoveredCandidates::evaluate()` applies rules in this order; the first that matches is the one primary reason:

1. **`excluded_title_role`**: the title contains a `search.excluded_title_keywords` term (`site reliability`, `sre`,
   `devops`, `firmware`, `compiler`).
2. **`excluded_seniority`**: the title contains a `search.excluded_keywords` term (`intern`, `internship`, `junior`, `jr.`,
   `entry level`, `entry-level`, `new grad`, `new graduate`). **Title only**: a senior posting whose description mentions
   junior engineers or interns is accepted.
3. **`title_not_matching`**: the title matches neither a `search.keywords` phrase nor a title family (below).
4. **`work_arrangement_not_allowed`** and **`location_not_allowed`**: the remote/location policy (below).
5. **`employment_type_not_allowed`**: only when both the criteria and the candidate name an employment type (the criteria
   default to none).

Matching is whole-word and case-insensitive. Seniority words (Senior, Staff, Principal, Lead) are never exclusions:
technical seniority and leadership are not evidence of people management, and qualification handles management roles.

### Title families

A title is accepted when it names a specialization directly followed by `engineer` or `developer`, with spaces and hyphens
interchangeable (`Full Stack`, `Full-Stack`, `Fullstack`): `config/job_discovery.php` → `search.title_families`.

- Specializations: software, software development, web, application, full stack, back end (`backend`, `back-end`), front end,
  product, integration(s), api(s). "Developer, Integrations" and "Engineer, APIs" (role first, comma) are also read.
- Stacks: php, laravel, react (`react.js`, `reactjs`), typescript, javascript, node (`node.js`, `nodejs`).
- The older `search.keywords` phrases (`software engineer`, `platform engineer`, `data engineer`, …) still apply.
- Explicit developer-facing phrases, matched whole: Developer Support Engineer, API Support Engineer, Technical Support Engineer,
  Solutions Engineer. There is no generic `support` or bare `engineer` match, so "Support Engineer" and "Customer Engineer"
  are still rejected.
- A bare "Engineer", "Staff Engineer" or "Engineering Manager" is **not** accepted; neither is "Developer Advocate" or
  "Production Engineer". Which stack a role really needs, and whether it is product work, is qualification's job.

## Remote and location policy

Discovery is remote-first (`search.remote_preference = remote_us`). Only explicit contradictions reject:

| Posting says | Result |
|---|---|
| Remote, US or no/unclear location | accepted |
| Remote, location names only non-US places (`Germany`, `EMEA, LATAM`) | rejected, `location_not_allowed` |
| Himalayas `locationRestrictions` with no US/worldwide entry | rejected, `location_not_allowed` |
| Remote status unknown | accepted and **left unknown**; a bare `US`, `USA` or a state name is never read as remote |
| Remote status unknown, location names only non-US places | rejected, `location_not_allowed` |
| Hybrid, location mentions Florida | accepted |
| Hybrid, no location | accepted (unknown location) |
| Hybrid, any other location | rejected, `work_arrangement_not_allowed` |
| On-site (Florida included, for now) | rejected, `work_arrangement_not_allowed` |

"Florida" means the word `Florida` or a `FL` state token (`Orlando, FL`, `FL,US`, and a ZIP-coded `Tampa FL 33602`); no other state is read as Florida. "New Mexico" is a US
state, not the country Mexico. A hybrid role listing only `United States` has no place to be hybrid in, and is rejected like
any other non-Florida hybrid. The
patterns live in `LocationSignals`, shared with qualification's eligibility check (`AssessEligibility`) so the two agree.
Eightfold's prefilter applies the same hybrid rule (`work_arrangement` keeps hybrid positions listing `FL`), and
Eightfold candidates carry complete Florida and US signals from every listed location in `sourceMetadata`
(`floridaListed`, `usLocationListed`), so the display text's five-location cut does not affect the global filter. Qualification, not discovery, decides
eligibility from the posting text once it has enough information.

Adzuna keeps being ingested. Its postings are 500-character previews with no remote status; they stay marked
`description_completeness = preview` (the Jobs list shows a preview badge) and qualification will report
`insufficient_information` for them. No qualification is queued automatically and no description is fabricated.

## USAJOBS (federal)

`UsajobsDiscoveryProvider` searches `https://data.usajobs.gov/api/Search` with the key (`USAJOBS_API_KEY`) and the registered
email as User-Agent (`USAJOBS_USER_AGENT`), both mapped in `config/services.php` and never logged. It is registered only
when both are set, so an install without them behaves as before. A direct `retrieve()` without them throws and makes no
request.

- **Search:** every request is `JobCategoryCode=2210` (configurable, deliberately not widened to 1550/0854, which returned mostly
  unrelated research and engineering roles), `HiringPath=public`, newest first, `Fields=Full`. Two bounded branches run in
  order: **remote** (`RemoteIndicator=True`) and, for each hybrid region, **region** (`LocationName=<region>`, e.g. Florida).
  `max_pages` is the request ceiling for the whole run, shared by all branches (every branch still gets its first page), and
  `maxResults` caps the combined result. An announcement returned by both branches is one candidate.
- **Regions come from the search policy, not the provider:** `search.hybrid_regions` (default `['Florida']`, the same hybrid
  region the commercial policy uses). With no profile in the context every configured region is searched. With a profile,
  only regions it names in its allowed locations are, so the built-in `remote_us` profiles stay remote-only and a regional
  profile opts in by naming the region. Profile `any` is one unfiltered branch. There is no commute filtering: a posting that
  lists Florida anywhere is kept with its agency location data, and qualification or review judges distance.
- **Identity:** the announcement number (`PositionID`); the control number (`MatchedObjectId`) only as a fallback, and kept in
  metadata. The same announcement twice (across pages or re-listed) is one candidate.
- **Remote vs telework:** a posting is Remote only when the announcement's own `RemoteIndicator` (or its "(remote job)"
  location) says so. Telework eligibility is stored as `teleworkEligible` and never turned into a remote status; a
  telework-eligible posting keeps an unknown remote status. Because "unknown" normally passes the filter, USAJOBS is the one
  exception: a non-remote posting is accepted only when it lists a hybrid region (`hybridRegionsListed`, computed over every
  listed location) AND is telework eligible. Listing the region but not telework eligible is rejected as `onsite`; telework not
  stated as `telework_not_stated`; no region as `not_remote`. All are `work_arrangement_not_allowed`, with the evidence.
- **Federal titles:** commercial title rules apply, plus federal-only patterns (`usajobs.title_patterns`) for the
  application-software specialty: `IT Specialist` / `Information Technology Specialist` with `APPSW` anywhere in the
  parenthetical (`(APPSW)`, `(APPSW/INFOSEC)`) or `Application(s) Software`. A bare "IT Specialist" and INFOSEC-only, SYSADMIN,
  CUSTSPT, data-management and similar specialties are `title_not_matching`; supervisory titles are rejected first
  (`usajobs.excluded_title_patterns`). A mixed specialty such as `(APPSW/SYSADMIN)` is accepted because it names APPSW. Intern, junior, DevOps, SRE, firmware and compiler
  exclusions still apply, and the patterns never affect other providers.
- **Preserved fields:** grade range and pay plan, pay ranges (min/max across grades only when they share an interval, raw ranges
  kept), schedule and offering type, open and closing dates, "who may apply" and hiring-path data **as given**, security
  clearance, travel, relocation, locations and apply links. Nothing says the user is eligible: eligibility belongs to the
  announcement's own text and to qualification.
- **Early-career programs:** a `graduates` or `student` hiring path does not by itself exclude a posting; only the existing
  title exclusions (intern, junior, entry level, new grad) do. Raw hiring paths are kept for qualification, and a public
  hiring path does not mean the user is eligible.
- **Scheduling:** off by default (`USAJOBS_DISCOVERY_SCHEDULED=false`). `php artisan discovery:run --only=usajobs` runs it once.
- **Counters:** `retrieval_diagnostics` records `raw_entries`, `normalization_dropped`, `pages_fetched` and
  `duplicate_announcements` and `search_branches`.

## Rejection reason codes

Stable strings, stored in `discovery_rejections.reason_code` and `discovery_provider_attempts.rejection_counts`
(`App\Enums\DiscoveryRejectionReason`): `excluded_title_role`, `excluded_seniority`, `title_not_matching`,
`work_arrangement_not_allowed`, `location_not_allowed`, `employment_type_not_allowed`.

Each decision also carries `matched_term` (≤100 chars), `matched_field` (`title`, `remote_status`, `location`,
`location_restrictions`, `employment_type`) and a bounded `evidence` string (≤240 chars: the title, or the location text).
No description text is stored or logged anywhere in this path.

## Rejection persistence and lifecycle

`discovery_rejections` holds one row per provider identity, unique on `(source, source_job_id)` (every provider requires a
source id; an empty one is simply not recorded). Stored: title, company, location, remote status, source URL (only if
≤1000 chars), reason, matched term/field, evidence, `first_seen_at`, `last_seen_at`, `seen_count` and
`last_discovery_run_id`.

- **Seen again and rejected again**: one row; `last_seen_at`, `seen_count`, current reason and fields update;
  `first_seen_at` never changes.
- **Rejected for a different reason later** (a rule or the posting changed): the row's reason updates.
- **Accepted on a later run**: the row is kept as history with `resolved_at` set and is no longer a current rejection (not
  listed, not counted on Overview). If it is rejected again, `resolved_at` clears.
- A write failure is logged (structure only) and never fails the provider attempt.

## Retention

`php artisan discovery:prune-rejections` deletes rows whose `last_seen_at` is more than 30 days old
(`job_discovery.rejections.retention_days`, env `JOB_DISCOVERY_REJECTION_RETENTION_DAYS`; `--days=N` overrides), resolved rows
included. A row exactly 30 days old is kept. It touches only `discovery_rejections`, is safe to repeat, and is scheduled
daily (`routes/console.php`), subject to the same scheduler-process caveat.

## Counters

Per provider attempt (`discovery_provider_attempts`). Existing counters keep their original meaning.

| Counter | Measures | Comparable across providers? |
|---|---|---|
| `candidates_retrieved` | candidates that reached the global filter (after normalization; for Eightfold, also after its prefilter and detail fetch) | **No**: Eightfold's is post-prefilter |
| `candidates_accepted` | passed the global filter, including ones that then updated an existing job | yes |
| `jobs_created` / `jobs_updated` | ingestion outcome of accepted candidates | yes |
| `jobs_canonicalized` | accepted candidates matched to a known ATS board | yes |
| `rejection_counts` (JSON) | global-filter rejections by reason; sums to `candidates_retrieved − candidates_accepted` | yes (same stage everywhere) |
| `retrieval_diagnostics` (JSON) | what the provider saw before the filter: `raw_entries`, `normalization_dropped`; Eightfold: `search_rows`, `unique_positions`, `prefilter_dropped` (reason → count) | `raw_entries`/`normalization_dropped` for single-feed providers only |

`rejection_counts` and `retrieval_diagnostics` are **null** when not recorded: attempts from before they existed, or an
attempt whose retrieval failed before the filter stage was reached. Null is never zero. `[]` means the stage ran and nothing
was rejected. No counter is inferred from another. A zero-result attempt is `status = succeeded` with
`candidates_retrieved = 0`; a failure has `status = failed` and a `failure_category`.

## Overview discovery health

Overview shows, per provider, its **last successful attempt** (a Succeeded or Partial attempt; Partial is labelled, with its summary; not a sum of history): age, retrieved, accepted, created,
updated and filtered counts (with the reasons), plus a failure message when a later attempt failed. A scheduled provider is
**overdue** when its last success is older than twice `stale_after_hours` (46 hours) or missing; manual-only providers
(Eightfold unless scheduled) are listed but never flagged. The headline says discovery "has not run recently" or "has not run
yet" and never says discovery is running. "Filtered candidates (N)" opens the rejection list.

## Inspecting rejected candidates

Overview → **Filtered candidates**, or `/discovery/rejections`: current (unresolved) rejections, newest sighting first, with
title, company, provider, reason, matched term and evidence, location, last seen, times seen and a link to the source
posting. Search by title or company; filter by reason; 25 per page, server-side. The list is read-only: there is no promote
or override action, rejections are never Jobs, and they are not part of any Jobs count.
