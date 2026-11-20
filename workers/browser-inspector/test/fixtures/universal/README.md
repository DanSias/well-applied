# Universal Application Inspection: the M0 corpus

Fixtures, golden expectations and measurable M1 criteria for a generic, read-only application-form inspector. **Nothing here
changes inspector behaviour.** It defines what "correct" means before any generic discovery is written, so M1 can be scored
instead of argued about.

Everything is offline and deterministic: no network, no AI box, no database, no live Greenhouse page. `npm test` runs
`test/universal-corpus.test.js`, which checks this directory (41 checks).

## Layout

| Path | What it is |
|---|---|
| `evidence/chainguard-4722145006/` | The real-world baseline. **Immutable** (hash-locked by `MANIFEST.json`). |
| `golden/chainguard-4722145006.semantic.json` | How that form should be *understood*. Every assertion is tagged with an evidence class. |
| `forms/*.html` | Eight synthetic adversarial fixtures. |
| `expected/*.expected.json` | What M1 must produce for each fixture (and what later milestones add). |
| `corpus.json` | The manifest: fixture hashes, the shared vocabulary, machine-checkable traits, the offline rules. |
| `m1-criteria.json` | The M1 acceptance criteria. |

The existing `../phase1/unknown-ats-form.html` is part of the corpus (hash-locked, not copied): a plain accessible
direct-hosted form on an unknown ATS. It ends `unsupported` today, so reading it is M1's first measurable win.

## Four kinds of statement, never mixed

| Class | Meaning | Where it comes from |
|---|---|---|
| `observed` | Seen directly. | A stored row of run #12 (the page after scripts ran), or a fact read from one plain `GET` of the page (server-rendered markup, no scripts run, nothing clicked). |
| `api_derived` | Stated by Greenhouse's public job-board API. | `boards-api.greenhouse.io/v1/boards/chainguard/jobs/4722145006?questions=true`. The API describes the data model, not necessarily what is rendered. |
| `inferred` | Concluded by reasoning. Plausible, **not confirmed**. | Each carries its basis. Tests refuse to let an inferred or unknown fact be marked confirmed or verified. |
| `unknown` | Not established by any evidence we hold. | Recorded so it is not mistaken for a finding. An unknown never carries a value. |

The golden is a **semantic target**, not a record of run #12. Run #12's rows are the *observed baseline* and contain known
extraction errors; the golden lists them under `observed_extraction_errors_in_run12`.

## The evidence

| File | Class | Note |
|---|---|---|
| `run12.agent_run.json` | observed | `agent_runs` row 12, exported verbatim. Succeeded / complete, 3 s. |
| `run12.application_questions.json` | observed | The 20 rows run #12 stored, verbatim. |
| `greenhouse-api-schema.json` | api_derived | The public API response. **One change:** the `content` key (the job description prose, 12,238 characters) was removed; the original's hash is recorded. |
| `static-page-observations.json` | observed | Facts from one plain `GET`. The HTML itself is not stored. |

## Discrepancies: the API versus the rendered form

Computed by the tests from the evidence, so this table cannot drift from it.

| Item | API | Rendered / observed | Status |
|---|---|---|---|
| **Race** | `compliance` lists `Race` (8 options) | **No control.** The page's embedded data contains the question, but there is no label reading "Race", no element with id `race`, and run #12 extracted none. | Absence **observed**. Why it is absent is **unverified**. It is **not** asserted to be conditional, a form variant, or a rendering rule. |
| Hispanic/Latino | Not a question (compliance lists Race, Gender, VeteranStatus) | Rendered as "Are you Hispanic/Latino?" (`hispanic_ethnicity`, combobox, optional) | Observed. How it relates to the API's Race is **unknown**. |
| Country | No country question | A labelled combobox `Country*` (`country`) plus one unlabeled `aria-hidden`, `tabindex=-1`, `required` input beside it | Both **observed**. That they are one logical control, and that the hidden input is run #12's row 4, is **inferred** (matched by traits, not identity). |
| Resume/CV text, Cover Letter text | `resume_text`, `cover_letter_text` textareas (optional) | "Enter manually" buttons and visually hidden labels for those ids; **no element with either id, no textarea at all** | Absence **observed**. "The button reveals the textarea" is **inferred**; no click was made. |
| File labels | "Resume/CV", "Cover Letter" | Accessible labels are both "Attach" (visually hidden) | Observed. The association is by DOM id equal to API name. |
| Dropdown types | single/multi select | `input type=text`, `role=combobox`, `aria-haspopup`, `aria-autocomplete=list`. Run #12 recorded `text` with no options. | Observed. Option lists are in the API only, not the DOM. |
| Phone | `input_text` | `type=tel` | Observed. |
| GDPR consent | `data_compliance: gdpr`, no consent required | Not examined | **Unknown**. |

Other unknowns (full list in the golden): the Country combobox's default and options; whether more fields appear after an
answer is chosen (none was entered); whether the invisible reCAPTCHA gates submission (only its configuration entry was seen);
what answer format the free-text sponsorship question accepts.

### Run #12's extraction errors (observed baseline versus golden)

| Error | Rows | Class |
|---|---|---|
| One control reported as two (the Country combobox plus a blank required row) | 4 | inferred |
| File inputs labelled `Attach` instead of Resume/CV and Cover Letter | 6, 7 | observed |
| Combobox controls recorded as `text`, with no options | 3, 11-19 | observed |
| The required marker (`*`) left in the label | 0-3, 5 | observed |
| No sensitive flag exists, so demographic and EEOC questions are indistinguishable except by section name | 11-19 | observed |

