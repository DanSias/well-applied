# Stage 1: the proposed evaluation cohort

**Status: PROPOSED. This authorises nothing.** No discovery, URL resolution, inspection, or application preparation has run or may run
from this document. Stage 2 and Stage 3 each need their own approval (see `../README.md`). Every `liveness` below is `unverified`.

Files: `stage1.proposed.json` (the cohort, `eval-cohort/1`) · `composition.md|json` (generated: `node eval/src/cli.js cohort eval/cohort/stage1.proposed.json --out eval/cohort`) ·
`external-candidates.json` (the hand-curated half and its stated gaps) · `select-from-database.py` (the production half; read-only).

## The finding that shaped this

Of 412 discovered jobs, 397 (96%) are aggregator links and 14 are direct employer/ATS URLs. **No discovered job is on Greenhouse, Lever,
Workday, Workable or SmartRecruiters.** Only 2 of 10 applications have an application URL, both typed in by the user. So **application-URL
resolution and the quality of direct-ATS sources are the priority evaluation questions**, ahead of field extraction.

## Composition (32 cases: 30 new + 2 carried from Stage 0)

| Part | Cases | What |
|---|---|---|
| `production_discovery` | 20 | Eightfold direct ×4 (3 NVIDIA-hosted, 1 Lockheed: two employers); the one Ashby job ×1; same-source duplicate pairs ×3 (2 Jobicy, 1 Adzuna: 6 jobs); Adzuna ×3 (two older than 60 days), Jobicy ×2, Himalayas ×2; negative controls ×2 (a record with no URL; a manual record pointing at a search engine's job board) |
| `curated_external` | 10 | Greenhouse ×3, Lever ×3, SmartRecruiters ×2, Workday ×1, Workable ×1 |
| `stage0_existing` | 2 | Chainguard (Greenhouse) and NerdWallet (Ashby), with their evidence limitations carried |

Aggregator-sourced listings: 13 (including the six duplicate-pair rows). Platforms named in the request: all seven appear at least once.

### Rules the selection followed

- **Jobs that have an Application are excluded.** Pursuing a job is personal history, not a representative sample.
- **A thin record.** A production case stores its id, source, host, the *shape* of its URL, a SHA-256 of the exact stored URL and flags. **Not** the URL, title, company, description or location. Stage 2 re-reads the URL from the database by id and refuses to run if its hash differs.
- **Seeded** (`20261121`) and deterministic for a given database. It is a curation aid, not a random sample: the strata were chosen to cover things worth measuring.
- **An aggregator listing URL is never an application URL.** Every aggregator listing is `application_url.status = unresolved`, `value = null`, and no platform is guessed for it. The statuses `proposed_by_resolution` and `human_verified` are refused by the validator until Stage 2 has run and a person has reviewed.
- **Nothing was added to fill a quota.**

## Missing information and limitations

- **Nothing external was fetched.** The ten external candidates came from web search results only. Their liveness, titles (three Greenhouse titles are unrecorded: the results read "Back to jobs") and form contents are unverified. Search quality was poor: many hits were aggregators, vendor demo boards or old postings.
- **No Ashby or Eightfold candidate** surfaced externally. Ashby rests on one production job (and NerdWallet); an owner-supplied URL is the way to add more. Eightfold rests on production only.
- **Lever, Workday, Workable and SmartRecruiters rest only on external candidates**, several of them vendors' own or demo boards (`ext-lever-leverdemo-*` is a Lever demo posting: a stable form layout, not an employer). Their representativeness is weak.
- **The Workday page URL was derived** from the API path the search returned and has not been seen. Workday flows usually require an account: an authentication barrier is the likely correct result.
- **Two external controls are hypotheses**: `ext-greenhouse-greenplaces-4461030008` (a job id much older than its neighbours) and `ext-workable-careers-5656BF6FBE` (dated 2023). Whether they are closed is unknown.
- **No cross-source duplicate pair exists** in the database (0 of 28 duplicate groups), so duplicate handling is measured only within a source. A cross-source pair needs an external posting that also appears on an aggregator.
- **The unreachable/stale controls are only candidates.** The database has no reachability signal (`source_updated_at` is empty for every job); staleness here is posted-date age.
- **Aggregator destinations are unknown** for 13 cases, which is what the resolution procedure below is for.
- **Production sample is small and skewed**: 96% aggregator, one ATS family. Stage 1 results will not generalise to the market; they measure Well Applied's actual intake.
- The Stage 0 evidence limitations carry over unchanged (Chainguard: one old captured run with known extraction errors; NerdWallet: offline schema adapter, **not** a live Ashby inspection).

## Hand-reviewed form ground truth (10 cases)

Chosen where no provider schema exists and the answer teaches the most:

| Case | Why |
|---|---|
| `prod-2` (Ashby) | a second Ashby organisation: tests the unverified "other Ashby orgs" question using a browser DOM observation like NerdWallet's |
| `prod-148`, `prod-150` (Eightfold: Lockheed, NVIDIA) | the largest direct ATS in the data, with no ground truth and prior `unsupported` runs on the platform |
| `prod-246`, `prod-180`, `prod-83` (Adzuna, Jobicy, Himalayas) | one per aggregator: the destination AND its form, giving URL-resolution and platform truth together |
| `ext-lever-palantir-7d75bed5` | Lever has no schema endpoint in our adapters |
| `ext-smartrecruiters-smartrecruiters-743999873519601` | SmartRecruiters form |
| `ext-workday-workday-JR-0107781` | Workday: what is visible without an account; an authentication barrier is a scorable outcome |
| `ext-lever-leverdemo-835f5c45` | a stable, harmless Lever layout |

Two Greenhouse candidates use `provider_schema` ground truth (the public board API), which is a Stage 2 gate. Their form is not hand-reviewed. The rest are scored on
URL resolution, platform classification and outcome only. Hand review costs a person's time: this is the cap I would hold to.

## Proposed read-only resolution of aggregator destinations (not executed)

Goal: for each aggregator listing, find the employer's application URL, with provenance, without a person doing all 13 by hand.

1. **Input**: the cohort's aggregator cases. The listing URL is re-read from the database by id; its hash must equal `record_ref.url_sha256`.
2. **One public `GET` of the listing page** through the existing worker's guard (GET/HEAD only, private-address refusal, host allow-list), concurrency 1, a per-host delay, a fixed request cap, **no retries**. No clicks, no forms, no sign-in, no POST. Adzuna's `/land/ad/…` links are tracking redirects: **following them is a third-party request** and is listed separately in the Stage 2 approval.
3. **Collect candidates** with the existing Apply-link logic (`src/apply-links.js`): each candidate's href, anchor text, position, whether it is a redirect and its full GET-only redirect chain, with the host of every hop.
4. **Classify each candidate's final host**: a known ATS host (the seven platforms), the employer's own domain (the hostname contains the company token, as normalised by `NormalizeCompanyName`), the aggregator itself, a tracker or unknown.
5. **Accept automatically only when unambiguous**: exactly one candidate leads to a known ATS host or to the employer's own domain. The result is recorded as `proposed_by_resolution` with its full evidence. **It is not `human_verified`.**
6. **Send to human review** when: several different destinations; none; only a tracker or aggregator-internal "apply" flow; the destination domain does not match the company; an interstitial, login or captcha; or a redirect chain longer than the cap.
7. **Provenance is recorded for every outcome**: listing URL hash, each hop, timestamps, the inspector source hash, and which rule fired. `unresolved` is a valid result and is scored as such, never as a failure to be retried.
8. **Stop conditions**: any security refusal; a 403/429/captcha on two listings; two consecutive infrastructure failures; the request cap; the wall-clock cap.
9. **Output** goes under `eval/runs/` as files. It touches no Well Applied table and creates no Opportunity, Application or agent run.
10. Humans review only the `needs_review` cases and the `proposed_by_resolution` ones in the ten hand-review slots; the rest of the automated results are spot-checked (a seeded 20%).

**Gate:** this runs in Stage 2, only after you approve the cohort manifest hash, the host list, the request cap and whether tracker redirects may be followed.

## Reproducing

```
python3 eval/cohort/select-from-database.py <path to database.sqlite>      # read-only; rewrites stage1.proposed.json
node eval/src/cli.js cohort eval/cohort/stage1.proposed.json --out eval/cohort
```

Re-running against a later database will change the selection: the committed manifest is the proposal being reviewed.
