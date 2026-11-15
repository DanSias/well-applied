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
`retry_after_hours` (6). Eightfold is registered but only scheduled when `job_discovery.eightfold.scheduled` is true
(default off).

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
