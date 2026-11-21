# Provider freshness pilot: plan (design only; nothing executed, no request made, flag off)

Status: **proposal for authorization.** Prepared read-only against the local database (counts and local ids only; no URL, title or company is recorded here). The pilot is **not built**: `app/Support/JobFreshness/CheckRequests` only *specifies* requests, and no code in `app/Support/JobFreshness` or `JobsFreshnessReport` writes to the database, sends a request or is scheduled (checked by search).

## 1. The sample (named, small, frozen before any request)
Himalayas has only 14 stored jobs, all with a **future** `expiryDate` (Nov 29 2026 to Jan 19 2027); none is pursued or has an Application. Jobicy has 189 stored jobs (56 posted in the last 7 days, 76 in 8-30 days, 57 older), 6 of them protected (3 pursued, 6 with Applications, some overlap).

| Provider | Local job ids | Why |
|---|---|---|
| Himalayas (8 requests) | 3, 4, 5, 27 (oldest, posted Sep 30, expiry Nov 29); 57, 85 (Oct, expiry early Dec); 207, 391 (recent, expiry Jan) | oldest, middle and newest, all with expiry metadata. None are protected |
| Jobicy (1 request, 23 ids) | recent (7 days): 254, 255, 256, 257, 298, 299, 300; 8-30 days: 103, 105, 106, 107, 108, 109, 110; older than 30 days: 29, 30, 31, 32, 33, 34, 35; old protected (read only): 82, 97 | known-active records, older records the free status endpoint is documented to cover beyond the 7-day window, and two old jobs that have an Application, to see how a protected job would read |

The runner would resolve each local id to the provider key (Himalayas: companySlug and the job slug from the stored `applicationLink`; Jobicy: `discovery_source_id`) **inside the process** and write only local ids to the evidence file. The list is frozen as a manifest with a SHA-256 hash that the authorization names; any other id is refused.

