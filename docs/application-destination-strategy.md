# API-first application destination strategy (investigation; nothing implemented)

Status: **investigation and proposal.** No production code, record, migration or provider was changed, no live API was called, and no provider page was fetched or scraped. The provider documentation pages were read once;
what they say is reported as summarised by a page-fetch tool and has **not** been verified word for word. Nothing here is legal advice.

## 1. What the three aggregator providers actually give us

| Source | What the API supplies as the link | Employer/ATS URL in the API? | What the app stores | Where |
|---|---|---|---|---|
| **Himalayas** (`himalayas.app/jobs/api`, no key) | `applicationLink` ("Link to apply for the position") | **No** separate employer/ATS field. Docs: use `applicationLink` for **every Apply button, unchanged**; show a visible link to Himalayas as source | `applicationLink` → `job_postings.source_url` (it is also the fallback `discovery_source_id`) | `HimalayasDiscoveryProvider::normalize` |
| **Jobicy** public API (free, no key) | `url` = the **Jobicy listing URL**, always | **No.** Docs: with the public API keep Apply links pointing at the Jobicy page; preserve the canonical `url`; credit Jobicy | `url` → `job_postings.source_url` | `JobicyDiscoveryProvider::normalize` |
| **Jobicy Commercial Jobs API** (same endpoint, `Authorization: Bearer <key>`, wallet) | `url` becomes the **original ATS application URL when one exists**, otherwise stays the listing URL | **Yes**, per job, where available | not used | none |
| **Adzuna** | `redirect_url`: a click-through tracker | **No** | `redirect_url` → `source_url` | `AdzunaDiscoveryProvider::normalize` |

### Is an API-provided destination being discarded?
**No, for these three on their free tiers: they do not provide one.** What the code keeps is exactly what the API gives: the provider's own page (or tracker) as `source_url`. The only provider that supplies a destination distinct from the source URL
today is USAJOBS (`ApplyURI`, kept in `discovery_metadata.discovery.applyUris` and read by `ResolveInspectionStartUrl::providerDestination`). Eightfold's public position URL is already a direct employer-hosted URL. The one place a destination
is *not* captured is **Jobicy's Commercial tier**, because the app does not use it.

### Other findings from the code review
- **Attribution is not shown anywhere.** Both Himalayas and Jobicy require crediting the source and linking to it. The UI never mentions the provider (only `discovery_source` exists on the record). This is a **terms gap independent of resolution**.
- **Himalayas' `applicationLink` is overloaded**: it is the Apply URL, the dedupe key (`findByApplicationUrl`) and the fallback source id. That is fine as long as it stays unchanged; it must never be overwritten by a resolved employer URL.
- **Expiry is recorded but not enforced.** Himalayas' terms require removing expired jobs; `expiryDate` is kept in metadata (none are past expiry today). Both providers also publish a **documented liveness check** (below) that nothing uses.
- **Jobicy endpoint drift (unverified):** the repo calls `jobicy.com/api/v2/remote-jobs`; Jobicy's current documentation lists `GET https://jobicy.com/api/jobs` (apiVersion 2.2.x). Confirm the old path still serves the same shape before relying on it.
- **Jobicy etiquette:** documented limits are at most 1 request per second per IP and automated passes no more than once per hour. Discovery runs on a daily cadence, so this holds; the provider's retry (3 attempts, a fixed 1 s delay, including on 429) does not honour `Retry-After`, which the docs ask for.
- **The existing URL model is already the right shape.** `ResolveInspectionStartUrl` keeps *source URL*, *provider destination*, *application URL* (origin `user` or `resolved`) and per-run *requested/final* URLs apart, with origins `user | resolved | provider | source`. The proposal extends it rather than replacing it.

## 2. What the terms allow (summary; see `workers/browser-inspector/eval/live/AUTOMATION-RESTRICTIONS.md`)
- **Himalayas:** the website terms prohibit bots and screen scraping of its pages, **except** the public API, job feeds and MCP server; API users must use `applicationLink` for every Apply action and show a source link. **Browser resolution of Himalayas pages is not permitted.**
  The documented API also offers a **single-job check** (`GET /jobs/api/companies/:company/jobs/:slug`: 200 open, 410 closed with `expiryDate`, 404 not found; cached up to 10 minutes) that answers "is it still open" without touching the page.
- **Jobicy:** terms prohibit scraping "other than via our official API" and bypassing limits; robots.txt disallows `/go/` and `/away.php`. Jobicy documents a free **batch status endpoint** (`GET /api/jobs/status?ids=...`, up to 100 ids; `active | closed | unknown`; no cost) for liveness.
- **Adzuna:** `redirect_url` is a click-through tracker; following it is a third-party side effect and needs its own permission (see the resolution plan). Adzuna's terms are unverified.

## 3. Jobicy Commercial Jobs API: is it practical?
- **What it is:** the same endpoint with a Bearer key from Jobicy's dashboard; a funded wallet is required.
- **What it adds:** `url` is the original ATS application URL where Jobicy has one. **Cost:** $0.01 per job *with* a direct URL, once per key (repeat requests for that job and key are free); jobs without a direct URL cost nothing; each page reports `total_request_cost` and per-job `job_request_cost`.
- **Practicality:** at the current volume (about 177 Jobicy jobs stored; 100 to 200 per fetch) a full refresh costs **at most about $1 to $2**, and far less after the first run per key. That is small enough that cost is not the obstacle. The obstacles are: opening an account and funding a wallet (the owner), storing a secret, an unknown **share** of jobs that have a direct URL (only a sample call would tell), and **terms**: the docs do not say Apply buttons must use the commercial URL, and attribution is still required.
- **Recommendation:** worth a **small, owner-approved sample** (a few dollars, one key) *before* investing in browser resolution for Jobicy, because it would replace a resolution step with an API field and remove the Jobicy terms ambiguity around page access.

