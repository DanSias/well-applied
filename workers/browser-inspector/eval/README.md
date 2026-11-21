# Application inspection evaluation harness: Stage 0 (offline)

Scores the inspector's results against ground truth, repeatably, using only evidence already committed to this repository.
**Stage 0 reads files and writes reports. It opens no connection, runs no browser (except the replay refresh, over local fixture
servers), touches no database, and writes nothing to Well Applied.** It is not part of the production image (`Dockerfile` copies
only `src/`), and nothing in `src/` knows it exists; `test/eval-safety.test.js` enforces both.

```
node eval/src/cli.js run --config generic-on --out eval/reports/<name> [--inspector-commit <sha>]
node eval/src/cli.js compare <before>/report.json <after>/report.json [--out <dir>] [--fail-on-regression]
eval/tools/make-sample.sh [<sha>]            # regenerate eval/reports/sample/ (committed)
node eval/tools/refresh-results.mjs          # re-replay the synthetic fixtures (local fixture servers only)
node eval/tools/seal-manifest.mjs            # re-pin evidence + result hashes after a DELIBERATE evidence change
```

Exit codes: `0` ok · `1` regression (compare, with `--fail-on-regression`) · `2` usage · `3` evidence integrity failure.

## What is evaluated

`manifest.json` (`eval-manifest/1`, version `0.1.0`) lists 11 cases. **Synthetic regression fixtures and real-world captured
evidence are separate origins, reported separately and never pooled.**

| Origin | Cases | Result under test | Ground truth |
|---|---|---|---|
| `synthetic` | 9 (`syn-*`): the eight universal-corpus fixtures and the unknown-ATS form | replayed through the real `inspect()` over local fixture servers, once per configuration (`generic-off` = production today, `generic-on`) | the corpus's expected files, **authored** with the fixtures |
| `real_world` | `real-chainguard-4722145006` (Greenhouse) | the stored production run #12, re-shaped into the worker→Laravel contract. **Fixed**: it cannot change with the inspector | the golden: observed rows, the Greenhouse API, explicit inferences |
| `real_world` | `real-nerdwallet-258` (Ashby) | the committed schema-only contract result (kept in step by the Ashby tests) | a person's own browser DOM observation: independent of the schema, but **not exhaustive** |

Results are existing contract results; the scorer never runs or re-implements extraction (it imports nothing from `src/`).

## Evidence bases: what a score rests on

Every case states the kind of result its score rests on, in `report.json` (`evidence_basis`, `evidence_bases`, `live_inspection`) and in `report.md`:

| Basis | Live inspection? | Meaning |
|---|---|---|
| `captured_live_inspection` | yes | a stored result of a real production run; fixed |
| `offline_provider_schema_snapshot` | **no** | offline schema-adapter output over committed provider-schema evidence; no page opened |
| `synthetic_fixture_replay` | **no** | the real inspector over a local synthetic fixture; regression evidence only |

NerdWallet's scored result is an **offline Ashby schema-adapter snapshot, not a successful live Ashby inspection**. Origin aggregates that
mix bases are labelled `mixed_evidence` and are **not a live-inspection success rate**; the same metrics are also given per basis
(`aggregates_by_evidence_basis`), never pooled across bases. Stage 0 has one live-inspection case, and no such rate is measured.

## Evidence classes

`observed` (seen directly) · `api_derived` (provider data) · `authored` (defined by a synthetic fixture) · `inferred` (reasoned,
**not** firm) · `unknown` (never scored). Accuracy metrics use observed, api_derived and authored truth. Truth that is `inferred`
is scored in a separate `secondary_inferred_truth` figure and never in the headline; `unknown` is counted in no figure.

## Metrics

Every metric is `numerator / denominator` over the **scorable cases**; `unscored` lists each other case with its reason. Rates
are micro-averaged over fields, not averages of per-case rates. Missing evidence is never a pass and never a failure.

| Metric | Definition | Unscored when |
|---|---|---|
| `application_url_resolution` | resolved URL equals the true application URL (origin + path); a listing must resolve none | no truth; a schema-only result (it carries no URL by design) |
| `platform_classification` | `ats` equals the true platform (`unknown` for none) | no truth |
| `field_recall` / `field_precision` / `field_f1` | one-to-one matching by control id, then by normalised label (a truncated reference label matches by prefix ≥ 20 chars). F1 = 2·TP / (2·TP+FP+FN) | recall: no scorable truth fields. Precision: **truth not exhaustive** (extra rows cannot be called wrong) |
| `field_type_accuracy`, `required_accuracy` | over matched fields, against firm truth | unmapped types are skipped; `required: null` is an abstention |
| `unresolved_label_rate` | rows with no resolved label / all rows | no rows |
| `provenance_expectation` | rows whose `extraction_source` the truth allows / all rows. The distribution of DOM vs `provider_schema` rows is reported too | no truth |
| `outcome_classification` | claimed outcome is one the evidence allows | no truth |
| `barrier_classification` | on cases whose truth is a barrier (no apply link, auth, unsupported…): outcome and stop reason both right | truth is not a barrier |
| `false_barrier_rate` | on extractable pages, how often a barrier/unsupported was claimed (lower is better) | truth is a barrier |

