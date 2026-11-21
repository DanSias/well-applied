# Offline resolution batch runner

Runs a **proposed** destination-resolution batch under hard limits and records what happened to every case. **This milestone is offline only: it has no live mode.**

```
node eval/runner/cli.js plan    --manifest <batch.json> --expect-sha256 <hash> [--out <dir>]    # validate and show what WOULD run; runs nothing
node eval/runner/cli.js dry-run --manifest <batch.json> --expect-sha256 <hash> [--out <dir>]    # the real resolver over LOCAL FIXTURE servers only
```

Exit codes: 0 ok · 2 usage (including any attempt to use a live mode) · 3 refused before anything ran · 4 the batch stopped early.

## What it enforces

| Rule | How |
|---|---|
| The manifest is **not** an authorisation | it must say `status: proposed`, must not carry `approved`/`authorised`/`run`, and its hash is checked **first**, before it is parsed |
| Concurrency 1, **no retries** | sequential loop; a case is called once; a failure is its result |
| Per-host pacing | a case waits out the minimum gap since its source host (and any host an earlier case reached) was last requested; pacing that would pass the deadline stops the batch instead |
| 3 pages per case, a total navigation cap, a wall-clock deadline | from the manifest; the cap is checked before each case, the deadline before each case and each wait |
| Stops the **whole batch** on | any policy security refusal; a prohibited (non-GET) method; a changed or unreadable source URL (re-hashed against the manifest before each case); 2 consecutive infrastructure failures; 3 site blocks (403/429/CAPTCHA) overall |
| Stops one **host** on | 2 site blocks on that host (its remaining cases are skipped with the reason) |
| Tracker redirects OFF | `allow_tracker_redirects` alone is refused; it needs an approval naming the batch hash and the exact cases that need it, and then applies to those cases only. A manifest that enables it is refused |
| Unknown redirectors need review | the resolver refuses them before navigation; the runner puts them in the review queue |
| Everything is accounted for | every case is `attempted`, `skipped`, `unattempted` or `refused`, with a reason; a checkpoint is written after every case |
| No production data | no database, relay, Laravel client or profile access (a test scans for it); it creates no Opportunity, Application or agent run |
| A clean committed report | URLs are reduced to host + path shape: no query strings, ports, cookies, credentials, page text or public slugs (`/companies/<slug>/jobs/<slug>`) |

An interrupt (Ctrl-C) stops between cases: finished cases are kept, the rest are `unattempted: batch_stopped:interrupted`. There is no resume and no retry: a new run is a new, separately named run.

## Files

`batch.js` (pure core: injected resolver, URL source, clock) · `sanitize.js` · `report.js` · `dry-run.js` (the only module that imports the inspector, through the resolver and the local fixture sites) ·
`cli.js` · `fixtures/dry-run-batch.json` (local fixture URLs, same format as the proposed batch) · `samples/` (a committed plan of the real 13-case batch and a dry run).

## What does NOT exist yet

A **live adapter**: the two functions that would make a live run possible: re-reading a case's URL from the database **by id, read-only**, and calling the resolver with the real guard against the public internet.
Until that is built, reviewed and separately approved, nothing in this directory can make an external request. The runner's core already takes them as injected functions, so the live adapter is a small, separate module.

## Proposed authorization for a small supervised live pilot (NOT GRANTED)

For the owner to grant, in chat, in these words or others naming the same items. It does not exist until it is given, and it cannot be given for the runner as it stands (no live mode).

> I approve building and then running ONE supervised live pilot of destination resolution.
> 1. **Manifest:** `eval/cohort/resolution-pilot.proposed.json`, SHA-256 `92f8e929759152c8f0a10c5efe6561512847fa9f9b86930159677759d28deea9` (3 cases: prod-180 and prod-393 on jobicy.com, prod-83 on himalayas.app). The run must refuse any other hash.
> 2. **Source URLs** are re-read read-only from `database/database.sqlite` by job id and must match each case's `url_sha256`.
> 3. **Hosts:** requests to jobicy.com and himalayas.app (one navigation per case), and to the destination hosts those pages' single Apply links lead to (at most two navigations per destination host per case), through the existing guard only (public addresses, GET/HEAD only, hop limits 3 pages / 2 follows / 8 redirects).
> 4. **Limits:** concurrency 1, no retries, 10 s between requests to the same host, 9 page navigations in total, 8 minutes wall clock.
> 5. **Tracker redirects off.** Unknown redirectors are flagged for my review and not followed. Adzuna is excluded.
> 6. **Stops** the whole run on any security refusal, prohibited method, changed source URL hash, 2 consecutive infrastructure failures, or 3 blocked/CAPTCHA/429 cases; stops a host after 2 such cases.
> 7. **Output** only under `eval/runs/`, sanitised as above. Nothing is written to Jobs, Opportunities, Applications, agent runs or profile tables; no application data is entered, nothing is clicked, uploaded or submitted.
> 8. **I am present** and will start the run; every ambiguous or flagged result is reviewed by me before anything is called verified.
> The pilot's result authorises nothing further: the other 5 Jobicy/Himalayas cases and the 5 Adzuna cases each need their own approval.

Pilot manifest hash: `92f8e929759152c8f0a10c5efe6561512847fa9f9b86930159677759d28deea9` · full 13-case batch hash: `6e169a26b07c24380e7ce99b2e7ae18f772b7b41bce1de4370f06a1fb201f2ad`.

**Before that approval can mean anything**, a small **live-adapter milestone** is needed (the read-only URL source, the live wiring, an authorisation check that the manifest hash and case ids match the approval, and tests that it refuses everything else). Run nothing until it is reviewed and merged.

## Remaining risks

- The resolver has only met synthetic fixtures; a live page may behave differently (JavaScript-only Apply buttons are reported, never clicked; a single bounce through an unknown host with no structural signal is followed and not flagged).
- Pacing is per source host plus hosts an earlier case reached; a destination host first met within a case is not paced before that case.
- Stop thresholds are conservative defaults chosen without live data.
- Jobicy and Himalayas terms of use for automated requests are **unverified**.
- `url_shape` in the cohort and batch still contains slugs; the runner's reports do not.
