# Application Preparer (Phase 0)

Opens an ATS application form (Lever or Greenhouse), fills **approved**
applicant data, attaches a resume PDF, verifies what it did, writes a
structured report plus screenshots, and **stops before submission**. A human
reviews the form; nothing here ever submits.

Standalone, like `workers/browser-inspector/`: its own `package.json`,
lockfile and `.gitignore`; no Laravel, database, queue, token or
`node_modules` dependency; the inspector is untouched. Phase 0 is a local CLI
only: no HTTP API, no polling, no migration, no Docker.

## Run it

```sh
cd workers/application-preparer
npm install
npx playwright install chromium     # only if this Playwright version's Chromium is missing
node src/cli.ts <input.json> [--hold] [--headless] [--runs-dir DIR]
npm test            # local fixtures + a local server only, no live site (about 3 minutes: real Chromium)
npm run typecheck
```

Requires Node >= 22.18 (native TypeScript type stripping, so there is no
build step; hence erasable-syntax-only TS: no enums, no constructor parameter
properties). Playwright is pinned exactly (`1.63.0`), the same version the
inspector's Docker image pins; keep them in step.

Exit codes: `0` ready_for_review, `3` needs a human / input / unsupported,
`4` safety_failure, `1` failed or bad usage.

## Input (`PrepareInput`, see `src/contracts.ts`)

`applicationUrl` (https, Lever/Greenhouse hosts only), `resumePdfPath` (a real
PDF; relative paths resolve against the input file), `applicant` (approved
facts), optional `answers` (approved answers to select/radio questions, keyed
by normalised label, applied only when exactly equal to an option).
`fixtures/applicant.synthetic.json` and `fixtures/sample-resume.pdf` are
synthetic and are what every automated test uses.

## Report (`runs/<runId>/report.json`)

Outcomes, in priority order:

| outcome            | meaning                                                                                                                |
| ------------------ | ---------------------------------------------------------------------------------------------------------------------- |
| `safety_failure`   | something attempted or looked like submission / an unexpected side effect. Never ready.                                |
| `unsupported`      | host not supported, or no form fields found                                                                            |
| `blocked`          | a human must act first: visible captcha, login field, Next/Continue steps, no resume field, resume upload not verified |
| `input_required`   | form understood and filled as far as possible, but a **required** answer is missing/uncertain, or a fill failed        |
| `ready_for_review` | every required field verified. Optional fields may be unanswered; see `summary.unansweredOptional`                     |

Per field: `filled_verified`, `unanswered_optional`, `unresolved_required`,
`unresolved_uncertain`, `unverified`, `manual_action_required`, `error`, with
`expected`/`actual` (never recorded for demographic/EEO fields). The four
non-success situations are kept apart: a **missing approved answer**
(`unanswered_optional` / `unresolved_required`), an **intentionally skipped
interactive control** (`manual_action_required`), a **value written but not
confirmed** (`unverified`), and an **execution failure or changed value**
(`error`). Reports keep origin + path only, never
query strings. Report schema version 2.

- **`unverified`**: a value was entered but what a _person sees_ could not be
  confirmed: the control is not visible, or it is a composite/autocomplete
  widget whose committed value could not be established. A required
  `unverified` field makes the run `input_required`; an optional one is listed
  in `summary.unverified` and `warnings` and does not block.
- **`manual_action_required`**: a typeahead/combobox where a suggestion must
  be chosen. The worker has no click, and such widgets discard text that was
  not selected, so it does **not type into it**. The intended applicant value
  stays in `expected`; `note` says a suggestion must be selected by a person;
  other fields are still filled. A required one makes the run
  `input_required`; an optional one only adds a warning (and
  `summary.manualAction`). Detection happens _before_ filling, from structure
  only: ARIA combobox / `aria-autocomplete=list|both` / `aria-haspopup=listbox`,
  an `aria-controls`/`aria-owns` pointing at a listbox, a hidden
  selected-value input (`selected…`, `…id`, `…place`), or a related hidden input
  beside a suggestion container. **Not** triggers: `autocomplete="off"` or any
  browser autofill token, a native `list=` datalist (free text works), class
  names, `aria-expanded` alone, or a hidden input that merely overlaps the name
  (`email` / `email_confirmation`).
