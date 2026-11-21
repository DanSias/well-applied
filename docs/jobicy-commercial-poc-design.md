# Jobicy Commercial API proof of concept, liveness and provider hygiene (design; nothing implemented, nothing called)

Status: **design and proposal.** No credit was bought, no key created, no authenticated or unauthenticated API call made, no production record changed, no migration written. Provider documentation was read once through a summarising
fetch tool; treat quoted details as approximate and re-read the pages before spending anything. Builds on `docs/application-destination-strategy.md` (branch `docs/api-first-destination-strategy`, not yet on master).

## 1. The Jobicy Commercial Jobs API, as documented
Same endpoint as the public API with an `Authorization: Bearer <key>` header. With a key, `url` is the **original ATS application URL when one exists**, otherwise the Jobicy listing URL. A funded wallet is required; **$0.01 per job that has a
direct URL, once per key** (repeats for that job and key are free); jobs without a direct URL cost nothing; each page reports `total_request_cost`, each job `job_request_cost`. Free tier: `url` is always the Jobicy listing; only listings
from the **last 7 days** are returned; cursor pagination (`count` 1 to 100, `nextCursor`); at most 1 request per second per IP; automated passes no more than once per hour.

## 2. A minimal, controlled sample

**What it must answer.** What share of Jobicy jobs return a genuine, usable destination, at what cost, and can the Jobicy listing URL still be kept?

**Cost.** The ceiling is `count x $0.01`. Recommended: **one request, `count=100`, `geo=usa`: at most $1.00** (a page where every job has a direct URL; fewer is cheaper; jobs without one are free). A second identical request is free for the same key. A sample of 100 estimates a share
to about plus or minus 10 percentage points (95% confidence), which is enough to decide whether the API is worth building on; a smaller one (`count=25`, at most $0.25) would only show that it works.

**Wallet and credentials (owner actions; I will not do them).**
- Create **one key dedicated to the PoC**, fund the wallet with the **smallest amount the dashboard allows (about $5)**, and confirm **auto top-up is off**, so the wallet balance is the hard spending ceiling. Revoke or rotate the key afterwards.
- The key lives only in the environment (`JOBICY_COMMERCIAL_API_KEY`), never in the repository, a log, a fixture, a report or a memory file. Config: `services.jobicy.commercial_key` (default null) and a separate flag `JOBICY_COMMERCIAL_ENABLED` (default false). The
  existing provider keeps using the public API untouched.
- In code, independent guards that do not rely on the wallet: a hard cap on `count` for the sample command, an aborting check if a response reports `total_request_cost` above a configured maximum, and a command that **refuses to run without `--confirm-spend`**.

**How it would run.** A one-off Artisan command (`jobicy:commercial-sample`), in the spirit of the existing `live-eval:*` commands: it writes a **sample report file** (and, if needed, a disposable SQLite), **never** `job_postings` or any production table, and creates no
Opportunity, Application or agent run. It fetches **the same window twice**: the free public page (for the canonical listing URL and ids) and the commercial page, and joins them by `id`.

