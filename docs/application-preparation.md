# Application preparation (backend, Slice A)

Well Applied can queue the **application-preparer** (`workers/application-preparer/`) to open an employer's Lever or
Greenhouse form, fill approved applicant data, attach the tailored resume and report what a person still has to do. It
**never submits**. This document covers the Laravel side only: dispatch, the frozen inputs, the worker protocol, result
ingestion and the security boundaries. The worker's polling client and any UI are later slices.

It reuses the inspection infrastructure (see `docs/application-inspector.md`) and does not add a second workflow system.

## The pieces, and what each one means

|                                                                                                                          | Meaning                                                                                                                                                                                                      |
| ------------------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `applications` / `Application`                                                                                           | The job's one operational record (unique per `job_posting_id`), shared with inspection. Preparation does not change its status.                                                                              |
| `WorkflowRun` (`application_preparation`) → `WorkflowStep` (`prepare_application`) → `AgentRun` (`application_preparer`) | One preparation attempt. A retry is always a brand-new triple: nothing is reopened or rerun.                                                                                                                 |
| `AgentRun.status`                                                                                                        | **Execution**: queued, running, succeeded, failed. Succeeded means the worker finished and delivered a report.                                                                                               |
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
- later: `screenshots/{phase-NNofMM-yNNNNN}.png` and `report.json`.

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
- Every artifact is on the private `local` disk, never a public path; nothing in this slice serves an artifact over HTTP
  (the private disk's own `serve` route answers only signed URLs). Reading screenshots and reports in the UI is a later
  slice and must go through a controller, not a path.
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

## Not in this slice

Any React UI, serving screenshots/reports to the browser, per-job cooldowns, cancelling a running attempt, answer sourcing, human review and approval, session persistence, and submission.