- **Questions** (`questions[]`, `questionSummary`): the form as a person sees it. A
  checkbox group (shared name, or one fieldset / `role=group`) is **one** question with
  its options, however many controls render it, so a 33-language list is one entry, not 33. Each question carries `label` (+ `labelSource`, `labelConfidence`:
  `structural` | `inferred` | `unresolved`), `required`, `kind`, `options`, `selected`
  (the page's, or the worker's verified read-back), `provenance` (`applicant_fact` |
  `approved_answer` | `chosen_resume` | `none`), `status` and a `review` hint
  (`complete`, `optional_skipped`, `needs_answer`, `needs_person`, `needs_check`,
  `failed`). When the page gives no question label the label is `null`, never taken from
  an option; `name` is then the only handle. A label taken from a nearby question
  container is marked `inferred_proximity`. Checkbox groups are still never selected.
  Outcome `reasons` are listed per question. `fields[]` stays as per-control evidence.
- **Field identity**: `id` is per DOM element and never positional (an element keeps its id
  when others are inserted, removed or reordered; a removed element's id is never reused;
  a cloned duplicate gets its own). `key` + `occurrence` + `ambiguous` give a logical
  identity (kind, input type, name, id attribute, normalised label) for re-finding a field
  a framework re-rendered. The resume is tracked this way across its own upload
  (`resume.identity.tracking`: `same_element`, `rematched_by_key`, `ambiguous`,
  `removed`); if its input disappears the result is kept on the resume
  (`presence: "removed_after_upload"`) and never blamed on another control.
- **Standard-field matching** uses exact labels, observed `name` attributes, input types
  and the standardised HTML `autocomplete` purpose tokens (`given-name`, `family-name`,
  `name`, `email`, `tel`, `tel-national`, `organization`; `off` and the rest are ignored).
  A short, non-question label that only _resembles_ one is `uncertain`; a question or
  sentence that merely mentions "Company" or "address" is just unmapped.
- **Controls** (`controls[]`) include hidden ones (`hidden: true`), such as a
  hidden `type=submit` that a page script clicks. They are reported, never
  activated, and never count as a Next/Continue step.
