# Job qualification (shadow mode)

A fast, evidence-backed screen of one discovered job against the career profile, run before full analysis and matching.
It is **shadow mode**: it produces a recommendation a person can read in the job drawer and nothing else. It never changes
a job's disposition, queues analysis or matching, generates a resume, or runs on a schedule.

## Contract

`job_qualifications` rows are immutable snapshots (job + profile). Each carries:

- `eligibility`: `eligible | ineligible | unknown | insufficient_information`, with `eligibility_reasons` (code, severity,
  message, and the field value or posting sentence it rests on);
- `verdict`: `strong | good | needs_review | poor`, or null when no model was asked (ineligible or thin postings);
- `model_verdict` and `adjustments`: what the model proposed and every change the policy made to it;
- `assessment`: the validated model output (role type, primary focus, hands-on level, responsibilities, technologies with
  requirement/familiarity/depth/transferable flags, seniority, fit reasons, biggest concern, practical concerns), each
  claim citing posting sentences, stored as the posting's exact text rather than anything the model wrote;
- `content_fingerprint`, `profile_fingerprint`, `policy_version`, `prompt_version`, `generated_by`, `generated_at`.

There is no confidence number anywhere.

## Pipeline (`App\Support\JobQualification\GenerateJobQualification`)

1. **Freshness.** If the latest row matches the posting's content fingerprint, the profile's capability fingerprint and the
   policy/prompt versions, return it (no model call) unless a refresh was requested. Compensation, URL and discovery
   timestamps are not part of the content fingerprint, so rediscovery does not invalidate anything.
2. **Eligibility gate (`AssessEligibility`).** Deterministic. Ineligible only on explicit, reliable evidence: an on-site or
   hybrid role outside Florida, a sentence restricting the role to a place outside the US or to states without Florida, or a
   required clearance the profile gives no sign of. Unclear remote status or location is `unknown`; a preview-only or very
   short description is `insufficient_information`. Neither is a rejection. Ineligible and insufficient postings stop here
   and cost no model call.
3. **Screen.** One bounded structured call through `GeneratesJobQualification` (Ollama by default,
   `AI_JOB_QUALIFICATION_PROVIDER`; its own model and timeout settings). The model sees the posting's numbered sentences and
   a compact `CandidateCapabilitySummary` built from the same eligible (non-Private) facts and skills the full match uses.
4. **Validate.** `JobQualificationResponseValidator` rejects any unexpected field, unknown value, oversize list or
   evidence id the model was not given.
5. **Policy (`ReconcileVerdict`).** The policy enforced in code:
   - a mandatory unfamiliar technology that explicitly needs deep production experience, with no transferable allowance, is
     `poor`;
   - a mandatory unfamiliar technology where equivalents are explicitly welcome is `needs_review`..`good`;
   - preferred or incidental technologies never lower a verdict, and `poor` needs a structural reason (such a stack, a
     non-engineering role, or a primarily management or infrastructure role), otherwise it is raised to `needs_review`;
   - infrastructure-focused and management-focused roles are capped at `needs_review`;
   - `strong` needs a hands-on product-engineering role, nothing unfamiliar and mandatory, and eligible status;
   - a technology the model calls unfamiliar that is one of the candidate's own skills is corrected to familiar.

Failures (provider error, timeout, off-contract output) end as a failed `GenerationAttempt` and write no row. The earlier
assessment, if any, stays visible.

## Reuse

Lifecycle, claim lock, duplicate protection, stale recovery and polling are the existing `GenerationAttempt` machinery
(`GenerationType::JobQualification`, `GenerateJobQualificationJob`). The transport is `OllamaChatCompletionsClient`;
evidence uses `SegmentJobPostingDescription`; candidate data comes from `ResumeEligibility` and `CareerFactAttribution`.

## Trigger and UI

`POST /jobs/{job}/qualification` (optionally `refresh=1`) from the Qualification block at the top of the Jobs drawer.
Single job only; a batch action was deliberately not added. Policy-version bump rule: change `QualificationPolicy::VERSION`
whenever eligibility, reconciliation or the prompt change what a job would be told.

## Known limits

Eligibility patterns are English-only and sentence-based; the policy's role-type and familiarity judgements still rest on the
model; nothing is calibrated against real postings yet, which is what shadow mode is for.
