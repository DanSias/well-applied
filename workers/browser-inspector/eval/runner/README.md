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

## Live mode (this milestone; NOT used)

`live` exists, and it is **disabled unless a separate authorization matches EXACTLY** (`eval/live/`; see `eval/live/README.md`). The core validates it before reading or requesting anything:
the manifest hash; the allowed case ids (exactly the manifest's cases); the allowed source hosts; the execution limits (each equal to the manifest's, and within hard ceilings in code: 3 cases, 3 pages per case,
9 navigations, 10 s pacing, 8 minutes); tracker redirects explicitly `false`; a granted flag, a granter and the owner's verbatim statement; and a validity window of at most 24 hours. The CLI also needs
`--supervised`, and **refuses to reuse a run directory** (`eval/runs/live-<manifest12>-<authorization12>`), so a run is never resumed or repeated.

It reads source URLs from the production database **by id, read-only** (one column, one fixed SELECT, read-only connection plus `query_only`), re-hashes each before navigation, and calls `resolveDestination()` through
a guard built from the inspector's own `createGuard` with an added, refusing, per-hop host policy. **No live run has been made.** It is tested only against local HTTPS fixtures.

## Pilot authorization (NOT GRANTED)

The three-case pilot (`resolution-pilot.proposed.json`, SHA-256 `92f8e929759152c8f0a10c5efe6561512847fa9f9b86930159677759d28deea9`) includes a Himalayas case, and **Himalayas' terms prohibit automated access to its pages** (see
`eval/live/AUTOMATION-RESTRICTIONS.md`). **Do not approve it as written.** The proposed alternative is the two-case Jobicy-only pilot
(`resolution-pilot-jobicy-only.proposed.json`, SHA-256 `9edde06a2d21f9d40fd03389d8aa6d10206c86cfb9a7bc5884f89717724a1a5a`): prod-180 and prod-393 on jobicy.com, 6 navigations, 6 minutes, 10 s per-host pacing, concurrency 1, no retries.

Proposed authorization, for the owner to grant in chat in these words or others naming the same items (it exists only once given; nothing runs until then):

> I approve ONE supervised live pilot of destination resolution, on these terms.
> 1. **Manifest:** `eval/cohort/resolution-pilot-jobicy-only.proposed.json`, SHA-256 `9edde06a2d21f9d40fd03389d8aa6d10206c86cfb9a7bc5884f89717724a1a5a` (prod-180 and prod-393, jobicy.com).
> 2. **I have read Jobicy's terms and robots.txt** and accept the residual risk described in `eval/live/AUTOMATION-RESTRICTIONS.md` (or I have asked Jobicy and attach its answer).
> 3. **Hosts:** jobicy.com (one navigation per case) and the destination hosts its single Apply link leads to (known ATS hosts, or the one Apply destination the resolver chooses), through the existing guard only. Unknown redirect hosts are refused before they are requested; paths robots.txt disallows are refused.
> 4. **Limits:** concurrency 1, no retries, 10 s between requests to the same host, 6 page navigations, 6 minutes.
> 5. **Tracker redirects off; Adzuna and Himalayas excluded.**
> 6. **Stops** on any security refusal, prohibited method, changed source URL hash, 2 consecutive infrastructure failures, or 3 blocked/CAPTCHA/429 cases.
> 7. **Output** under `eval/runs/` only, sanitised. Nothing is written to Jobs, Opportunities, Applications, agent runs or profile tables; nothing is entered, clicked, uploaded or submitted.
> 8. **I am present** and will start it with `--supervised`; I review every flagged result before anything is called verified. This authorises nothing further.

On receiving that, the next steps are: create the authorization file with that statement, `granted_at`, a `valid_until` within 24 hours, the hash, the case ids, the host and the limits; then the owner runs the CLI.

## Remaining risks

- The resolver and adapter have only met **synthetic local fixtures**; live pages may behave differently. JavaScript-only Apply buttons are reported, never clicked. Third-party scripts never load (an unapproved host is refused), so a page that builds its Apply link from a third-party script shows none: that fails safe, as `no_apply_link`.
- **Robots rules are enforced for the source URL and the Apply candidate only.** The guard caches its per-host decision, so a later redirect hop on a host already visited is not re-judged by path. The snapshot is dated and secondhand.
- A redirect revisiting a host already used earlier **in the same case** is not paced again (same cache).
- The **pacing sleeps happen inside the guard's pre-request check**; a long wait could exceed Chromium's own navigation timeout and be recorded as a timeout (counted as an infrastructure failure, which stops the batch: a safe failure).
- Stop thresholds are conservative defaults chosen without live data. DNS rebinding between the address check and the connection is the inspector's existing, documented gap.
- The Jobicy terms ambiguity and the Himalayas prohibition above; the destination sites' terms were not reviewed.
