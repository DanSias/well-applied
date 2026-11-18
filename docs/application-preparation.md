# Application preparation

Well Applied can queue the **application-preparer** (`workers/application-preparer/`) to open an employer's Lever or
Greenhouse form, fill approved applicant data, attach the tailored resume and report what a person still has to do. **It
never submits an application**, and nothing it reports means one was submitted: sending an application is a separate step a
person does themselves, which Well Applied only records afterwards (`Submission`).

Preparation is available from the **Opportunity workspace** (the "Application preparation" section and its drawer). This
document covers the whole feature: setup, dispatch, the frozen inputs, the worker protocol, result ingestion, the review
evidence and its contract, the UI, and the security boundaries. The worker itself is documented in
[`workers/application-preparer/README.md`](../workers/application-preparer/README.md) ("Laravel agent").

It reuses the inspection infrastructure (see `docs/application-inspector.md`) and does not add a second workflow system.
The **browser inspector is a separate worker and stays read-only**: it has its own token and routes, never receives
applicant data or a resume, and has no fill or upload capability.

## Setup

1. **Migrate.** The feature needs one migration (`agent_runs.input_summary`): `php artisan migrate`.
2. **Two different tokens** in `.env` (see `.env.example`): `PREPARER_WORKER_TOKEN` for this worker and, as before,
   `BROWSER_WORKER_TOKEN` for the inspector. They must differ (the preparer's middleware refuses an equal pair), and an unset
   token fails closed. Optionally `PREPARER_WORKER_CLAIM_TTL` (the lease, default 120 s).
3. **Start the preparer agent** where Chromium is installed (Node >= 22.18, `npx playwright install chromium`):
   `cd workers/application-preparer && CAREER_TOOLKIT_URL=http://localhost:8000 PREPARER_WORKER_TOKEN=... node src/agent.ts`.
   All its settings, the lease and heartbeat behaviour and the delivery guarantees are in the worker README.
4. **Keep the app on localhost** (see "Security assumptions").
5. **Try it on a local fixture, with no live site:** `workers/application-preparer/scripts/e2e-laravel-ui.sh` starts a
   disposable database and storage, a local fixture form, the app on `127.0.0.1:8765` and the agent, then you click through
   the real UI (`FIXTURE_PAGE=/lever-attach-ready.html FIXTURE_ATS=lever` for a verified-resume "Ready for review";
   `/lever-attach-input.html` for "Needs your input"; `AGENT=0` to try Cancel). `scripts/e2e-laravel-fixture.sh` does one
   headless run and prints what Laravel recorded. Both use `PREPARER_ALLOW_LOCAL_FIXTURE`, which accepts a local
   `http://127.0.0.1` form and is for local fixtures only (off by default).

## The pieces, and what each one means

|                                                                                                                          | Meaning                                                                                                                                                                                                      |
| ------------------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `applications` / `Application`                                                                                           | The job's one operational record (unique per `job_posting_id`), shared with inspection. Preparation does not change its status.                                                                              |
| `WorkflowRun` (`application_preparation`) → `WorkflowStep` (`prepare_application`) → `AgentRun` (`application_preparer`) | One preparation attempt. A retry is always a brand-new triple: nothing is reopened or rerun.                                                                                                                 |
| `AgentRun.status`                                                                                                        | **Execution**: queued, running, succeeded, failed, cancelled. Succeeded means the worker finished and delivered a report.                                                                                    |
| `AgentRun.result_summary['outcome']` (`PreparationOutcome`)                                                              | **What the report says**: `ready_for_review`, `input_required`, `blocked`, `unsupported`, `safety_failure`. Kept apart from execution status: a delivered `input_required` report is a successful execution. |
| `AgentRun.input_summary`                                                                                                 | What the attempt was given, **never values**: resume variant id, SHA-256 and size, the hash of the input snapshot, the applicant _keys_ sent and the keys left out with a reason.                            |
| `Submission`                                                                                                             | Untouched. Only `RecordSubmission` (a person saying they applied) creates one. Nothing in preparation can.                                                                                                   |

No new tables. The only migration is the nullable `agent_runs.input_summary` JSON column.

## Dispatch

`App\Support\ApplicationPreparation\DispatchApplicationPreparation`, reached by `POST /jobs/{job}/preparations` (a pursued
job of the current profile; optional `resume_variant_id`) and read back at `GET /applications/{application}/preparation`.

1. The job needs a plain-https Lever or Greenhouse URL (the preparer's own host list). Otherwise it is refused up front.
2. The resume is the explicitly chosen variant (it must belong to the job and the profile), else the job's **preferred
   (final)** one, else its **current** one. It is rendered and validated through the same path as the download route
   (`PreparationResumePdf`); an over-length, unverifiable or unrenderable resume stops the dispatch, never falling back to
   another one.
3. A lapsed claim is settled (failed, never rerun), then, in a transaction, an already-active preparation is returned
   instead of starting a second. Otherwise the three rows are created and the inputs are frozen.

### The frozen inputs (`PreparationArtifacts`)

Private disk (`storage/app/private`), `application-preparations/{agent_run_id}/`, write-once, SHA-256 checked on every read:

- `input.json`: the exact applicant values, the application URL and the resume descriptor.
- `resume-{sha256}.pdf`: the resume bytes. Deliberately not `SubmittedResumeSnapshot`: nothing here implies a submission.
- then, as the worker delivers: `screenshots/{phase-NNofMM-yNNNNN}.png` and the sanitised `report.json`.

The applicant values come only from the `CareerProfile` (`BuildPreparationApplicant`): nothing is invented. LinkedIn and
current company have no source and are never sent. A name is split into first/last only when it is exactly two plain
words (`Mary Jo Smith`, `Smith, John`, `Dr. Ann Lee`, a single word and initials stay as a full name only, and the form's
separate name fields are left for a person). A malformed e-mail, phone or URL is left out, with the reason.

Later changes to the profile or the resume never alter an attempt: it keeps what it was given.

## Worker protocol (`/api/worker/preparer/*`)

A different trust class from the inspector, so a different token (`PREPARER_WORKER_TOKEN`), route group, middleware
(`AuthenticatePreparerWorker`) and lease (`PREPARER_WORKER_CLAIM_TTL`, default 120 s). The two tokens must differ, and an
unset token fails closed. The inspector's token opens none of this; the preparer's opens none of the inspector's. The
inspector's result endpoint no longer finds a preparation run (404), and the nav-bar worker badge counts only inspector
heartbeats.

|                                           |                                                                                                                                                                                                                                            |
| ----------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `POST /agent-runs/claim`                  | Claims the oldest queued preparation (atomic conditional update). Returns the run id, URL, lease and the resume's hash and size; **no applicant values**.                                                                                  |
| `GET /agent-runs/{id}/input`, `/resume`   | The frozen input and resume, for the claimant of a live run only. Served only if they still match their recorded hashes.                                                                                                                   |
| `POST /agent-runs/{id}/heartbeat`         | Renews the lease (and records an allow-listed `phase`). A lapsed or foreign claim changes nothing.                                                                                                                                         |
| `PUT /agent-runs/{id}/screenshots/{name}` | One screenshot as a raw PNG body. The name must match the worker's own pattern (also enforced in the route); PNG-only, ≤ 3 MB, ≤ 60 per attempt, write-once (identical re-upload = duplicate, a different file under the same name = 409). |
| `POST /agent-runs/{id}/result`            | The delivery (validated by `ReportPreparationResultRequest`, ≤ 2 MB).                                                                                                                                                                      |

Every run-scoped call proves the caller is that run's claimant (`X-Worker-Identity` must equal the recorded
`worker_identity`) via `PreparerRunAccess`, and reads/writes are refused unless the lease is live. A lapsed claim is
settled first, so a worker that stopped heartbeating is refused rather than trusted.

**Leases and recovery.** `ClaimNextPreparationRun::recoverExpired()` marks a lapsed Running run **failed
(`claim_timeout`)** with its step and workflow. It is never requeued or retried: whether the form was partly filled is
unknown, so only a person starting a new attempt can try again. It runs lazily at dispatch, claim and every worker call.

**Results.** `PersistPreparationResult` uses the conditional `UPDATE ... WHERE status = running AND worker_identity = ?
AND claim_expires_at >= now`, so a stale worker cannot overwrite a completed, failed or superseded attempt (HTTP 409). The
same delivery again is a `duplicate` (nothing is written); a different delivery for a finished run is refused. The report
file and the state change commit together or not at all.

## What a result can and cannot say

The request validation keeps only whitelisted, bounded fields (no raw DOM, controls, option lists, selected values or
anything else a report happens to carry). The report must carry `submission.assertion = worker_did_not_submit`; any other
assertion is a 422. The stored outcome is **derived**, never taken on trust (`SanitizePreparationReport`):

- any submission-like safety event (`blocked_mutating_request`, `submit_event_blocked`, `unexpected_navigation`,
  `suspected_submission`), a suspected submission or a blocked attempt makes it `safety_failure`, whatever the worker said;
- `ready_for_review` is downgraded to `blocked` if the resume the worker reports is not the one it was given, or if the
  worker did not verify the upload;
- the screenshot manifest is cross-checked against what was actually uploaded (`screenshots_missing`,
  `screenshot_hash_mismatch`);
- query strings are stripped from every URL-ish string, and no value is kept for sensitive fields.

`result_summary` is small and holds no applicant value; the full sanitized report is the private `report.json`. A
`safety_failure` report is preserved and shown, and is never `reviewable`. **`reviewable` is the most this slice says: a
person may look at it. Nothing here means ready to send.** Preparation never creates a `Submission` or a submission event
and never changes the job.

## Security assumptions

- **Localhost-only.** Well Applied is local-first and has no login. The web routes above, including the status endpoint,
  are exactly as private as the network the app is bound to. Bind it to localhost (the default dev server), or treat
  everything on that network as having access to applicant data. Before exposing the app on a LAN (for example for an
  AI-box worker), add UI authentication or put it behind something that has it.
- The worker endpoints are token-gated, but the token is a shared secret over plain HTTP: fine on localhost, a concern
  on a shared network.
- Every artifact is on the private `local` disk, never a public path (the private disk's own `serve` route answers only
  signed URLs). The app does serve two things from it, through scoped controller routes only (see "The review"): an
  attempt's review and its screenshots. **Those routes carry applicant-adjacent data and must not be exposed on a network
  without authentication.**
- **Screenshots are not redacted.** They are raw page captures and show whatever was on the form, including values typed
  into it and anything the page displayed. The label-based redaction below applies to the structured review only. Treat
  every screenshot as sensitive.
- The applicant values exist in plaintext in `input.json` (private disk) and are sent to the worker. Deleting a run
  directory deletes them; there is no retention policy yet.

## Cancelling a queued attempt

`POST /preparations/{workflowRun}/cancel` (`CancelApplicationPreparation`) withdraws an attempt **no worker has claimed**.
It is the same kind of atomic conditional update the claim is (`... WHERE status = 'queued'`), so exactly one of a claim and a
cancel can win the row: after a cancel the claim finds nothing, and after a claim the cancel changes nothing (409,
`not_cancellable`). Repeating a cancel is safe (`already_cancelled`). The rows are kept as `cancelled` (execution status
`AgentRunStatus::Cancelled`), together with the hashes and key names in `input_summary`; the frozen applicant values and resume
of a cancelled attempt were never given to anyone and are removed. A running attempt cannot be cancelled. Cancelling creates
no `Submission`, and a person may start a new attempt afterwards.

## The worker side

The agent that speaks this protocol is `workers/application-preparer/src/agent.ts` (see that README, "Laravel agent"): a
20 s heartbeat against the 120 s lease, a 10 minute execution limit, evidence uploaded before the result, idempotent
retries of transport failures only, and no browser reruns. For a local end-to-end check with a disposable database and a
local fixture form, run `workers/application-preparer/scripts/e2e-laravel-fixture.sh` (it enables the
`PREPARER_ALLOW_LOCAL_FIXTURE` opt-in, which is for local fixtures only).

## The Opportunity workspace

The workspace has an **Application preparation** section, separate from "Application inspection" (reads the form) and the
recorded Applications (what a person says they sent). Its drawer lets a person:

- see the job, the application form's host and path, and the tailored resume that will be used (the final one, else the
  current one; any eligible version may be chosen), with a note that the PDF is copied when the attempt starts;