- **Resume parser** (`resume.parser`): four facts kept apart, none inferred
  from another. (1) _Bytes attached and verified_: `resume.verified` /
  `verification` (SHA-256 of the browser's own copy). (2) _Parse request
  allowed_: `parser.requestAllowed`, counted by the guard when the adapter's
  named exception lets it through. (3) _Visibly succeeded_: `parser.status:
"success"`, only ever from a visible success indicator on the page. (4)
  _Failed or unresolved_: `"failure"` (a visible failure indicator) or
  `"unknown"` (no terminal indicator within a bounded wait: still working,
  nothing visible, or conflicting indicators; neither success nor failure is
  claimed). `"not_observable"` means this ATS has no known indicator (only
  Lever's is known); `"not_attempted"` means no resume was attached or no
  request was allowed. The wait is bounded (15 s during preparation, 5 s at the
  end of a hold) and the parser never decides `outcome`. `atPreparation` keeps
  what preparation saw after the hold updates `status`.
- **Hold and final state**: `diagnostics.hold` = `{ endedBy, durationMs }`
  with `endedBy` one of `stop_file`, `enter`, `window_closed`,
  `browser_disconnected`, `callback`. `finalState` (null without a hold, or if
  the window was already closed) records what the form _displays_ when the hold
  ended, for non-sensitive fields, next to each field's unchanged
  `statusAtPreparation`: so a typeahead a person completed shows its value
  while its preparation status stays `manual_action_required`. `end_of_hold`
  screenshots are the final viewport shots.
- **Verification rule**: the user-visible control is the evidence. A hidden
  input holding the right value never verifies, and never rescues a differing
  display. Composite controls (ARIA combobox/autocomplete/haspopup/controls,
  `list=`, widget-ish class names, related hidden inputs matched by name stem)
  verify only when the displayed text and the underlying value agree. After all
  fields are filled, every one is re-read, which catches widgets that clear a
  value when focus moves on. No selector is ATS-specific.
- **Captcha** (`blockers.captcha`): `none`; `inactive_markup` (hidden,
  zero-size, off-screen, aria-hidden or declared invisible, or a **passive reCAPTCHA
  badge**: a page initialising a widget; a warning only); `challenge_visible` (a displayed
  challenge or checkbox: `blocked`); `uncertain` (displayed markup that could
  not be classified: `blocked`, and reported as uncertain, not as a confirmed
  blocker). reCAPTCHA frames are judged by their own `src`: an `anchor` with
  `size=invisible` (or inside `.grecaptcha-badge`) is the passive badge; an `anchor` with
  `size=normal`/`compact`/absent is the interactive checkbox; a `bframe` is the challenge,
  and is never excused by sitting in a badge container; a "badge" displayed far larger than
  a badge, or any unrecognised displayed frame, stays `uncertain`.
  `blockers.captchaDetails` records per element the tag, title,
  host, frame path, size parameter, size and why (no page text). Nothing is clicked, solved or suppressed.
- **Screenshots** (`screenshots[]`): viewport-sized shots at scroll positions
  that overlap by more than the measured sticky/fixed overlays, so content
  behind a header or cookie banner appears unobscured in a neighbouring shot.
  Each record has `phase`, `index`/`of`, `scrollY`, viewport and document size,
  and the `fixedOverlays` on screen. The page is never restyled and banners
  are never dismissed; only the scroll position changes (restored after). A
  coverage check warns if any row could stay hidden.

`submission.assertion: "worker_did_not_submit"` is an **execution assertion**:
this worker has no way to invoke a submit. It is not proof nothing was
submitted. Read `safetyEvents` and `submission.suspected`.

## Safety controls and their limits

Layers (`src/guard.ts`, `src/guard-init.ts`, `src/fill.ts`), each independent:

1. **No capability.** The only writes are `fillText`, `selectOption`,
   `chooseRadio`, `uploadResumeFile`, each addressed by a discovery id and
   limited to one element type. No click/press/submit exists.
   `test/no-capability.test.ts` fails if any other source file gains one.
2. **Page events.** Capture-phase `submit` is cancelled and
   `form.submit()`/`requestSubmit()` are no-ops, each reported.
3. **Network.** Everything except GET/HEAD/OPTIONS is aborted unless it matches
   a named `UploadException` (see "Network exceptions" below). Service workers
   are blocked so they cannot route around this.
4. **Navigation.** After the initial load (and its redirects) any main-frame
   navigation is aborted and popups are closed.
5. **Outcome.** Any blocked mutation, blocked submit, navigation or
   confirmation-looking page makes the run `safety_failure`. Three kinds of
   blocked request are recorded but are not failures: third-party (non-ATS
   host) requests such as analytics, one specific Cloudflare beacon
   (`blocked_bot_detection_request`), and one specific Greenhouse Snowplow
   beacon (`blocked_telemetry_request`). Everything else on the ATS host fails.
6. **Fail closed.** Uncertain label matches are not filled; checkboxes,
   free-text questions and demographic questions are never filled; multistep,
   captcha and login stop the run; the resume must be verified: by SHA-256 of
   the browser's own copy where the input remains, and where an upload step
   removes it (Greenhouse) by a 2xx response to the authorised upload request plus
   the widget showing the file name (see "Upload evidence").
7. **Hold.** With `--hold` the guard stays on: even a human clicking Submit in
   that window is blocked and reported (`phase: human_hold`). Submit manually,
   elsewhere, after review.

Limits, stated plainly:

- It is protection against accidents and misbehaving pages, not against a
  compromised Chromium or Playwright.
- An aborted main-frame navigation leaves Chromium on an error page; the form
  state is lost (fail closed). Re-run.
- Not covered: WebSocket/WebRTC messages and anything GET-based that has a side
  effect (a GET "submit" URL is only caught by the navigation lock or the
  confirmation heuristics, which are text/path heuristics).
- The Lever resume-parse exception's content-type and resource-type
  constraints are the _expected_ shape of a file upload; the first live run
  did not record them. If real Lever differs, the request stays blocked and the
  event records exactly what was seen (`[resource type, content-type, size]`).
- Fixtures are synthetic, hand-written approximations of Lever and Greenhouse
  markup, not captures; real forms will differ (see the supervised test below).
  The Lever fixture mirrors the structure seen in two supervised runs (reports,
  screenshots and one sanitized DOM snapshot that is deliberately **not**
  committed) and says, in its header, which parts are observed, which are
  approximations (e.g. the clear-on-blur script is inferred from the observed
  empty field) and which are invented (the custom-questions block).

## Network exceptions

Two, each granted only by its own adapter and neither inherited by the other.
First, Lever's, only for `https://jobs.lever.co` (not the EU host, not `http`, not
any other origin):

|             |                                                                                                                                                                     |
| ----------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| name        | `lever-resume-parse`                                                                                                                                                |
| request     | `POST https://jobs.lever.co/parseResume`, pathname exactly `/parseResume` (case-sensitive, no trailing slash), no query string, no fragment, no credentials         |
| type        | resource type `xhr` or `fetch` (a form-post navigation is `document` and is refused); `Content-Type` `multipart/form-data`; from the main frame; body at most 12 MB |
| when        | only while the worker itself is attaching the resume (`openUploadWindow`); at most once per run                                                                     |
| recorded as | `allowed_exception_used` with the redacted URL and `[resource type, content-type, bytes]`; never the body                                                           |
| never       | an application submission endpoint, any other POST, or anything outside the above                                                                                   |

### Greenhouse resume upload (`greenhouse-resume-upload`)

Observed in the first Greenhouse run: the widget XHR-POSTs multipart form data to
`https://grnhse-prod-jben-us-east-1.s3.amazonaws.com/`. Greenhouse's public client code
shows how the page gets that destination: it `GET`s
`<page>/uncacheable_attributes/presigned_fields?fields[]=resume…` and the JSON answer names
the S3 `url` and the signed form fields; the upload is then an XHR `POST` of `utf8`, the
signed fields, `key`, `authenticity_token`, `Content-Type` and finally `file`. So the
exception does **not** rest on the hostname:

|               |                                                                                                                                                                                                                                                                                                                                |
| ------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| request       | `POST https://grnhse-prod-jben-us-east-1.s3.amazonaws.com/`: exact origin, pathname exactly `/`, no query, from the main frame                                                                                                                                                                                                 |
| type          | resource type `xhr` only; `Content-Type` `multipart/form-data`; instrumented body at most 32 KB                                                                                                                                                                                                                                |
| authorised by | the destination (origin + pathname) must equal one the page's **own** presign response named earlier in this run. The guard keeps only that URL; the signed fields are never stored or reported                                                                                                                                |
| body          | exactly one file part, named `file`, last, carrying the chosen resume's **file name**; every other part's name must be on a short S3-field allowlist (`utf8`, `key`, `policy`, `acl`, `authenticity_token`, `Content-Type`, `x-amz-*`, …), none repeated, none over 4 KB, and `key` present. Reasons name fields, never values |
| when          | only during the worker's own resume attachment; at most once per run                                                                                                                                                                                                                                                           |
| who           | granted only when the page origin is `https://job-boards.greenhouse.io` or `https://boards.greenhouse.io`. Not the EU board, not employer-hosted pages, not other ATSs, not a different bucket                                                                                                                                 |

**What the guard cannot check: the file's bytes.** Chromium's request instrumentation omits
the bytes of `File` parts, so the guard sees the file part _empty_ and cannot hash the
upload. (If the bytes are ever visible they must match the resume's SHA-256 exactly.) A
page could in principle build a same-named `Blob` with other content and send it to the
authorised destination inside the one allowed window; that would only deliver data to
Greenhouse's own upload target, and is a documented residual limitation.

#### Upload evidence (`report.resume.upload`)

Selecting a file, the page sending a permitted request, the server accepting it, and the
widget showing it are four different facts, and each is reported separately. The worker
hands the browser the **bytes it hashed when it loaded the resume** (not a path that could
change), and records whether they equal the trusted SHA-256 (`artifactSha256Matches`). The
guard records the **status** of the one authorised request's response (status, status text,
`content-length`, duration or a network failure text; never a body, a header value beyond
those, or a query). A 2xx response is evidence the upload was **accepted**; it is never
evidence an application was submitted. The wait for it is bounded and event-driven (it
returns when the response arrives), not a fixed delay, and a disappearing input is never
treated as proof either way, because Greenhouse's widget removes it.

| `upload.outcome`                | meaning                                                                                                                                                            |
| ------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `accepted_filename_visible`     | 2xx to the authorised request **and** the widget shows the file name (which was not on the page before). The only verified outcome                                 |
| `accepted_confirmation_missing` | 2xx, but the widget never showed the name (or showed a different one, or the name was already on the page): not verified                                           |
| `failed`                        | the response was not 2xx (including 3xx), or the request failed at the network: not verified                                                                       |
| `unknown`                       | the request was sent but no response arrived within the bounded wait (`uploadWaitMs`, default 15 s): not verified                                                  |
| `no_upload_request`             | selected, but the page sent no permitted request: not verified                                                                                                     |
| `selection_failed`              | the file could not be handed to the page, or the bytes handed over do not hash to the trusted resume                                                               |
| `unauthorized_request`          | an upload-like request (a partial match of the exception, or multipart) was refused by the guard while the window was open; the run also fails as a safety failure |
| `not_applicable`                | the ATS has no separate upload step (Lever attaches the file to the form)                                                                                          |

`resume.verification` is `upload_accepted` only for `accepted_filename_visible` when the
input is gone, and `content_sha256` when the input remains and the upload was accepted. A
populated input alone is `input_only`, and an emptied input with the name shown but no
accepted upload is `page_text`; neither is verified. `resume.inputHeldChosenResume` (a hash
of the input right after selection) is informational only: a widget may remove its input
first, and that never decides verification.

A request that matches method, origin and path but fails another constraint is
blocked and the reason is recorded (`exception "lever-resume-parse" refused:
...`). The Cloudflare beacon (`POST /cdn-cgi/challenge-platform/h/b/jsd/oneshot/
<12 hex>/<token>/<16 hex>` on the page's own origin) stays blocked; it is only
classified differently. Any other `/cdn-cgi/` path still fails closed.

### Greenhouse analytics beacon (classified, still blocked)

Live Greenhouse boards send one Snowplow tracker request, which the guard blocks:
`POST https://c.spl.greenhouse.io/com.snowplowanalytics.snowplow/tp2` (a `fetch`, JSON body).
It is recorded as `blocked_telemetry_request`, which is **not** a safety failure, so a run is
no longer ended by it. Nothing is allowed: it is only reported as page noise instead of a
possible submission. Every part must match: HTTPS, exactly this host and pathname, `POST`, resource
type `fetch`, media type `application/json`, no credentials in the URL. Any other host,
path, method, resource type or content type, anywhere on `greenhouse.io`, remains
`blocked_mutating_request` and fails closed. The query string is never written to a report.

## Pre-selected values and file labels

- **Combobox selections.** A react-select-style control (role `combobox` / `aria-haspopup` /
  `aria-autocomplete`) is read for what it _displays_: a rendered selected-value element is a
  selection; a placeholder (including placeholder-looking text such as "Select...") or a
  dialing code drawn as decoration is not. A required, unmapped field that already shows a
  selection is reported as `preselected_by_page` (review `needs_check`, counted in
  `summary.preselectedByPage`): it is not an unanswered required question, and it is **not**
  completed by the worker or attributed to the applicant. The displayed value is neither
  chosen nor interpreted; for instance a phone dialing code is never taken as the
  applicant's country, residence or work authorisation. Sensitive questions record no value.
- **File labels.** A file control whose own label is only its button ("Attach", "Upload",
  "Dropbox"...) takes the heading from its widget container: a `role=group`/`fieldset`
  naming it, otherwise exactly one distinct heading-like element before it, and only when
  that container holds exactly one file input. Two uploads sharing a container, or two
  different candidate headings, are ambiguous and keep the original label. The label source
  is recorded (`inferred_proximity` unless the group names it), and identity keys follow the label
  consistently across re-discovery.

## Layout

```
src/contracts.ts     input/report types (hand-synced vocabulary, like inspector's result.js)
src/url-safety.ts    https + supported-host policy
src/guard*.ts        submission safeguards
src/request-policy.ts  pure request rules: UploadException matching, Cloudflare and Greenhouse telemetry beacons
src/upload-outcome.ts  pure: what a resume upload's independent facts add up to
src/discover.ts      read-only form discovery (tags controls with data-wa-id)
src/classify.ts      deterministic field -> applicant-fact mapping
src/verdict.ts       pure judgement of the user-visible control's value
src/identity.ts      tracking one logical field across DOM changes
src/questions.ts     report-layer grouping of controls into questions
src/hold.ts          how a hold ends: Enter, STOP file, window/browser close
src/parser.ts        resume-parser status from a visible indicator, bounded wait
src/fill.ts          the whole write surface
src/verify.ts        read-back checks (resume hash, values, confirmation text)
src/evidence.ts      viewport screenshots with overlap planning + report (run dir is private, gitignored)
src/adapters/        ATS knowledge: where the form is, upload exceptions
src/prepare.ts       the engine;  src/cli.ts  the entry point
```

`runs/`, `profiles/` and `*.profile/` are gitignored (applicant data).

## Running a supervised test and ending it gracefully (non-interactive)

`--hold` keeps the headed browser open for review with the guard fully active.
It ends on the first of: **Enter** in an interactive terminal, the browser
window closing, or the **STOP file** below. A supervisor with no terminal
must use the STOP file (Enter is ignored unless stdin is a terminal).

The STOP file is `runs/<runId>/STOP`, in the gitignored run directory. Its
existence is all that is checked: **its contents are never read**, so it
cannot carry an instruction. It only ends the hold. It does not authorize any
browser action, does not touch the guard, and cannot cause a submission.

Exact procedure:

1. `cd workers/application-preparer` and confirm `npm test` is green.
2. Put the input in `runs/inputs/<name>.json` and set a `runId`, so the run
   directory is predictable: `runs/<runId>/`.
3. Start it in the background, headed, with output to files:
   `node src/cli.ts runs/inputs/<name>.json --hold > runs/<runId>.stdout 2> runs/<runId>.stderr &`
4. Wait until preparation is done: `runs/<runId>.stderr` contains a `HOLD:`
   line (it prints the exact STOP path) and `runs/<runId>/report.json` exists.
   That report is the _preparation_ report (`diagnostics.hold` is `null`).
5. Review: read the report and the `before-*` / `after_fill-*` screenshots,
   and watch the window if you are at the machine. Do not click Submit (it is
   blocked and reported).
6. End the hold: `touch runs/<runId>/STOP`
7. Wait for the process to exit (`wait` on the job, or poll `pgrep -f "cli.ts runs/inputs/<name>"`).
   Chromium is closed by the worker. Exit code: 0 ready_for_review, 3 needs a
   human / input / unsupported, 4 safety_failure, 1 failed.
8. Read the final `runs/<runId>/report.json`: `diagnostics.hold.endedBy` is
   `stop_file`; `screenshots[]` now includes `end_of_hold-*` shots;
   `finalState` has the displayed values; `resume.parser` has the four resume
   facts; `safetyEvents` is the guard's complete log (preparation events first,
   hold-phase events appended, none replaced).
9. If the run directory holds anything real, delete it: `rm -rf runs/<runId>*`.

Notes: creating STOP _before_ the hold begins ends the hold the moment it
starts (preparation and the guard still run in full). Closing the window
yourself also ends the hold, but then no final capture is possible (the report
says so in `warnings`). Killing the process (SIGKILL) skips the final report and
should be a last resort.

## Supervised, non-submitting live run (acceptance criterion; needs separate authorization)

Do not run this until it has been authorized. Acceptance for Phase 0 is three
or more postings per ATS (Lever and Greenhouse) behaving as below.

1. Confirm a clean tree for this worker (`git status workers/application-preparer`),
   `npm test` green, Chromium installed.
2. Pick one real, open posting you are content to _open_ (never to apply to):
   Lever `https://jobs.lever.co/<company>/<uuid>`; Greenhouse
   `https://job-boards.greenhouse.io/<company>/jobs/<id>`.
3. Create `runs/inputs/<name>.json` (gitignored) from
   `fixtures/applicant.synthetic.json`: set `applicationUrl`, an absolute
   `resumePdfPath`, and `answers` only for questions you want exercised.
   Use synthetic data unless you have decided otherwise.
4. Run `node src/cli.ts runs/inputs/<name>.json --hold` (headed).
5. Watch. Do not click Submit (it is blocked anyway), do not solve a captcha,
   do not log in. Compare each filled field against the page by eye.
6. End the hold: press Enter in the terminal, or `touch runs/<runId>/STOP` from a
   supervisor (see the section above), or close the window.
7. Inspect `runs/<runId>/report.json`, `01-before.png`, `02-after-fill.png`,
   `03-end-of-hold.png`. Record per posting: outcome and reasons;
   `safetyEvents` (**every `blocked_mutating_request` URL is a candidate upload
   exception, review them, do not add one blindly**; note the request shape
   recorded for any refused `lever-resume-parse`); `blockers.captchaDetails`
   (which element, if any, is a visible challenge); each field's `control`
   evidence and any `unverified` field; `screenshots` coverage warnings; `resume.verification`;
   `controls`; `blockers`; unrecognised or mis-labelled fields; anything the
   page did that the fixtures did not.
8. Confirm nothing was submitted: no confirmation page, no email at the
   address used, the posting shows no application.
9. Delete the run directory if it holds anything real (`rm -rf runs/<runId>`).
10. Stop and report on any `safety_failure` involving a submit, a suspected
    submission, or behavior you cannot explain. Do not retry around it.
