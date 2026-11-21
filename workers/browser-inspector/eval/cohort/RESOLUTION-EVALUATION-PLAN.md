# Supervised read-only evaluation plan: resolving the 13 aggregator candidates

**Status: PROPOSED. Not executed. This authorises nothing.** No request has been made to any aggregator, employer or ATS, and the component it
would use (`src/resolution/`) has only ever run against local fixtures.

- Batch manifest: `eval/cohort/resolution-batch.proposed.json`
- **Batch manifest SHA-256:** `74a4354e830bd9d9fb0234649104438c381691cfcb623e2906093ab256fba863`
- Derived from the Stage 1 cohort `eval/cohort/stage1.proposed.json` (its hash is recorded inside the batch). Regenerate with `node eval/tools/make-resolution-batch.mjs`; a test fails if this hash and the file disagree.
- An approval should name **the batch hash above**, the host list below, and the tracker decision. Anything that changes the file changes the hash and needs a new approval.

## What would run

13 aggregator-listing cases: **Jobicy 6, Adzuna 5, Himalayas 2** (including the six duplicate-pair rows). For each, one call to `resolveDestination()`: the inspector's own guarded
walk (GET/HEAD only, private-address refusal, host allow-list, at most 3 pages / 2 Apply follows / 8 redirects), recording every hop. It does not click, fill, authenticate,
upload, submit, extract a form, or create an Opportunity, Application or agent run.

## Execution limits

| Limit | Value |
|---|---|
| Concurrency | **1**: one case at a time, in id order, Adzuna (tracker) cases last |
| Retries | **none**: a failure is the case's result |
| Pacing | at least 5 s between requests to the same host; 2 s between cases |
| Per case | 3 pages, 2 Apply follows, 8 redirects, 90 s budget |
| Per host | the listing host: 1 navigation per case; a destination host: 2 navigations per case |
| Total | 39 page navigations (13 × 3); 20 minutes wall clock |
| Source URLs | re-read from the database **by id, read-only**; the run refuses a case whose URL hash differs from `url_sha256` |

## Stop conditions

Any one stops that host or the run, and the stop is reported, not worked around:

1. a security refusal other than the expected policy refusal of a prohibited destination;
2. HTTP 403/429 or a CAPTCHA/anti-bot page on **two** cases from one host (stop that host), or on **three** cases overall (stop the run);
3. two consecutive infrastructure failures;
4. the navigation cap or the wall-clock cap;
5. any non-GET request, or any attempt to interact with a page;
6. a changed `url_sha256`.

## Human review

Resolution outcomes are *proposals*. A person reviews: every `unresolved: multiple_apply_links`; every `proposed_by_resolution` flagged `needs_human_review`; every `too_many_steps` or unclassified stop;
the cases in the ten hand-review slots; and a seeded 20% spot-check of the automatic proposals. Only a person's confirmation makes a destination `human_verified` (a status the resolver can never produce).

## Adzuna: the tracker-redirect finding

**Following an Adzuna `/land/ad/…` link needs its own permission, and the resolver now enforces that mechanism.**

- **It is the stored source URL itself.** 182 of 205 stored Adzuna source URLs (and all 5 in this batch) have the shape `www.adzuna.com/land/ad/<id>?…`. The very first request would be the click-through. The other 23 use `/details/<id>?…` (a listing page).
- **Why it is different from reading a listing.** A `/land/ad/` URL exists to record an apply click and then redirect. A GET to it is a third-party request with a side effect we cannot see (a counted or billed click, tracking cookies, further redirect hops through other trackers). Whether automated requests are permitted by Adzuna's terms is **unverified**: I have not read them, and nothing in the repository records them.
- **The guard is not the issue.** Each redirect hop is already checked (public addresses, hop limit 8), and a redirect into a private address is blocked (tested). That makes it *technically* safe; it does not make it *permitted*.
- **Mechanism implemented.** `resolveDestination(url, { allowTrackerRedirects })` defaults to **false**. A tracker source URL is refused before any browser is launched or request made; a tracker Apply link is refused before it is requested (the listing is still read, the candidate recorded as evidence). Either returns `unresolved: tracker_redirect_requires_permission` with the rule and where it fired. Granting permission is a separate, explicit input, recorded in every result's provenance (`tracker_redirects_allowed`). Only one rule is known (`adzuna_land_ad`); other trackers are not recognised yet, so a hop through an unrecognised tracker would be recorded but not gated.
- **Consequence for the batch.** With the default, the **5 Adzuna cases would each return `tracker_redirect_requires_permission` with zero requests**, which is a valid, recorded outcome. The runnable set is Jobicy 6 + Himalayas 2 = 8.
- **Options, for you to choose:** (a) leave Adzuna refused and resolve those 5 by hand in your own browser (you click through; the resolver is not involved); (b) approve tracker redirects for those 5 only, after reading Adzuna's terms, with the limits above; (c) ask Adzuna's API for a non-tracking employer URL (not known to exist).

## What does not exist yet

A runner that reads the batch, re-reads each URL from the database by id, applies the pacing and stop conditions, and writes a run directory under `eval/runs/`. It is small and offline-testable; it is not built, because nothing here approves a run.

## Limitations

- The resolver's fixtures for Adzuna, Jobicy and Himalayas are **synthetic**, authored from URL shapes; nothing is known about the live pages. The first live run may meet markup the fixtures never imagined (JavaScript-only Apply buttons are reported, never clicked).
- Host typing knows seven ATSs by host. White-labelled careers sites (such as `jobs.nvidia.com` on Eightfold) are not recognised, so such a destination is `other_site` and flagged for review.
- `url_shape` in the cohort and batch contains the URL path with digits replaced. For Himalayas and Jobicy the path includes the slug of the title and, for Himalayas, the company: those are public URL text, not copied page content, but the earlier claim that no company name appears in the cohort is not strictly true for them.