- start an attempt, or be told why not (no resume, no URL, an unsupported site, a PDF that would not render). A resume is
  never created or chosen for them;
- watch it: **Queued** (with a Cancel button and the preparation worker's presence, shown as its own fact), then **Running**
  with the real reported phase and elapsed time. Polling runs only while an attempt is queued or running;
- cancel a **queued** attempt (a running one cannot be cancelled);
- read the result (below), look at earlier attempts one at a time, and **explicitly** start a new attempt. A failed or
  safety-stopped attempt is never retried automatically.

Execution status and outcome are shown apart: a delivered "Needs your input" or "Blocked" report is a successful run; a
"Stopped for safety" report is never presented as ready; "Check the evidence" marks a report whose own evidence did not hold
together (for example a different resume hash). No label says submitted, sent or applied.

## The review

`GET /preparation-attempts/{agentRun}` returns one attempt's review (built from its private report), and
`GET /preparation-attempts/{agentRun}/screenshots/{name}` serves one screenshot as an inline PNG (`private, no-store`). The
attempt must be a preparation of the current profile's job (otherwise 404), a screenshot name must match the worker's strict
pattern and is read only from that attempt's own directory, and no path is ever taken from the request. The review is
fetched when the drawer shows it; it is not in page props, the polling state or any list.

The drawer shows the outcome, an explicit "Not submitted" notice, counts, the resume (variant, hash, upload evidence and its
verification), the questions grouped as needs-your-answer / needs-a-person / needs-a-check / errors / verified / optional,
safety events (failures prominently, routine blocked background requests as a calm note), warnings and reasons, and an ordered
screenshot gallery. A question the page left unlabelled stays unlabelled.

### The evidence contract

Each logical question may carry one of three kinds of value, never mixed and never inferred, each with its provenance:

| Kind                                | Meaning                                                                                                                                                          | Allowed when                                                                                                                                                                |
| ----------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Entered** (`entered`)             | What the worker typed and **read back** from the control                                                                                                         | The question is verified, came from the profile (`applicant_fact`, with the fact's key) or an approved answer, has exactly one control, and is not a file or checkbox group |
| **Intended** (`intended`)           | What the profile would put there but was **not entered** (a typeahead a person must complete, or a value that could not be confirmed); also the resume file name | Profile- or answer-sourced and not verified; or the chosen resume                                                                                                           |
| **Page selected** (`page_selected`) | Options the **page itself** had already selected: a default the worker did not choose                                                                            | Provenance `none`                                                                                                                                                           |

All are bounded (500 characters, 20 selections of 200, a 300-character note) and allow-listed. A value is dropped for a
sensitive question, by the worker's own flag **and** by the server's label check (gender, race, ethnicity, Hispanic/Latino,
veteran, disability, orientation, religion, pronouns, birth date, passport, passwords, tokens and similar). Option lists,
other selected values, controls and the raw page are never stored. A value outside its provenance is discarded, and an
over-long or malformed one rejects the whole delivery. Values appear only in the review endpoint.

**These values are observations made while the form was being prepared**, not a verified final application state: the page's
final state, and anything a person changes afterwards, is not captured (the review says so). Do not read them as "what will be
sent".

## Known limitations and follow-ups

Not implemented (genuinely remaining): authentication for the web UI (the app is local-first, so localhost only);
capturing the form's final state before any submission feature; editing answers, answer sourcing and approved-answer reuse;
a person's review/approval step and any submission or browser takeover; cancelling a running attempt; a per-job cooldown;
a retention and delete policy for attempt directories (screenshots, reports and `input.json` stay until removed); showing
preparation state in the Opportunities list; the Phase 0 Greenhouse accuracy issues (phone Country detection, generic
"Attach" labels); and a Greenhouse S3-upload success through the local fixtures (those exercise Lever's file-hash
verification; the Greenhouse upload path is covered by the engine's own tests and the earlier supervised runs).