## 2. Requests, limits and pacing
- **Total: 9 requests, hard cap 10**, hosts `himalayas.app` (8 GET) and `jobicy.com` (1 GET). No other host, no redirects followed, no cursor, no pagination, no retries, no second pass, no scheduling.
- Documented endpoints only: Himalayas `GET {HIMALAYAS_BASE_URL}/companies/{companySlug}/jobs/{slug}` (the existing config `https://himalayas.app/jobs/api`); Jobicy `GET https://jobicy.com/api/jobs/status?ids=...` (verified 2026-11-22 to answer `{apiVersion, checkedAt, count, jobs:[{id,status}], statusCode, success}`). No browser, no listing or application page, no credentials, no `applicationLink` is ever fetched.
- Pacing: at least **2 seconds** between any two requests (the Jobicy limit is 1 per second per IP; Himalayas' limit on this endpoint should be re-read before authorization). Jobicy is sent last, once.
- Timeout 10 s per request.

## 3. Stop conditions (the run ends at once, with the evidence so far)
Any 403; any 429 (`Retry-After` is recorded and **respected by stopping**, never by waiting and retrying); any 5xx; any redirect; any content type other than JSON; a body over 256 KB; two 404s in a row on Himalayas (the endpoint is then unsuitable for this slug form); a request count that would exceed 10; a host not in the manifest.

## 4. Evidence captured (a new local file, written once; no database write)
Per request: sequence number, host, local job id(s), HTTP status, elapsed ms, response headers `Content-Type`, `Retry-After`, `Cache-Control`, `Age`, and the **key names** of the body; plus only the fields B3 reads: Himalayas `expiryDate` (and whether it is a number or a string) and the status code; Jobicy `jobs[].status` per id and `checkedAt`. **Never** stored: URLs, slugs, titles, descriptions, company names, bodies, cookies. The `Observation` objects (already URL- and body-free) are written to a JSON file that `php artisan jobs:freshness-report --observations=file.json` can replay offline, so the policy output is reviewed with no further request.

## 5. How responses are interpreted (existing, tested interpreters; unchanged)
| Response | Reading |
|---|---|
| Himalayas 200, no passed expiry | **active** (confirmed by the provider's endpoint) |
| Himalayas `expiryDate` at or before now (stored or reported) | **expired**, known; wins over a 200 |
| Himalayas 410 | **closed** (one observation: "May be closed", not final) |
| Himalayas 404, 429, 5xx, timeout, unexpected status | **uncertain**, never closure |
| Jobicy `active` | active; `closed` | closed (needs a second observation at least 6 hours later to confirm); `unknown`, id absent, unrecognised shape, errors | uncertain |
| anything unrecognised | uncertain, with the basis recorded |
Nothing is closed, reopened or hidden by the pilot: it only produces observations, which are replayed through the dry run.

## 6. It cannot alter pursued Opportunities or Applications
- The runner is read-only by construction: it opens no write path (no `update`, `save`, `create`, `delete`; the existing freshness code has none, by search), makes no status change, runs no migration, and has no scheduler entry.
- None of the 8 Himalayas jobs is protected. Jobs 82 and 97 (Jobicy, with an Application) are *queried* only; a would-be closure of a protected job becomes a `review` item in the dry run and is never applied.
- It must ship with the regression tests used in the presentation work: `Http::fake` records exactly the manifest's requests, a DB write listener sees none, row counts and the serialized `job_postings`, `applications`, `agent_runs`, `generation_attempts` tables are byte-identical before and after.

## 7. The `expiryDate` filter assumption (reviewed)
- All 14 stored values are JSON integers today (checked by `json_type`).
- Two readers disagree on a **non-integer** value. The interpreter (`parseExpiry`) accepts integers, digit strings and parseable date strings. The Inbox/Home SQL filter compares the raw JSON value with `now()->timestamp`, and in SQLite any text value sorts **after** any integer, so a stored string such as `"2026-12-01"` is never treated as expired: the Inbox keeps the job while the badge says "Expired". (A `CAST(... AS INTEGER)` would be worse: `'2026-12-01'` casts to 2026, an instant false expiry.) Epoch milliseconds are ignored by both.
- **Smallest correction (not made; needs approval, no migration, no data write):** normalise at the provider boundary. In `HimalayasDiscoveryProvider`, store `expiryDate` as an integer epoch in seconds using `HimalayasLivenessInterpreter::parseExpiry`, and omit it when unparseable. Future ingests then always store what the filter reads. Add a Pest test with integer, digit-string, ISO-string, millisecond and garbage inputs, and one test that asserts the Inbox filter and the badge agree for each stored form. Existing rows need nothing (all integers). Until then it is safe: a non-integer value fails open (the job is shown, never wrongly hidden).

## 8. Reviewing the freshness UI locally without production records
Use a throwaway database and a separate server process; the production file and `.env` are never touched.
1. `DB_CONNECTION=sqlite DB_DATABASE=<scratch>/demo.sqlite php artisan migrate --force` (verified: creates an empty database; the production file's `job_postings` count is unaffected).
2. Seed it from factories with one job in each state: unchecked, confirmed open, possibly closed, expired (past `expiryDate`), confirmed closed, inconclusive, a pursued expired job, and an expired job with an Application. (Needs a small demo seeder or command that refuses to run unless `DB_DATABASE` is not `database/database.sqlite`; to be built on request.)
3. `DB_DATABASE=<scratch>/demo.sqlite JOB_FRESHNESS_ENABLED=true php artisan serve --port=8123` (and `npm run dev` or the existing build), then review `/jobs`, `/jobs?inactive=1`, `/opportunities` and the drawers in a browser. The flag is set only in that process's environment. Delete the scratch file afterwards.
The scratch database was created and migrated once during this preparation to confirm the override works; it holds no data.

## 9. Gates
1. **Gate A (offline, approval to build):** the `expiryDate` normalisation; the pilot runner (`jobs:freshness-pilot`, plan-only by default, manifest-hash checked, `--confirm-requests=9`, evidence-file output only, no database access beyond reading the sample); the demo seeder; all with `Http::fake` tests. No request is made.
2. **Gate B (presentation-only activation, locally reviewed):** after the visual review in section 8, an owner decision to set `JOB_FRESHNESS_ENABLED=true`. Reversible by unsetting it. With no observations it shows almost nothing (every job "Not live-checked").
3. **Gate C (the live pilot):** a separate approval naming the two hosts, the 9 requests and the manifest hash, the pacing and stop conditions above, and confirming the Himalayas terms permit single-job API checks (the existing note that its terms block page-based work concerns pages, not this documented endpoint, but it must be confirmed).
4. **Gate D (production writes), scheduled checks and any Jobicy Commercial activity:** each separately gated, unchanged from `docs/job-freshness.md` stages 5 and 6.

## 10. Gate A: implemented offline (branch `feature/job-freshness-gate-a`; nothing run live)
- **Expiry normalisation.** `HimalayasLivenessInterpreter::normalizeExpiry` is the one reading of a provider expiry: integer epoch seconds (2000-2100), 13-digit milliseconds (divided by 1000), an integral float, or an ISO-8601 date-time with an explicit offset. Anything else is *unknown*, never expired: zero, negatives, a number between the seconds and milliseconds ranges (ambiguous), a date with no time or offset, text. `HimalayasDiscoveryProvider` stores that integer (and drops unknowns). The badge reads a stored expiry only as an integer in seconds, so the Inbox/Home SQL filters and the badge agree for every stored form (a legacy string or millisecond value is unknown to both and the job stays visible). No existing row is modified (all 14 are already integers).
- **Pilot runner.** `php artisan jobs:freshness-pilot --manifest=docs/pilot/job-freshness-pilot-manifest.v1.json --manifest-hash=<sha256> [--mode=plan|dry-run] [--responses=file --out=newfile]`. Plan prints the 9 requests; dry-run answers them from recorded or synthetic data (no I/O) and writes `<out>` (evidence) and `<out>.observations.json` (replayable by `jobs:freshness-report --observations=`). `--mode=live` is refused, and the real HTTP transport refuses unless a literal `false` in `config/job_freshness.php` is changed in code. The manifest holds local ids and one-way identity fingerprints only; its SHA-256 must equal `job_freshness.pilot.approved_manifest_sha256`; every source row is re-read and fingerprint-checked, hosts and the 9-request plan are verified, all before any request. Any manifest change needs a new authorization (and a new approved hash).
- **Isolated demo.** `FRESHNESS_DEMO=1 QUEUE_CONNECTION=discard JOB_FRESHNESS_ENABLED=true DB_CONNECTION=sqlite DB_DATABASE=/abs/path/freshness-demo-x.sqlite php artisan freshness:demo --database=/abs/path/freshness-demo-x.sqlite` migrates and seeds ten fictional jobs (every state, plus an expired pursued job and an expired job with an Application), then the same environment with `php artisan serve` shows them. Safeguards: explicit marker; a `freshness-demo*.sqlite` absolute path that is not the production file by path, symlink, hard link or directory; the process's own database must be that file; a `discard` queue (driver `null`: dispatches are dropped, not run or stored); `Http::preventStrayRequests()`; a file that already holds job rows is touched only if it carries the demo's `PRAGMA user_version` marker. A process started with `FRESHNESS_DEMO=1` also checks these at boot, so it cannot run on the production file at all.
- **Known limit found while building the demo.** A closure that rests only on recorded observations (not on a stored `status=closed` or a passed expiry) is *labelled* "Closed" but the Inbox still lists the job, because the list is filtered from stored fields; writing a status is stage 5. The visual review will show this: a "Closed (confirmed twice)" demo job stays in the Inbox.
