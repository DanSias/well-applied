# Application tracking (backend foundation)

Well Applied keeps four things apart:

1. **Application inspection**: reading an employer's form (the `applications` table and `Application` model, with
   `ApplicationQuestion`, `WorkflowRun` and `AgentRun`). Read-only. It never means anything was submitted.
2. **Application preparation**: derived from resume and inspection data (not built yet).
3. **Submission**: a person's record that they really submitted an application (`submissions`, `Submission`).
4. **Tracking**: what happened afterwards (`submission_events`, `SubmissionEvent`).

The user-facing word for 3 and 4 is "Application"; the backend entity is `Submission`, so it never collides with the
inspection `Application`.

## What creates a submission

Only `App\Support\Submissions\RecordSubmission`, when a person says they submitted. Never an inspection, a finished
resume, a pursued job or a worker run. A submission is a user assertion, not proof the employer received it. It does not
change the job's disposition and requires no inspection record, resume or link.

- A job may have any number of submissions. Recording another while one is still *active* needs `allowAnother: true`.
- `idempotencyKey` makes a retry return the submission already recorded.
- `submitted_at` is the person's time (not in the future); `created_at` is when it was recorded.
- Only `manual` is supported; `assisted` and `automated` are reserved.

## The submitted PDF

`ResumePdfStore` is a regenerable, content-addressed cache and cannot prove what was sent. `SubmittedResumeSnapshot` keeps
the exact bytes instead, on the private `local` disk (never public) at `submissions/{id}/resume-{sha256}.pdf`, with the
SHA-256 on the submission. Files are written once and never overwritten; a read-back is checked against the hash. The bytes
come from the caller, or (with `snapshotServedPdf`) from the cached PDF for the resume, which is never rendered here: if it
is not cached there is no snapshot. A submission with no PDF, or a PDF made elsewhere, is fine. If a person submits a file
other than the one Well Applied served, only the bytes the caller supplies can prove it.

## Events and state

`submission_events` is append-only (the model refuses updates and deletes). A mistake is corrected by a
`recorded_in_error` event. There is no status column: `App\Support\Submissions\SummarizeSubmission` derives the state.

Precedence, deterministic:

1. Order by `occurred_at`, then `id`.
2. Any `recorded_in_error` makes the submission a mistaken record: not active, not confirmed, final. Nothing more can be
   recorded on it.
3. Otherwise any ending (rejected, withdrawn, offer accepted, offer declined) sets the phase to the **latest** ending, and
   the submission is no longer active. Later non-endings never reopen it. Different kinds of ending are flagged as
   conflicting.
4. Otherwise the phase is the **furthest stage reached**, not the latest event: offer received > interviewing >
   confirmed > submitted. A late confirmation never moves an interviewing application back.
5. A follow-up never changes the phase; interviews repeat and are counted.

## Not built yet

Routes, UI, readiness checklists, Gmail matching, browser-assisted submission, reminders, AI answers, a timeline. The
`email` and `automation` event sources, and the `assisted` and `automated` methods, are reserved and refused.