**Ambiguity.** A row or truth field that matches several candidates is reported as ambiguous and excluded from every count (with an
`ambiguous_matches_excluded` finding), never guessed. A second row claiming an already-matched field is a duplicate, counted as a
false positive.

## Findings (separate from the metrics)

- **high** `false_complete`: the result claims `complete` where the evidence supports only partial, or with known fields missing from an exhaustive truth. Reported in its own section, first.
- **high** `provider_schema_overclaim`: provider-schema rows claiming complete, or lacking the `provider_schema_only` / `visibility_not_established` warnings.
- **high** `evidence_altered` / `evidence_missing`: a pinned file differs or is absent; the case is not scored from it and the CLI exits 3.
- **medium** `unverified_complete`: `complete` claimed where the truth does not establish whether the form is complete.

## Reports

`report.json` (`eval-report/1`) has sorted keys, no timestamps and no environment data: the same inputs give the same bytes.
It records the manifest version and SHA-256, the inspector commit, `source_tree_modified`, and a hash of `src/` (equal hashes mean
the same inspector whatever the commit says), the configuration, every case's evidence (role, class, SHA-256, status) and the
evidence basis (below). `report.md` is rendered from it. `compare` judges a metric only when
both runs scored the same cases, otherwise `not_comparable`.

## Changing evidence

Evidence is pinned by hash in `manifest.json`, and replayed results in `results/INDEX.json`. Altering a fixture or result without
re-sealing fails the run. To change one deliberately: edit it, run the refresh/seal tools, and review the diff: that diff is the
record the change was intended. Bump `manifest_version` when cases or evidence change.

## Stage 0 limitations

- 11 cases: the figures demonstrate the harness and are **not** an estimate of production quality. The synthetic fixtures were
  written to be solvable; their 100% means the regression suite passes, not that real forms are read well.
- Two real-world cases: Greenhouse (an older run, with known errors) and one schema-only Ashby result.
- Matching by id hides label errors (a matched field with a wrong label is not penalised); no label-accuracy metric yet.
- The `must_not_report` rules in the corpus need a DOM and are not scored offline; an unwanted row still lowers precision.
- Sensitivity, widget and options accuracy are not scored (the data model does not persist them).
- Job normalisation, duplicate handling and source quality are not evaluated (no committed ground truth; Stage 1).

## Stage 1: selecting about 30 representative jobs

A **proposed** cohort now exists in `cohort/` (see `cohort/README.md`); it authorises nothing and nothing has run against it. The original plan follows.

1. A **read-only export** of candidate jobs from Well Applied's discovery data to a local file, run by the owner, with
   no write path back. Only source, ATS host, normalised URL and a hashed company slug are kept; no descriptions, no personal data.
2. **Stratify**, taking whatever the real system holds: Greenhouse ~6, Ashby ~5, Lever ~4, other ATS (Workday, Workable,
   SmartRecruiters…) ~4, direct employer pages ~4, aggregator-sourced (Adzuna, Jobicy, Himalayas) ~4, negative controls ~3 (closed,
   login-walled, dead). Add deliberate duplicates (same job from two sources) to measure duplicate handling.
3. **Select by a seeded, committed procedure**, not by hand, so the cohort is reproducible. Record the seed and the stratum counts.
4. **Ground truth** per job: the provider's public schema where one exists (Greenhouse); otherwise a form reviewed by a person once,
   in the evidence-class format above. A job without ground truth stays in the cohort and is unscored where truth is needed.
5. The cohort manifest is committed and **hash-pinned**; the owner approves the manifest hash before anything runs.

## Stage 2 and Stage 3: live runs (not started; each needs its own approval)

| Stage | What it does | Gate |
|---|---|---|
| 2: read-only discovery | public GETs through the existing guard to resolve the application URL and classify the platform | approval naming the **cohort manifest hash**, the host list and the request cap |
| 3: live inspection | the full inspector, one small cohort first (about 5, no Ashby), then the rest | a separate approval per run: cohort, caps, stop conditions. The Ashby flag stays a separate decision |

Both: concurrency 1 (at most 2), a per-host delay, a total request cap, **no automatic retries** (a failure is recorded as a
result), and a stop on any security refusal, repeated 403/429/captcha, two consecutive infrastructure failures, a deadline overrun or
the wall-clock cap. Neither stage enters data, uploads, prepares answers or submits, and neither changes the guard, sandbox,
nftables, Docker networking or the API relay. Live results are written to a run directory under `eval/runs/` (outside Well Applied's
tables) and scored by this same scorer, so a live run and a replay produce comparable reports.

## Where M2 (application readiness) fits

After this evaluation, not before. M2 consumes extracted requirements, so its quality is bounded by the field recall, type accuracy
and false-complete rate measured here. M2 must treat `partial` and `provider_schema` results as incomplete knowledge. Once a baseline
exists, M2 can reuse the cohort with its own ground truth and metrics (requirement coverage, sensitive-field handling).
