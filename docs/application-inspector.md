# Application Inspector

How Well Applied's first agentic capability — a read-only browser-based
inspector of external job-application forms — is architected, and what of
that architecture is actually implemented today. Companion to
`docs/domain-model.md`'s "Application", "WorkflowRun", "WorkflowStep",
"AgentRun", and "ApplicationQuestion" sections (the persisted domain
model); this document covers the pipeline, the worker trust boundary, and
the conventions worth keeping stable across future changes — the same
split `docs/job-analysis-generation.md` and its siblings already
establish for the generation pipelines.

**Implementation status.** The domain model, the browser worker (`workers/browser-inspector/`), the worker protocol and the
Opportunity drawer are implemented. The paragraphs below that describe the worker, its protocol or its authentication
were written as an approved design before the worker existed and are kept as historical context; where they differ from
the code, the code and `workers/browser-inspector/README.md` are authoritative. "Phase 1: finding the application" at the
end of this document records what changed when inspection learned to locate the actual application form.

## Purpose

Well Applied's long-term direction is to continuously help operate a
job search while the user remains in control of consequential decisions.
The first concrete step toward that is entirely passive: given an
application URL, open it in a real browser, identify the ATS, extract
the form's fields and questions, and report a structured result — never
filling, never submitting, never inventing an answer. This is
deliberately the least agentic mechanism that could plausibly work,
chosen after a disposable Playwright/Docker proof of concept confirmed
that deterministic DOM/accessibility extraction is sufficient for real
Greenhouse and Lever applications, with no LLM, no vision model, and no
browser-agent framework required for this scope.

## JobPosting vs. Application

`JobPosting` — already implemented, unchanged by this work — is **an
opportunity that exists**: the verbatim source material for a job,
candidate-independent. `Application` is **my mutable operational attempt
to evaluate or pursue that opportunity**. The two are deliberately
different tiers: a `JobPosting` may exist indefinitely with zero
`Application`s against it (most postings a user merely captures and
analyzes are never actually pursued), and — unlike everything else in
this codebase's generation pipeline — an `Application` is not an
immutable snapshot. It has a real, mutable operational lifecycle, because
that is what it actually is: the first entity in this domain that isn't
either canonical data or a frozen AI-generated artifact.

"Draft" is intentionally broad enough to mean "I am actively evaluating
or pursuing this opportunity" — including the act of inspection itself.
Inspection therefore belongs to an `Application`, not to some separate,
un-homed inspection concept: running the Inspector is itself a form of
evaluating the opportunity, so it earns a `Draft` `Application` rather
than requiring a prior, separate "have I decided to apply yet" gate.

## Inspector v1 flow (conceptual)

```
JobPosting (has a source_url)
  -> user requests inspection
  -> Application (Draft) — created or reused if one is already in progress
  -> WorkflowRun (workflow_type: application_inspection)
       -> WorkflowStep (step_key: inspect_application)
            -> AgentRun (agent_type: browser_inspector)
                 -> browser worker claims the run, opens the URL,
                    extracts fields, reports a structured result
       -> ApplicationQuestion rows persisted, in source-form order
  -> WorkflowStep succeeds -> WorkflowRun succeeds
  -> result displayed
```

Nothing in this flow fills or submits anything. A `WorkflowRun` that
cannot make full progress (e.g. an ATS that gates real questions behind
a login wall) is not a technical failure — see "Technical status vs.
inspection outcome" below.

## WorkflowRun

`WorkflowRun` represents **"accomplish this durable business process."**
It is Well Applied's own durable execution-state record for a business
process spanning potentially many steps and, eventually, pauses for
human input — the layer that sits _above_ `GenerationAttempt`, not a
replacement for it (see "GenerationAttempt remains specialized" below).
A `WorkflowRun` belongs to exactly one `Application`
(`workflow_runs.application_id`) and has many `WorkflowStep`s. Its
`workflow_type` is a plain, extensible string (`application_inspection`
today; a second workflow type is a one-line addition, never a
migration, following this codebase's established `EvidenceSource`
/`CareerFactType` enum-growth convention).

## WorkflowStep

`WorkflowStep` represents **"perform this bounded step in that
workflow."** A `WorkflowRun` accomplishes its business process by
progressing through one or more `WorkflowStep`s; the Inspector's own
workflow definition has exactly one (`inspect_application`) as of this
document. A `WorkflowStep`'s `step_key` is deliberately a plain string,
not a shared cross-workflow-type enum — a single enum mixing step
vocabulary from unrelated future workflow types would be exactly the
premature-generalization this codebase avoids elsewhere; the right
moment to introduce a real step-key abstraction is when a second
workflow type actually needs one, not before.

