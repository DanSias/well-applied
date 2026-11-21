# Jobicy Commercial API sample: what was built (B2), what is unverified, and the approval a live run needs

**Status: implemented and tested entirely offline.** No credential exists, no wallet was funded, no request (free or paid) has ever been made by this code. Everything below about Jobicy's behaviour comes from its public documentation
(read 2026-11-22 through a summarising fetch tool) and is marked as such.

## What exists
| Piece | Where | Role |
|---|---|---|
| `JobicyApiClient` | `app/Support/JobDiscovery/JobicyCommercial/` | isolated client for `GET https://jobicy.com/api/jobs`; key from configuration only; no retry, no sleep |
| `SpendBudget` | same | strict request and spending budget; refuses a paid page whose WORST case (count x 1 cent) does not fit |
| `SampleEvaluator` | same | pure comparison of one free page and one Commercial page, by job id |
| `DestinationClassifier`, `UrlShape`, `Wilson` | same | genuine-destination judgement, URL-free reporting, uncertainty |
| `SampleRun` | same | free page, 1.1 s spacing, paid page, comparison; stops on any failure; at most one page per tier |
| `jobicy:commercial-sample` | `app/Console/Commands/` | the gated command; writes ONE new sanitised report file, no database row |
| config | `services.jobicy_commercial` | `enabled` (default false), `base_url`, `api_key` |

It is **not** wired into discovery, ingestion, any schedule or any migration, and the existing `JobicyDiscoveryProvider` and its endpoint (`/api/v2/remote-jobs`) are untouched (tests assert both).

## Endpoint and request assumptions
| Assumption | Basis | Verified live? |
|---|---|---|
| `GET https://jobicy.com/api/jobs` serves both tiers | documentation | **No** |
| `Authorization: Bearer <key>` on every commercial request; none for the free tier | documentation | **No** |
| query `count` (1 to 100), `geo`, `cursor` | documentation | **No** (`geo=usa` is what the existing provider sends to the old path) |
| response `{success, statusCode, jobs[], jobCount, nextCursor, hasMore, total_request_cost, ...}`, per-job `job_request_cost` | documentation | **No** |
| `url` is the original ATS URL when one exists, otherwise the Jobicy listing | documentation | **No** |
| `$0.01` per new direct-link job, once per key; repeats and non-direct jobs free | documentation | **No** |
| 400 (`success:false`), 429 and 503 with `Retry-After`; 1 request/second/IP | documentation | **No** |
| **missing or invalid key: NOT documented.** Treated as 401/403 `auth_failed`; but the API might instead quietly answer as the free tier | **assumption** | **No** |
| **insufficient wallet: NOT documented.** Treated as 402 `insufficient_balance` | **assumption** | **No** |
| `jobSlug` is in the response and **already contains the job id** (`163636-some-title`); the listing is `https://jobicy.com/jobs/{jobSlug}` | **verified against the FREE tier on 2026-11-22** (3 of 3 jobs; the earlier `{id}-{jobSlug}` rule was wrong and has been corrected) | Free tier: yes. Whether the Commercial tier returns the same `jobSlug`: **No**: the sample measures it |
| the free and Commercial first pages of the same query contain the same jobs | assumption | **No**: jobs published between the two requests will show as unmatched |

If a Commercial answer carries **no cost field at all**, the evaluator warns `commercial_tier_not_observed`, because a bad key might simply return a free-tier answer.

## Cost and gates
The worst case of the paid request is `count` cents, and the run refuses unless `--max-cost-cents` covers it (hard ceiling 200). `--count 100 --max-cost-cents 100` bounds the whole sample at **$1.00**. The command refuses unless:
`JOBICY_COMMERCIAL_ENABLED=true`, the key is configured, `--confirm-spend`, a sufficient `--max-cost-cents`, and `--out` names a file that does not exist. It makes at most two requests (one free, one paid), never follows a cursor, and never retries. A 429/503 is recorded with its (clamped) `Retry-After` and the run stops: re-running later is the operator's decision.

## What the report answers
Totals and matches by id; jobs reaching genuine employer or ATS URLs (with a 95% Wilson interval); jobs still pointing at Jobicy, aggregators or trackers; the ATS platform mix; listing-URL reconstruction accuracy (`jobicy.com/jobs/{jobSlug}`, requiring the slug to start with the job's own id and a hyphen; a missing or inconsistent slug is reported as not reconstructable, never guessed); the API-reported charge, its reconciliation with per-job charges and the cost per usable destination; unmatched, ambiguous and malformed records; and each genuine candidate in the **B1 contract** (listing preserved as `canonical_url`, `tier: commercial`, `verification: unverified`, `may_replace_apply_link: false`) without persisting anything.
Every report states that it describes **one page of the last seven days of the public feed, not Jobicy's historical inventory or our stored jobs**, and that "genuine" is a judgement about URL text, not reachability.
Reports contain host and path shape only: no query string, slug, title, company, description or credential (tests assert it).

## Decision rule (fixed before any sample)
Adopt the Commercial tier for forward discovery only if, on a sample of about 100, the genuine-destination share is at least about half, the listing URL rebuilds for every job that has a consistent `jobSlug`, the API's charges reconcile, and the projected cost is a few dollars per thousand jobs. Otherwise keep the free tier. This sample **cannot** justify backfilling stored jobs: they are outside the seven-day window.

## The exact approval a small supervised live sample needs
The owner, in chat, in these words or others naming the same items (it does not exist until given; nothing runs without it):

> I approve ONE supervised live sample of the Jobicy Commercial API, on these terms.
> 1. **I** will create the Jobicy account and API key, fund the wallet with no more than about $5 with auto top-up off, set `JOBICY_COMMERCIAL_ENABLED=true` and `JOBICY_COMMERCIAL_API_KEY` in my own environment, and revoke the key afterwards. The assistant will not see or handle the key.
> 2. **Requests:** at most two: one free `GET https://jobicy.com/api/jobs?count=100&geo=usa` and one authenticated `GET` of the same query, spaced at least a second apart, one page each, no retry, no cursor.
> 3. **Spend:** `--count 100 --max-cost-cents 100` (at most $1.00).
> 4. **Output:** one new sanitised report file; no database row, no production change, no backfill, no migration.
> 5. **Terms:** I have read Jobicy's terms and accept this use of its API.
> 6. **I** will run `php artisan jobicy:commercial-sample --count=100 --max-cost-cents=100 --confirm-spend --out=<new file>` and share the report with the assistant.
> 7. The result authorises nothing further; adopting the tier, any ingestion change or any live job-liveness check needs its own approval.

A separate, optional approval can let the assistant make the single unauthenticated comparison of the old and new Jobicy endpoints (`/api/v2/remote-jobs` against `/api/jobs`); it is not part of this sample.
