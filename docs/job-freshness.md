# Provider-supported job freshness and expiry (B3)

**Status: implemented offline and OFF by default.** No provider has been called, no job changed, no migration run, no schedule added. With `JOB_FRESHNESS_ENABLED` unset, every page, list and count behaves exactly as before.

## 1. Audit: how a job is "open" today
- **Lifecycle status:** `job_postings.status` (`open` | `closed`, `JobPostingLifecycleStatus`). Its docblock describes closing "after 2 consecutive rechecks", but **nothing ever sets `closed`**. All 412 stored jobs are `open`.
- **Freshness timestamps:** `posted_at` (provider), `discovered_at`, `last_checked_at` (set only when discovery ingests the job, so it is *not* a liveness check), `source_updated_at` (null for every job). Himalayas' `expiryDate` is stored in `discovery_metadata.discovery.expiryDate` and was never read. USAJOBS stores a `closingDate`.
- **Ingestion never touches `status`** (`IngestDiscoveredCandidate` writes provenance, content and metadata only), so rediscovery can neither close nor reopen a job. It does replace `discovery_metadata.discovery` wholesale, which is why liveness lives in a sibling key.
- **Surfaces that offer a job for action, and what they did with status:**

| Surface | Before B3 |
|---|---|
| Jobs Inbox and Shortlist (`JobPostingController::index`) | no status or expiry filter: an expired job stays in the list |
| Home overview and "continue" work (`PresentHome`) | excluded `status = closed` only (which never happens) |
| Opportunities list and workspace | listed every pursued job; a "Posting closed" badge only for `status = closed` |
| Job drawer, inspection drawer, analysis, match, qualification, resume and application preparation | not gated by status at all |