## AgentRun

`AgentRun` represents **one execution attempt by an external worker** —
deliberately not "a generic autonomous AI agent." For Inspector v1 the
worker in question is a narrowly-scoped browser extraction process, not
an agent that reasons about what to do next; the name is kept because
the future architecture anticipates other bounded, external,
uncertain-environment execution attempts (a future filling worker, for
instance) sharing this same shape, and because a `WorkflowStep`
represents _intent_ while an `AgentRun` represents one concrete
_attempt_ at satisfying it — the same intent/attempt split
`GenerationAttempt` already embodies for generation work, one layer
down. A `WorkflowStep` may accumulate more than one `AgentRun` over time
(the schema permits it — `agent_runs.workflow_step_id` carries no
uniqueness constraint), but v1's own orchestration never exercises that:
see "Retry semantics" below.

## GenerationAttempt remains specialized

`GenerationAttempt` is untouched by this work and stays exactly what it
already is: a small, standalone durable-execution record for one LLM
generation call (Job Analysis, Job Match, or Resume generation), with
its own proven `queued -> running -> {succeeded|failed}` lifecycle, its
own no-raw-content persistence policy, and its own polymorphic `subject`
serving three real, simultaneous subject types. `WorkflowRun` is a new,
separate concept sitting above it, not a generalization or replacement
of it — a future workflow step that happens to perform an LLM generation
would create a `GenerationAttempt` as that step's own execution record,
exactly as a step might instead create an `AgentRun`; nothing about
`GenerationAttempt` itself needed to change, or has changed, to make
that possible.

## Why WorkflowRun uses `application_id`, not a polymorphic subject