## 4. Representing the URLs without confusing them
Keep five distinct things, each with its own meaning and provenance. Nothing may overwrite another.

| Name | Meaning | Today | Provenance values |
|---|---|---|---|
| **source URL** | exactly what the discovery API gave: the provider's page or tracker. For Himalayas it is the mandatory `applicationLink`; for Jobicy public the canonical `url`. Never altered. | `job_postings.source_url` | `api:public:<field>` |
| **provider destination** | an employer/ATS URL the provider's API supplied **separately** (USAJOBS `ApplyURI`; Jobicy Commercial `url` when it differs from the listing; Eightfold) | only USAJOBS, in `discovery_metadata.discovery.applyUris` | `api:public:ApplyURI`, `api:commercial:url` |
| **resolved destination** | a destination found by following the provider's own links (our guarded walk) or by a person, with **status** (`verified_direct`, `proposed_by_resolution`, `login_required`, ...), hops and evidence. A **proposal**, never `human_verified` unless a person confirmed it | nowhere (offline evaluation reports only) | `browser_walk`, `human` |
| **application URL** | where the application is actually completed | `applications.application_url` with origin `user` or `resolved` (an inspection reached a form) | `user`, `resolved` |
| **attribution** | who must be credited and linked, and the canonical URL to link | not stored or shown | `required: true`, `canonical_url`, `rule` |

Rules that prevent confusion:
1. **`source_url` is immutable and is the only Apply link for Himalayas.** A resolved destination is a separate field and never replaces it.
2. **A provider destination is an input, not an answer.** It may seed an inspection's start URL (as USAJOBS already does) but is not copied into `application_url` until an inspection reaches a form there or a person confirms it.
3. **A resolved destination never claims more than its status.** `verified_direct` only when a form was observed on the source URL's own board; otherwise `proposed_by_resolution`; `human_verified` only by a person. It carries its method and hop evidence.
4. **Show provenance in the UI** wherever a URL is shown ("from Jobicy", "found by following the link", "you entered this").

### Where it could live (proposed, not built)
- **No migration needed first step:** generalise `discovery_metadata.discovery` with `destinations: [{url, field, tier: public|commercial}]` and `attribution: {provider, canonical_url, required}`; teach `ResolveInspectionStartUrl::providerDestination` to read `destinations` for any source (today USAJOBS only).
- **Migration later (separate approval):** a `destination_resolutions` table keyed by `job_posting_id` (so it can run on a Job before it is an Opportunity): status, reason, destination URL (no query), host type/platform, `needs_human_review`, hops JSON, method, resolver version, inspector source hash, reviewed-by/at. It never writes `application_url` itself.

## 5. Which sources still need browser-based resolution?

| Source | Needs browser resolution? |
|---|---|
| Eightfold, USAJOBS, manual ATS URLs | No: the URL is already direct (inspection reads the form) |
| **Himalayas** | **Not permitted.** Use the API `applicationLink` as the Apply link; a person can open it and enter the employer's URL (origin `user`); ask Himalayas whether automated resolution is allowed |
| **Jobicy** | **Only if the Commercial API is not adopted** (and only after the terms ambiguity is accepted or resolved) |
| **Adzuna** | Yes, and it needs the tracker permission and Adzuna's terms reviewed first; otherwise by hand |
| Manual aggregator links (Indeed etc.) | Yes, with the same caveats; mostly by hand |

## 6. Recommended order
1. **Terms and permissions (owner):** read Jobicy's and Adzuna's terms; email Himalayas (`hi@himalayas.app`, per its docs) asking whether resolving employer destinations is permitted; decide on a Jobicy Commercial sample.
2. **Attribution and canonical links (small, no migration):** show "via Jobicy/Himalayas" with the canonical link; make Himalayas Apply actions use `applicationLink` unchanged.
3. **Liveness through documented endpoints:** Himalayas single-job check and Jobicy batch status, replacing page-based reachability for those sources (staleness and expiry obligations).
4. **Provider destinations in metadata** for any source that supplies one (no migration), and a Jobicy Commercial adapter behind a config flag (live sample call only with approval).
5. **Browser resolution evidence** persisted (migration, separate approval) and the live pilot for the sources that still need it (Jobicy public tier if chosen, manual links), under the existing authorization model.
6. **A "confirm the destination" step for a person** (sets origin `user`) for Himalayas and Adzuna jobs.

## 7. Approvals this needs
- Owner decisions on Jobicy terms risk, a Jobicy Commercial sample (account, wallet, key, a few dollars, one live API call), and the Himalayas email.
- A live API call for any sample (none made).
- A migration for persisted resolutions (step 5).
- The usual authorization for any live browser resolution.

## 8. Limits of this investigation
Provider documentation was read once through a summarising tool and may be incomplete or out of date. Himalayas' API terms (clause 113) were seen only through a summary and third-party notes. The share of Jobicy jobs with a direct ATS URL is unknown. Whether
Himalayas' `applicationLink` itself redirects to the employer is unknown (the docs do not say). No API was called to check any response.