## 2. What B3 adds (all behind `job_freshness.enabled`, default false)
- **Provider-specific, pure interpreters** (`app/Support/JobFreshness/`), no request, no clock except the `now` they are given:
  - `HimalayasLivenessInterpreter`: a stored or reported `expiryDate` at or before now = **expired** (known; wins over a conflicting 200); **410** = closed (explicit); **200** = active; **404** = uncertain; 429, 5xx, unexpected status, timeout = uncertain.
  - `JobicyStatusInterpreter`: `closed` = closed; `active` = active; `unknown`, an id missing from the answer, an unrecognised shape, 429/503/5xx/400/404 or a timeout = uncertain. (The status endpoint's body is **not documented**; the parser accepts two plausible shapes and treats the rest as uncertain. See section 6.)
- **`Observation`**: provider, source (`expiry_date` | `job_endpoint` | `status_endpoint`), result (`active` | `expired` | `closed` | `uncertain`), a short `basis` code, `observed_at`, optional `expires_at`, `http_status`, `retry_after_seconds`. It carries **no URL, body, header or credential**.
- **`LivenessPolicy`** (pure; `confirm_closed_count` 2, `min_gap_hours` 6, `reopen_active_count` 2):
  1. **Known expiry** makes a job non-actionable **immediately**, from one observation and no request; it wins over a later "open" answer (the conflict is recorded).
  2. An **explicit closure is evidence, not proof**: two separate decisive closed observations at least 6 hours apart confirm it; until then the job is "May be closed" and still actionable.
  3. **404, `unknown`, timeouts, 429 and other transient errors are never closure**, however many; they neither extend nor reset a streak. An **active** answer resets a closure streak.
  4. A **closed job reopens only on two separate "open" answers from the provider's endpoints**. Rediscovered feed data is not an observation and never reopens anything; a known expiry is never reopened here.
  5. **Protected jobs** (pursued, or with an Application) are only labelled: a would-be status change becomes a `review` item.
- **`JobFreshness`** (read model): derives the decision from what is stored (the provider's `expiryDate`, `status`, recorded observations). With the feature off it returns null everywhere.
- **`LivenessRecord`**: builds `discovery_metadata.liveness` = `{schema, observations (≤ 10, de-duplicated, chronological), decision}`, a sibling of `discovery` and `canonical`, so a rediscovery cannot erase it. **Nothing in B3 stores it.**
- **`CheckRequests`**: the checks a later runner would make, as specifications only: Himalayas `GET {base}/companies/{companySlug}/jobs/{slug}` (slug read from the stored applicationLink, which is never changed), Jobicy `GET {status url}?ids=…` in batches of 100.
- **Surfaces (when on):** a freshness badge on the Opportunities list and workspace and the job drawer (Expired, Closed, May be closed); the Jobs **Inbox and Shortlist** leave expired and closed jobs out, say how many ("N expired or closed jobs left out · Show them"), and `?inactive=1` shows them; **Home** drops expired jobs from open work. A job with an Application is **never hidden**, a pursued job **stays in Opportunities** (labelled), other Jobs views are untouched, and **nothing is deleted or changed**.
- **Dry run:** `php artisan jobs:freshness-report [--json] [--observations=file.json]` is read-only: it reads jobs, makes **no request** and writes nothing, and prints how many jobs would be active, expired, closed, uncertain or unchecked per source, the evidence, the intended action (`none`, `set_closed`, `reopen`, `review`), how many are protected or have conflicting observations, and how many provider requests a real check would need. `--observations` replays recorded observations from a local file so the policy can be reviewed before any check exists.

Provider restrictions are preserved: the Himalayas `applicationLink` (the mandatory Apply target), its attribution and its terms are untouched; nothing replaces or re-labels either, and no observation or specification carries one. Himalayas is checked through its documented single-job endpoint, not its pages; Jobicy through its documented free status endpoint. The existing discovery providers and ingestion are unchanged.

## 3. Staged activation plan
| Stage | What | Gate |
|---|---|---|
| 0 (done) | Offline code, flag off, dry-run command, tests | none |
| 1 | **Read-only dry run on the real data**: `jobs:freshness-report` (no request, no write). Review the expired and protected counts and the examples | none: read-only. Run once for this milestone against the local database: see below |
| 2 | **Flag on in presentation only** (`JOB_FRESHNESS_ENABLED=true`): known-expiry labels and the Inbox/Shortlist/Home treatment. Still no write | owner decision; reversible by unsetting the flag |
| 3 | **A live, read-only observation run** (separately approved): Himalayas single-job checks for jobs without a known expiry, and Jobicy status batches, under the providers' limits and the existing authorization pattern; output to a file (observations), no job change. First the verification in section 6 | separate approval naming hosts, request counts, limits |
| 4 | **Replay the recorded observations through the dry run**; review what the policy would close, reopen and flag | none |
| 5 | **Production write gate** (separately approved): a command applies only `set_closed` for non-protected jobs and writes `discovery_metadata.liveness`, with `--dry-run` by default, a per-run cap, and an audit line per change. `reopen` and `review` stay manual. Pursued jobs and jobs with Applications are never written | separate approval; backup first |
| 6 | Scheduled observation runs (hourly at most for Jobicy), still no write without the gate in stage 5 | separate approval |

Rollback at any stage: unset the flag (presentation); the stored liveness record is additive JSON and can be removed without touching anything else.

**Result of that one dry run (2026-11-22, local database, no request made, no job changed):** 412 jobs. No Himalayas job has a passed `expiryDate` (the 14 Himalayas jobs are all unchecked, none expired), so **nothing would be classified expired, closed or uncertain**, and every intended action is `none`. 10 jobs are protected (pursued or with an Application). A real check of the jobs that the providers can check would need 14 Himalayas requests and 2 Jobicy batch requests (177 jobs). Adzuna (205), Eightfold (9) and manual (7) jobs have no provider-supported check. (The database file's bytes change continuously from other processes; `job_postings` itself was not modified: still 412 rows, latest `updated_at` unchanged.)

## 4. Known gaps and risks
- Nothing has been run against a live provider; the Himalayas single-job endpoint's 404 meaning and the Jobicy status body are unverified.
- USAJOBS' `closingDate` is a known expiry too but is not wired in (the milestone covers Himalayas and Jobicy); Adzuna and Eightfold have no provider check.
- Himalayas' cache (10 minutes) can lag a closure; a known expiry date is therefore preferred over an endpoint answer.
- Filtering the Inbox by JSON path assumes `expiryDate` is an integer in seconds. Gate A (docs/job-freshness-pilot-plan.md section 10) normalises it at ingestion and makes the badge read it the same way; legacy non-integer values fail open (shown, never wrongly hidden).

## 4b. Presentation follow-up (flag on; still default off)
- **Three kinds of "open" are never confused.** The view carries a `tone`: `ok` = **Confirmed open** (the provider's own endpoint said so; the note gives the day); `neutral` = **Not live-checked** (open only as far as our records go) or **Check inconclusive** (the last check gave no clear answer: explicitly not a sign of closure); `warn` = **Expired**, **Closed**, **May be closed** (a closure reported once, not yet confirmed). Neutral states are never a badge on a list; they appear only as a muted line in a drawer or workspace. Warning styling is used for nothing else.
- **"N expired or closed jobs left out" counts the same view the list shows**: disposition, source, search, and the inspection, triage and resume filters. "Show them" adds exactly that many (tested across filter combinations).
- **Protected jobs stay reachable.** Pursued jobs and jobs with an Application are never filtered from Opportunities, Home's open work, the Jobs "all" view or the Inbox (an Application-bearing job); every job opens in its drawer and workspace regardless of freshness, with at most a label.
- **Display only.** Viewing any freshness surface makes no provider request, no write, no inspection and no resume generation (tested); the "Show them" control is a GET navigation.
- Flag off: payloads, lists, counts and Home are exactly as before (tested).

## 5. Offline-testable, and what is not
Everything above is covered by deterministic fixtures: expiry boundaries, conflicting observations, repeat checks, reopen rules, every error class, provider restrictions, protected jobs, status presentation, the dry run (read-only, no request), and default-off behaviour. Not testable offline: how the live providers actually answer.

**Status-response structure (verified 2026-11-22):** `{apiVersion, checkedAt, count, jobs:[{id, status}], statusCode, success}`; only `active` was observed.

## 6. The Jobicy `/api/jobs` verification (EXECUTED 2026-11-22: three key-less requests)
The three public, key-less `GET` requests that were run, one second apart, from a person's machine or with explicit approval, no cursor, no key, nothing stored:

1. `GET https://jobicy.com/api/jobs?count=3&geo=usa` and `GET https://jobicy.com/api/v2/remote-jobs?count=3&geo=usa` (the endpoint the discovery provider uses today).
   **Result:** both paths answered 200 with the same envelope and apiVersion (the old path is an alias); `url` is a Jobicy listing equal to `https://jobicy.com/jobs/{jobSlug}`; no cost fields. **Established:** that both paths answer; the HTTP status; whether the response envelope has `success`, `statusCode`, `jobs`, `jobCount`, `nextCursor`, `hasMore`, `apiVersion`; whether each job has `id`, `url`, `jobSlug`, `jobTitle`, `companyName`; whether `url` is a Jobicy listing and equals `https://jobicy.com/jobs/{jobSlug}` (the slug already starts with the id); whether the old path is an alias with the same shape; whether `job_request_cost` or `total_request_cost` are absent (they must be, with no key).
2. `GET https://jobicy.com/api/jobs/status?ids={the three ids from step 1}` (free, documented).
   **Establishes:** the status endpoint's actual response body (the shape B3 only assumes), and what it says for a live id.

Total: three requests, three jobs, no key, no cost. Record only the status codes, the key names present and the three comparisons above (not the descriptions). It settles the B2 assumptions about the path, envelope, `jobSlug` and listing reconstruction, and B3's status-body assumption, without any authenticated or paid call. It does **not** verify the Commercial tier's behaviour, a bad key, or an empty wallet.