`GenerationAttempt.subject` is a genuine polymorphic `morphTo` because it
has three real, simultaneous subject types today (`JobPosting`,
`JobAnalysis`, `JobMatch`). `WorkflowRun` has exactly one real subject
type right now: `Application`. Introducing polymorphism for a
single-case relation would be pure speculative generality — the same
instinct this codebase already applies elsewhere (`GenerationAttempt`'s
own `result()` is deliberately _not_ a second polymorphic column, for
the identical reason: one real case doesn't justify the abstraction).
`workflow_runs.application_id` is therefore a plain, required, indexed
foreign key. If a second real `WorkflowRun` subject type is ever needed,
that is the moment to migrate to polymorphism — not before.

## Why there is no ApplicationEvent

Everything an `ApplicationEvent` row would record for this slice — "an
inspection started," "an inspection completed" — is already exactly what
a `WorkflowRun`/`WorkflowStep`/`AgentRun`'s own timestamps and statuses
say. A separate event table duplicating that would be pure
denormalization carrying zero new information: an indiscriminate event
log built before anything concrete needs it. `ApplicationEvent` becomes
justified the moment something happens to an `Application` that isn't
already a workflow record — a human manually changing its status, an
external email arriving — and neither exists yet.

## Well Applied owns durable state and orchestration

Every piece of state this feature needs to reason about — which
`Application` is being pursued, which `WorkflowRun`/`WorkflowStep` is in
progress, what an `AgentRun` actually found — lives in Well Applied's
own database, as the models this document describes. The browser worker
(§ below) is not, and will not become, an orchestrator: it claims one
bounded unit of work, executes it, and reports a result. It never
decides what work exists, never sequences steps, and never holds
durable state of its own beyond what one in-flight browser session
needs.

## Browser worker boundary (approved, not yet implemented)

The following describes the worker's intended trust boundary for the
implementation pass that actually builds it. **None of it exists yet.**

- **Source location**: `workers/browser-inspector/` inside this
  repository — an independently built and independently deployed Docker
  component, not a separate Git repository. One maintainer, one Git
  history, and a protocol that evolves in lockstep with the Laravel side
  outweigh the marginal isolation benefit of a second repository at this
  scale; Docker already provides the real deployment/runtime boundary.
  Should the worker ever need a genuinely independent release lifecycle
  or become reusable outside this project, extracting the directory into
  its own repository remains straightforward.
- **No Career Profile access.** The worker never receives candidate
  data, never queries it, and has no credential that could reach it.
- **No database credentials of any kind**, to Well Applied's database
  or otherwise.
- **No submission, filling, or email capability.** v1's worker has no
  `type()`/`select_answer()`/`upload()`/`submit()` primitive at all —
  not merely an instruction not to use one, an absent capability.
- **DOM/ARIA-first extraction**, proven sufficient by the disposable
  proof of concept against real Greenhouse and Lever applications; a
  visual/screenshot fallback is deferred, and adopted only against a
  concrete, demonstrated extraction failure, not preemptively.
- **No browser-local LLM.** Question extraction is fully deterministic;
  any future semantic classification of what a question _means_ happens
  server-side, in Well Applied, never inside the worker.
- **No browser-agent framework** (browser-use, Stagehand, Skyvern, or
  similar) — a small, purpose-built Playwright worker was sufficient in
  the proof of concept and would not benefit from a framework that wants
  to own its own task/orchestration loop, which would conflict directly
  with Well Applied owning `WorkflowRun`/`WorkflowStep`.
- **No vision/computer-use in v1.**
- **Greenhouse first.** Lever and Workday are explicitly out of scope
  for the first working worker; Workday in particular was found, during
  investigation, to gate real application questions behind mandatory
  account creation for at least one tested tenant — a genuine read-only
  boundary, not an implementation gap to work around.

## Approved future worker transport: HTTP pull -> claim -> result

```
AI-box worker                          Well Applied
    │  authenticated poll                   │
    ├───────────────────────────────────────►
    │                              claim pending AgentRun
    │◄───────────────────────────────────────┤
    │  Playwright inspection                 │
    │  authenticated result report           │
    ├───────────────────────────────────────►
    │                       persist result / complete AgentRun+WorkflowStep
```

Chosen over an external queue (no Redis exists anywhere in this stack,
and introducing one solely to reuse Laravel's own queue concept across a
machine boundary was explicitly rejected) and over a direct push to the
worker (which would require an inbound-listening service on the worker
machine, a strictly larger network/firewall surface for no benefit at
this scale). The worker never receives direct database access; Well
Applied remains the only writer of its own durable state.

## Approved v1 worker authentication: shared-secret bearer token

A single `BROWSER_WORKER_TOKEN`, compared with constant-time equality in
a narrowly-scoped middleware guarding only the worker's claim/result
endpoints — not Laravel Sanctum. This repository has no auth
infrastructure of any kind today; Sanctum's differentiating value
(multiple scoped tokens, database-backed per-token abilities,
self-service revocation across many identities) isn't exercised by
exactly one static worker identity calling exactly two endpoints. This
is explicitly a v1 decision, not a permanent one — upgrade to
per-worker/scoped credential infrastructure (Sanctum or equivalent) the
moment any of the following becomes real, rather than before:

- more than one worker identity
- more than one worker type
- a remote or otherwise untrusted worker
- materially broader worker capabilities than claim+report
- a need for independent credential revocation/scoping

## Worker presence

Well Applied needs to tell the user whether inspection capacity is
actually available, rather than leaving a queued inspection unexplained
if the worker happens to be offline. Piggybacked entirely on the
existing claim-poll protocol rather than a dedicated heartbeat
endpoint: a successful, authenticated `POST /api/worker/agent-runs/claim`
— whether or not it found work — already proves the worker is alive
and reachable, so `App\Http\Controllers\Worker\AgentRunClaimController`
upserts a `WorkerHeartbeat` row (`App\Support\ApplicationInspection\
RecordWorkerHeartbeat`) on every call. No separate protocol traffic, no
Redis, no WebSockets, no distributed presence infrastructure.

`WorkerHeartbeat` is a small, purpose-built table (`identity` unique,
`worker_type`, `last_seen_at`) — a presence record, not a generalized
worker registry — upserted by identity, never accumulated as history.
`App\Support\ApplicationInspection\PresentWorkerAvailability` derives
`online`/`offline` from `last_seen_at` against a threshold computed as
3x `services.browser_worker.poll_interval_seconds` (comfortably
survives one missed/slow poll without flapping): a *config-derived*
number, never a magic constant duplicated independently in the
frontend. `GET /browser-worker/status` exposes this for the nav-bar
badge and any "waiting for browser worker" message; Well Applied only
ever *observes* presence here — there is no start/stop/restart action,
and none is planned.

## Retry semantics

A failed inspection is never resumed in place. Retrying always means: a
brand new `WorkflowRun`, a brand new `WorkflowStep`, a brand new
`AgentRun` — exactly mirroring `GenerationAttempt`'s own proven "a retry
is always a new row" convention (see
`docs/job-analysis-generation.md`). Failed `WorkflowRun`s and
`WorkflowStep`s are never reopened. The schema permits more than one
`AgentRun` per `WorkflowStep` (for a possible future bounded
automatic-retry capability within one step, e.g. recovering from a
worker that claimed work and then crashed before reporting), but no
orchestration in this codebase creates a second `AgentRun` under an
already-existing `WorkflowStep` today.

