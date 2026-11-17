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
npm test            # 70 tests; local fixtures + a local server only, no live site
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
`unresolved_uncertain`, `error`, with `expected`/`actual` (never recorded for
demographic/EEO fields). Reports keep origin + path only, never query strings.

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
   a named `UploadException` (method + exact origin + anchored path + written
   justification; each needs a test). Service workers are blocked so they
   cannot route around this. There are currently **no** exceptions.
4. **Navigation.** After the initial load (and its redirects) any main-frame
   navigation is aborted and popups are closed.
5. **Outcome.** Any blocked mutation, blocked submit, navigation or
   confirmation-looking page makes the run `safety_failure`. Third-party
   (non-ATS-host) blocked requests such as analytics beacons are recorded but
   are not failures.
6. **Fail closed.** Uncertain label matches are not filled; checkboxes,
   free-text questions and demographic questions are never filled; multistep,
   captcha and login stop the run; the resume must be verified by SHA-256 of
   the browser's own copy (a widget that empties the input is `blocked`).
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
- The resume-parse request many forms make on file selection may be a POST that
  the guard will block (a `safety_failure`). That is expected until a
  supervised run shows the real request and a narrow exception is added.
- Fixtures are synthetic approximations of Lever and Greenhouse markup, not
  captures; real forms will differ (see the supervised test below).

## Layout

```
src/contracts.ts     input/report types (hand-synced vocabulary, like inspector's result.js)
src/url-safety.ts    https + supported-host policy
src/guard*.ts        submission safeguards
src/discover.ts      read-only form discovery (tags controls with data-wa-id)
src/classify.ts      deterministic field -> applicant-fact mapping
src/fill.ts          the whole write surface
src/verify.ts        read-back checks (resume hash, values, confirmation text)
src/evidence.ts      screenshots + report (run dir is private, gitignored)
src/adapters/        ATS knowledge: where the form is, upload exceptions
src/prepare.ts       the engine;  src/cli.ts  the entry point
```

`runs/`, `profiles/` and `*.profile/` are gitignored (applicant data).

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
6. Press Enter in the terminal (or close the window) to end.
7. Inspect `runs/<runId>/report.json`, `01-before.png`, `02-after-fill.png`,
   `03-end-of-hold.png`. Record per posting: outcome and reasons;
   `safetyEvents` (**every `blocked_mutating_request` URL is a candidate upload
   exception, review them, do not add one blindly**); `resume.verification`;
   `controls`; `blockers`; unrecognised or mis-labelled fields; anything the
   page did that the fixtures did not.
8. Confirm nothing was submitted: no confirmation page, no email at the
   address used, the posting shows no application.
9. Delete the run directory if it holds anything real (`rm -rf runs/<runId>`).
10. Stop and report on any `safety_failure` involving a submit, a suspected
    submission, or behavior you cannot explain. Do not retry around it.