**What it measures (per job and in aggregate, with the counts and a Wilson 95% interval, never a bare percentage).**
- *Direct-URL share:* jobs whose first-time `job_request_cost > 0`, and independently jobs whose commercial `url` differs from the public listing URL. The two must agree; a disagreement is reported.
- *Genuine destination* (all must hold): different from the Jobicy listing, `https`, host not an aggregator or a known tracker/redirector (the resolver's host typing and redirector signals, ported), and not the public `url`.
- *Destination type:* known ATS by platform (Greenhouse, Lever, Ashby, Workday, Workable, SmartRecruiters, Eightfold, ...), employer's own domain, other, invalid. Counts by platform and by company (so one company does not dominate).
- *Listing-URL recoverability:* the stored listing shape is `jobicy.com/jobs/<jobSlug>`, where `jobSlug` already begins with the id (verified against the free tier, 2026-11-22). The sample checks that **`https://jobicy.com/jobs/{jobSlug}` equals the public `url` for every job whose `jobSlug` starts with its own id**, because with a commercial key `url` no longer *is* the listing.
- *Cost:* total, per job, **per genuine destination**, and projected per 1,000 jobs.
- *Backfill reach:* the free tier only returns the last 7 days. How many of our **stored** Jobicy jobs are still in that window (almost certainly few) tells us whether this helps the existing 177 or only jobs discovered from now on.
- *Reachability is NOT measured:* nothing is fetched from any returned destination.

**A decision rule, agreed beforehand** (so the result is not argued after the fact): adopt only if the genuine-destination share is at least about half, the listing URL is recoverable for every job, and the projected cost is a few dollars per thousand jobs. Otherwise leave the free tier as is and rely on a person or the controlled browser fallback.

**Offline first.** Everything above except the single paid call can be built and tested with `Http::fake()` and fixtures shaped like the documented response: the client, the response parser, the listing-URL derivation, the destination classifier, the cost accounting, the spend guards, key redaction (the key
must never appear in a log, exception message or report; a test asserts it), the Wilson interval and the report. The live call is a separate approval.

## 3. Keeping the listing, the attribution and the commercial destination apart
- `source_url` stays **the Jobicy listing URL, exactly as the free API supplies it**. It is never overwritten by the commercial `url`.
- The commercial `url` is recorded as a **provider destination**, not as `source_url` and not as the application URL.
- Attribution stays attached to the job whatever the tier (credit Jobicy and link to the canonical listing).

Proposed **data contract, for agreement before any migration** (it fits the existing `discovery_metadata` JSON, so step one needs no schema change):

```
discovery_metadata.discovery.destinations[] = {
  url:        employer or ATS URL, query string removed
  source:     "jobicy" | "usajobs" | "eightfold" | ...
  tier:       "public" | "commercial"
  field:      "url" | "ApplyURI" | ...
  host_type:  known_ats | employer | other
  platform:   greenhouse | lever | ashby | ... | null
  retrieved_at
}
discovery_metadata.discovery.attribution = { provider, home_url, canonical_url, required: true }
```
`DiscoveredJobCandidate::applicationUrl` keeps carrying the listing (so ingestion's URL de-duplication is unchanged); destinations travel in `sourceMetadata`, which ingestion already persists. `ResolveInspectionStartUrl::providerDestination` would read `destinations` for any source (today USAJOBS only).
A destination is an **input**: it may seed an inspection's start URL but is not copied into `applications.application_url` until an inspection reaches a form there or a person confirms it. No resolved destination is ever `human_verified` by code.

## 4. Attribution display (implemented on this branch; see the commit)
The review found that **no provider credit was shown anywhere**, and that the link was labelled "Original posting" although for Jobicy it is Jobicy's own page and for Himalayas it is the API's mandatory `applicationLink`. Now: Jobicy shows "View on Jobicy" plus "Listing provided by Jobicy"; Himalayas shows
"Apply on Himalayas" plus "Job provided by Himalayas"; both credits link to the provider; every other source keeps "Original posting". The URL is passed through byte for byte (query string included), asserted in PHP and TypeScript tests. Adzuna is deliberately not credited because its terms are unverified.
The Himalayas Apply link is the stored `applicationLink` and nothing in the app replaces it; a resolved employer URL must never take its place.

## 5. Provider-supported liveness
**Finding:** nothing closes jobs. `JobPostingLifecycleStatus::Closed` exists and its docblock describes "closed after 2 consecutive rechecks", but no code sets it; all 412 stored jobs are `open`, `last_checked_at` is only set at ingestion, and Himalayas' `expiryDate`
is stored in metadata but never read (Himalayas' terms require removing expired jobs).

**Documented endpoints (no browser, no page access):**
| Provider | Check | Result |
|---|---|---|
| Himalayas | `GET /jobs/api/companies/:company/jobs/:slug` (company slug is stored; the job slug is the last segment of the stored `applicationLink`) | 200 open, **410 closed** (with `expiryDate`), 404 not found; cached up to 10 minutes |
| Jobicy | `GET /api/jobs/status?ids=...` (up to 100 ids; our `discovery_source_id`) | `active`, **`closed`**, `unknown`; free; also works beyond the 7-day window |
| Himalayas, already stored | `expiryDate` in `discovery_metadata` | a deadline, usable with no request at all |

**Proposed policy** (conservative, matching the docblock): an explicit **410** or **`closed`**, or a stored `expiryDate` in the past, is *confirmed closed evidence*; **404 and `unknown` are unconfirmed** (never close on them alone). Close a job only after **two consecutive confirmed-closed observations** (an expired `expiryDate` is one), never on one miss or a transport error. Respect the limits (Jobicy 1 request/second, batches of up to 100, no more than hourly passes; Himalayas the same free limits per its docs).
Store the observation and its basis; the existing `status` and `last_checked_at` columns suffice, so **no migration**, but writing them changes production job state, so it ships behind a flag and a dry-run mode first.
**Offline-testable:** the checkers, the closure policy and the dry-run report with `Http::fake`. A live check on a small sample is a separate approval.

## 6. Jobicy endpoint version discrepancy and `Retry-After`
**Endpoint.** The repository calls `https://jobicy.com/api/v2/remote-jobs` (`config/services.php`, overridable by `JOBICY_BASE_URL`). Jobicy's current documentation lists `GET https://jobicy.com/api/jobs` (apiVersion 2.2.x) with cursor pagination, a 7-day window and `count` up to 100.
Older third-party documentation cites the `v2/remote-jobs` path. The repository's own notes (`docs/job-discovery.md`) record that the feed "has returned 100 since early November, 200 before": consistent with a documentation and API change that the code has not followed.
**Unverified:** whether the old path still serves the same shape, and for how long. **Safe next step:** an offline contract test with a fixture of the documented new shape (so the provider tolerates either), then **one approved, unauthenticated GET to each path** to compare them. `JOBICY_BASE_URL` already allows switching without a code change. Note also that the new API's 7-day window and cursor pagination mean the current "no pagination" retrieval sees only the first 100 recent jobs.
**Himalayas has a similar drift:** the repository uses `offset 0 only`, `limit` 20; the docs now say `offset` is deprecated and `cursor` preferred.

**`Retry-After`.** The provider retries up to 3 times with a **fixed 1 second delay**, including on HTTP 429 (`->retry(3, 1000, ...)`), and ignores `Retry-After`. Jobicy asks clients to wait the `Retry-After` seconds on 429, to stay at or below 1 request per second, and to poll no more than hourly (discovery runs daily, so the cadence is fine).
**Proposed change (not made):** a retry callback that on 429 reads `Retry-After` (seconds or an HTTP date), waits that long up to a small cap (for example 60 s), and otherwise fails the attempt as rate-limited (recorded, not retried blindly); 5xx keeps the short fixed backoff. Testable offline with `Http::fake` and `Sleep::fake`, with no production change until approved.

## 7. Browser resolution stays a controlled fallback
For Jobicy, Himalayas and Adzuna the default path is **API data, not a browser**: the provider's link is the listing/Apply link, a commercial or provider destination (when available) is an input, and a person confirms the application URL. The browser walk (`resolveDestination`) runs for these sources
only on explicit authorization under the existing model (manifest hash, case ids, hosts, limits, `--supervised`), never automatically at discovery or inspection time. Himalayas pages are excluded entirely by its terms; Adzuna additionally needs the tracker permission.

## 8. Smallest safe next implementation milestone, and what is fully offline
**Milestone A (this branch, done, offline):** provider attribution display, with PHP and TypeScript tests.
**Milestone B (offline, no live call, no migration): the Jobicy destination contract and commercial sample tooling**
1. extend the provider output so `sourceMetadata` can carry `destinations` and `attribution` (and `ResolveInspectionStartUrl` reads them), tested with fixtures;
2. a `JobicyCommercialClient` and classifier, the sample command and its report, with `Http::fake` fixtures shaped like the documented response; no key needed;
3. Retry-After handling and a fixture for the documented new endpoint shape, behind the current defaults;
4. the liveness checkers and closure policy in dry-run, with `Http::fake`.
All of it is testable with no network, key or wallet.
**Approvals that cannot be skipped:** the Jobicy account, key and wallet (owner); **one authenticated sample call of at most about $1** (owner); one unauthenticated comparison of the two Jobicy endpoints; a small live liveness check; a migration only after the contract above is agreed; and the usual authorization for any browser resolution.