## ApplicationQuestion ordering

`ApplicationQuestion.position` is an explicit, required, zero-based
integer recording the field's position in the source form's own
document order, as the extraction produced it (`0` = first field
encountered, `1` = second, and so on). This is domain information — what
order a human filling out the real form would actually encounter these
questions in — not a database implementation detail, and is therefore
never inferred from insertion order or primary-key order; it must be
populated explicitly by whatever persists an inspection result, taken
directly from the worker's own ordered extraction output.

`ApplicationQuestion.raw_label`, `.required`, and `.options` may all be
`null`. A proof of concept against a real Lever application form found
a genuine case (a 3,302-option university selector) where neither DOM
nor accessibility information formally associated a label with its
control — an honest "unresolved" is an acceptable extraction outcome,
never forced into a guess.

## Technical status vs. inspection outcome

`AgentRun.status` (`queued`/`running`/`succeeded`/`failed`) is purely
about whether the browser execution itself completed without error.
`AgentRun.inspection_outcome` (`complete`/`partial`/
`authentication_required`/`mutation_required`/`unsupported`) — populated
only when `status` is `succeeded` — is a separate, orthogonal
description of what was actually achieved. `status: succeeded` +
`inspection_outcome: authentication_required` means the browser ran
correctly and stopped exactly where it should have: at a login wall it
was never authorized to cross, not a bug. Greenhouse-only v1 will
normally only ever produce `succeeded` + `complete`; the other outcome
values exist now so the schema doesn't need to change the day Lever or
Workday support is added.

## Opportunities inspection state

The Opportunities index/detail (the product-facing label for
`JobPosting` browsing — the model itself is unchanged) shows each
opportunity's inspection state (`not_inspected` / `queued` / `running`
/ `inspected` / `failed` / `unsupported`) without a second status
column on `JobPosting`. `App\Support\ApplicationInspection\
SummarizeInspectionStates` derives it in bulk from the same
`WorkflowRun`/`AgentRun` history `PresentApplicationInspection` already
reads for the Application page — the durable execution history stays
the single source of truth. As with that page, a later failed retry
never hides a previous successful result: `state: inspected` with
`latest_attempt_failed: true` is distinct from a plain `failed`, which
only appears when no attempt has ever succeeded.

## Explicit v1 exclusions