What run #12 got right: all nine core questions were found, every required flag matched, and every label was the page's own.

## Adversarial fixtures

All synthetic. Names, text and ids are invented; only the *structure* of the Greenhouse-style and aggregator fixtures follows
observed facts. `.example.test` is the only host used.

| Fixture | Exercises |
|---|---|
| `greenhouse-style-form` | The observed Greenhouse patterns together: generic "Attach" labels under headings, a Country combobox with its hidden validation input, 9 demographic comboboxes in two sections, "Enter manually" buttons with no textarea, and inert embedded data naming a "Race" question that is never rendered. |
| `combobox-nested-search` | An ARIA combobox with its listbox in the DOM; a button dropdown with a **nested search box** (chrome, not a question); a combobox with a hidden mirror input; a native select. One logical control each. |
| `labels-vs-headings` | Generic accessible labels ("Upload", "Choose file") under real headings; a placeholder-only input; a `legend`-named radio group; an `aria-labelledby` checkbox group; an input with only nearby text (weakest evidence); a real label that must beat the heading above it. |
| `conditional-hidden-fields` | A field hidden with a **declared** trigger (`aria-controls`); one hidden by CSS with **no** declared trigger; one created by script only after a change event (not in the DOM at load). |
| `sensitive-demographics` | Five genuinely sensitive questions, and look-alikes that are **not**: work authorization, "veterans' hiring programs", "people with disabilities", salary. Word-matching must not decide. |
| `multi-step` | A visible step 1, a hidden-in-DOM step 2, and a step 3 that exists only after "Next" is pressed. |
| `auth-gate-aggregator` | An aggregator listing: fragment-only and self-referencing Apply links, an Apply **button** opening a hidden sign-in dialog, and only the employer's homepage in structured data. |
| `apply-button-nonget` | An Apply button whose handler `POST`s, an "Apply" button inside a POST form, and a `role=button` that only runs script. `window.__trap` counts activations. |

## What M1 must do (and what it need not)

See `m1-criteria.json` for the exact metrics. In short: **recall, label, required, kind, logical-control integrity, extra rows,
sensitive recall and false positives, label-evidence honesty, option state, non-form outcomes**, each with a threshold;
**safety** (no clicks, no data entered, no non-GET reaching the server, page not modified, no egress, no values recorded,
existing protections intact) where any failure fails M1; and **determinism, speed and backward compatibility**.

**Not required:** an exact field count (run #12's 20 includes known errors), reproducing run #12's rows or ordering, access to
the live page, reading options that need a click, recognising conditional or multi-step content as such (M2), or detecting a
sign-in gate (M3). M1 only must not **invent** hidden content or report a hidden dialog's inputs as application fields.

Milestone-specific expectations live under `later` in each expected file (M2 coverage and conditional content, M3 the sign-in
gate, M4 contained interaction, M7 steps). Stop-reason names marked `proposed_` are proposals, not an existing vocabulary.

## Rules for extending the corpus

- Fixtures are **append-only**. Changing one changes its hash; update `corpus.json` in the same reviewed commit.
- Evidence is **never edited**. A new capture is a new directory with its own manifest.
- Synthetic fixtures must be offline: no external URLs (other than `.example.test`, and inert JSON blocks), no `<script src>`, no
  timers, no randomness, no clock. Scripts may only react to user events. A script that uses `fetch` must be declared in
  `allowed_script_calls`.
- Every fixture declares **traits** (selectors or expressions the tests verify in Chromium), so a fixture cannot silently stop
  exercising what it claims to.
- Keep the four evidence classes apart. If you cannot say how you know something, it is `unknown`.

## M1 status: generic read-only discovery

`src/discovery/` is scored against this corpus by `test/discovery-corpus.test.js` using the criteria in `m1-criteria.json`
(`test/support/discovery-score.js` turns them into metrics; its own tests prove each metric trips on the mutation it is about).

| Criterion | Existing extractor (baseline) | Generic discovery |
|---|---|---|
| F1 recall / per-fixture floor | 0.96 / 0.75 | 1.00 / 1.00 |
| F2 label accuracy | 0.84 | 1.00 |
| F3 required accuracy | 1.00 | 1.00 |
| F4 kind accuracy / dropdowns are `select` | 0.65 / 0.37 | 1.00 / 1.00 |
| F5 logical-control integrity (violations) | 6 | 0 |
| F6 extra rows / worst fixture | 0.15 / 3 | 0.00 / 0 |
| F7 sensitive recall / F8 false positives | 0 / 0 | 1.00 / 0 |
| F9 label-evidence honesty (violations) | 4 | 0 |
| F10 options state | 0.18 | 1.00 |

(The baseline is the unchanged Greenhouse extractor run through the same harness on the same form fixtures.)

M1 also corrected two points in this corpus that the work exposed, both in `expected/`: the radio group in `labels-vs-headings` is
named `auth` (the expectation said `work-auth`, and only matched through the label fallback), and the button dropdown's `required` in
`combobox-nested-search` is `null`, not `false`: a custom widget with no required signal is unknown, which is the existing rule. The
M0 suite now also checks that every expected DOM id exists in its fixture.

**What the corpus cannot show (found while integrating).** The classifier (`src/page-kind.js`) calls a page a form only on a strong
application signal, so three of the six form fixtures (`combobox-nested-search`, `conditional-hidden-fields`,
`sensitive-demographics`) are read by discovery but never reach it through `inspect()`; the pipeline tests document this as a known gap and
prove the flag does not change those pages.