(These describe the inspector. Application preparation, which does fill and upload, is a separate worker with its own
token and routes: see `docs/application-preparation.md`. The inspector's trust boundary is unchanged.)

Not part of this or the immediately following implementation pass:
Lever, Workday, or any generic/autonomous ATS handling; `CandidateProfile`
and its EEO/demographic sub-model; `ApplicationPreference`;
`ReusableApplicationAnswer`; `HumanInputRequest`; `ApprovalRequest`;
`ProfileFactCandidate`; `ApplicationSubmission`, `ApplicationResponse`,
and `ApplicationSubmissionAttempt`; application filling, resume/file
upload, or submission of any kind; question normalization or answer
resolution; any LLM integration, local or cloud, inside or outside the
worker; browser-use, Stagehand, Skyvern, or any browser-agent framework;
visual/computer-use capability; a generalized workflow or agent runtime;
WebSockets; Redis; a domain event bus; Laravel Sanctum; PDF-artifact
durability changes.


## Phase 1: finding the application

Most stored `job_postings.source_url` values are an aggregator's own page (Jobicy, Adzuna, Himalayas), not the employer's
application. Before Phase 1 the worker inspected whatever it was given and reported "Unsupported ATS" for anything that
was not a Greenhouse form, so a job listing and a real but unreadable application looked the same.

**Five URLs, kept apart**

| URL | Where | Meaning |
|---|---|---|
| Source URL | `job_postings.source_url` | Where the job was discovered. Never changed by inspection. |
| Provider destination | `discovery_metadata.discovery.applyUris` | A separate application URL a provider supplied. Only USAJobs does. |
| Application URL | `applications.application_url` (+ `_origin`, `_set_at`) | Where the application is completed: `user` (a person's override, never replaced automatically) or `resolved` (an inspection reached a form there). |
| Requested / final URL | `agent_runs.result_summary` | Per run, origin + path only. |

`App\Support\ApplicationInspection\ResolveInspectionStartUrl` chooses where a run starts: the Application URL, then a
valid provider destination, then the source URL. Every candidate passes `InspectionUrl` (http(s), no credentials, no
internal hosts, tracking parameters stripped, functional ones such as `gh_jid` kept); a provider URL is not trusted for
coming from an API. The browser then follows at most two Apply links from there. There is no second resolver.

**Outcomes.** Run status stays technical (`succeeded` / `failed`). `inspection_outcome` says what was found and
`inspection_stop_reason` why it stopped: `complete`, `partial`, `unsupported` (a form was reached that cannot be read),
`application_not_found`, `needs_application_url`, `authentication_required`, `blocked`. A record from before stop reasons
existed (`unsupported` with no reason) is shown as such and never reinterpreted.

**Generic form discovery (M1, off by default).** `BROWSER_WORKER_GENERIC_DISCOVERY=1` makes the worker read a form that has no
dedicated extractor (an unknown ATS, or Lever) from what the page says, without interacting with it, instead of reporting it
`unsupported`. One row per logical control: a custom combobox and its hidden validation input are one `select`, a radio group is one
`radio_group`, a generic file label ("Attach") is replaced by the heading above it (stored as `inferred_proximity`). A result is
`complete` only when nothing known was left unread; labelled hidden fields, labels that point at absent controls and announced further
steps make it `partial` with the warnings `conditional_fields_not_inspected` / `more_steps_not_inspected`. No new column, outcome or
stop reason: the existing contract is unchanged. See `workers/browser-inspector/README.md` "Generic form discovery".

**Recognising a form (generic discovery on).** The page classifier gains one step, after every barrier check: a page with no strong
application signal is read as a form when its *context* says it is the application (a URL the person supplied, a destination reached
through an identified Apply link, one an earlier inspection resolved) or when it shows independent *content* evidence (application
wording, an application submit button or a self-identification section, three or more questions). Search, sign-in, sign-up, newsletter,
contact and cookie pages are never read, whatever the context; a sign-in or a CAPTCHA always wins. The warnings
`application_form_inferred_from_context` / `..._from_content` record how a page came to be read. With the flag off nothing changes. See
`workers/browser-inspector/README.md` "Generic form discovery".

**How an outcome reads in the UI.** The Opportunities drawer and the full application page share one presentation
(`resources/js/lib/inspection-status.ts`, `resources/js/components/inspection-findings.tsx`), so a result is worded the same
in both. A run that finished but did not reach a form is a worker success with its own finding: `application_not_found` reads
"Needs application URL" (the listing was reached, the employer's application form was not), with where it started, the
application URL known so far and who set it (`user` or `resolved`), and the stop reason as a sentence. A listing that applies
through a JavaScript button is explained as one the inspector could not safely follow automatically (`apply_is_button`).
"Enter application URL" is the existing override (`application_url` on the inspection request: validated, saved with `user`
provenance, then inspected). Raw codes, the page trace and every warning stay in a collapsed "Technical details" section;
repeated warnings are listed once per code with a count (`blocked_non_get ×18`).

**Lease.** `BROWSER_WORKER_CLAIM_TTL` is 120 s. The claim response carries `budget_seconds` (the lease minus a 30 s
delivery allowance), the worker enforces it as a hard stop, and a result that arrives after a lapsed claim was recovered
as failed is still rejected as stale.

**Preparation is unchanged.** It still judges and opens only the job's `source_url` against its own host allowlist. The
Application URL is where a later phase will join the two, behind one accessor, without widening that allowlist.

**Deployment isolation.** The inspector runs untrusted pages' JavaScript, so on the AI box it is deployed behind a
host-enforced network policy rather than on a default Docker network: dedicated bridges, a two-endpoint API relay to Well
Applied, an nftables table that runs ahead of Docker's and Tailscale's rules, and systemd units that refuse to start (and
kill) the worker unless that policy is intact. See `workers/browser-inspector/isolation/README.md`.
