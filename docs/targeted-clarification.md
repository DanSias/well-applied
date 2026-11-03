# Targeted clarification and evidence enrichment

An optional stage of the one-click "Generate Tailored Resume" pipeline. It
asks a few high-value questions where the job needs something the career
data does not say, turns the answers into evidence the user reviews, and only
then lets approved evidence reach matching and resume Selection. It never
runs on its own authority and never blocks: **Generate Now** is always
available.

```
analysis -> match -> [ clarification ] -> resume (Selection, Wording) -> PDF
```

## Policy decisions

| Topic | Decision |
|---|---|
| Source of truth | The database. `data/approved-career-evidence.json` is an explicit, idempotent export of approved evidence only. `canonical-career-data.proposed.json` stays an import source for the original corpus and is not a competing source. |
| Verification | New user-confirmed facts are `strongly_supported`. `verified` is allowed explicitly; a statement containing a figure additionally needs the user to confirm they can substantiate it. |
| Visibility | Never assumed. Approval must pass an explicit visibility. The proposed default is the most restrictive visibility among related evidence in the same role, and `restricted` when nothing anchors it. Restricted or Private material is never promoted to Public by the system. |
| Limitations | Separate records (`career_limitations`), not CareerFacts. Revisable (`revoked_at`), never permanent. |
| Presentation preferences | Separate records (`presentation_preferences`). Guidance only, never an accomplishment. |

## How a question is chosen

`DiscoverClarificationQuestions` is deterministic and makes no model call. A
finding from the job analysis drives a question only if the match found it
`partial` or `no_evidence`, it is a real requirement (not a culture signal, not
`not_required`, not a years-of-experience floor), and its topic is not already
answered.

```
score = strength x emphasis x leverage x gap x answerability
```

`answerability` rewards adjacent resume-eligible evidence (the user probably
knows more than is written down) and gives a requirement with nothing nearby a
low floor. Keyword frequency is not an input. Every question stores its score
breakdown, and the UI shows why it was asked. All weights live in
`config/clarification.php`.

A finding maps to a **topic** (first matching entry in the catalog, else a slug
of its label), so one finding gives one question and the same subject is
recognised across differently worded jobs. A topic is suppressed by: an active
limitation, an approved fact or preference on it, or an explicitly skipped
question inside the cooldown (30 days). "Generate Now" is not a skip.

Standard mode asks at most 3 questions above a minimum score; if none qualifies
the stage never appears. Deeper discovery relaxes both limits (up to 8). A user
can also open questions on a finished resume ("Add details to improve
targeting").

## Pipeline behaviour

- One `ClarificationSession` per (job posting, job analysis). The first time the
  pipeline reaches Selection it ranks and, if anything qualifies, pauses with a
  `pending` session (state `needs_clarification`). A resolved session is never
  reopened implicitly, so retries and the re-match after approval cannot loop.
- A regeneration, a job with nothing worth asking, and a retry never pause.
- `continue` resumes the same run. With nothing answered it records `skipped`.

## Freshness: no stale match

`career_profiles.evidence_revision` is bumped whenever approved evidence changes
the fact corpus (a fact created or replaced, or a sync that changed facts).
`job_matches.evidence_revision` records the value read **before** the corpus was
built. `TailoredResumePipeline::inspect()` reuses a match only while the two
are equal, so approving evidence makes the earlier match stale and matching runs
again before Selection. Limitations and preferences do not change the corpus and
do not bump it.

Selection reads the full eligible corpus, and its checkpoint fingerprint hashes
the exact prompt it saw, so an earlier checkpoint cannot match once a fact is
added. A finished run whose match went stale is shown as `idle` with an
explanation rather than an endless spinner.

## From answer to canonical evidence

1. `SubmitClarificationAnswer` stores the answer verbatim (append-only) with the
   chosen mode and responsibility scopes, and creates one `EvidenceProposal`:
   a fact, a limitation (`no experience`), or a presentation preference.
2. The user edits the statement, scope, attribution and visibility.
3. `ReviewEvidenceProposal::approve()` is the only place a CareerFact,
   limitation or preference is created. It requires an explicit visibility,
   runs the scope guard, and stops on a duplicate, a same-topic fact (needs an
   explicit replace), or a contradicting limitation (needs an explicit revoke).
   Every step is appended to the proposal's `history`.
4. A fact gets `user_confirmed` evidence whose `quoted_text` is the user's
   original answer and whose metadata (`origin: clarification`, topic, scopes)
   marks it as approved user evidence.

**Scope guard.** A verb that claims building or ownership ("built",
"implemented", "led", "webhook handler", and so on, listed in config) is allowed
only when the user selected "Implemented it myself" or used that word in their
own answer. Configuring webhooks never becomes building webhook handlers.

## Known limitations at generation time

An active limitation adds its `terms` to the wording check as a **block**
finding (`known_limitation`), so a bullet or summary sentence claiming the term
is omitted by the existing recovery, and a Skill matching a term is withheld
from the Selection candidates. Matching is whole-word ("trails" is not
"Rails").

## Claim scope at generation time

The approval-time scope guard only checks the statement the user approved. Wording
can still merge several true facts into a stronger sentence ("worked with webhooks"
plus "troubleshot integration issues" becomes "troubleshooting webhook flows").
`ClaimScopeChecker` runs after Wording, per sentence, inside the same triage that
produces `known_limitation` and restricted-evidence findings:

1. It reads each "action ... topic" claim. The nearest classified action verb before
   a topic mention governs it. Verb classes (implementation, ownership, integration,
   configuration, troubleshooting, advising, support) and the topic patterns live in
   `config/clarification.php` (`claim_scope`, `topics`). Nouns ("integration") are not claims.
2. Each cited fact that mentions the topic is asked whether it establishes that class.
   A fact's classes are the scopes the user declared when confirming it
   (`DeclaredScopes`, newest revision), otherwise what its own statement says.
   Implementation implies integration and configuration; nothing implies ownership;
   configuration, support and advising never imply implementation.
3. Outcomes: supported = no finding. The action appears only in a cited fact that does
   not mention the topic (a composite), or is not established = `review`: the bullet ships,
   is flagged, and the report names the exact claim and why. Implementation or ownership
   that the evidence rules out (declared scopes exclude it, or nothing cited supports it)
   = `block`: the existing bullet-level recovery omits just that bullet.

The review report item carries the flagged sentence (`text`) and each finding's `detail`
(the exact claim) and `message` (why). A removed sentence is not stored in the report.
Confidentiality is still enforced by `RestrictedEvidenceGuard` and limitation terms.

## Visibility in matching

`CandidatePayloadBuilder` uses `ResumeEligibility::eligibleCareerFactsQuery`, the same
boundary as Selection and Wording: Private facts, and facts under a Private project, are
never sent to a model, never frozen into `input_snapshot`, and cannot be cited (the match
validator rejects a key it was not given). Restricted facts are unchanged. Clarification
ranking is deterministic and was already restricted to eligible facts.

## Presentation preferences

Active `PresentationPreference` rows are passed to Selection as plain data
(`ActivePresentationPreferences`), appended to the Selection user prompt as a block that
states its own limits: emphasis only, not evidence, job relevance first, never cited. With
none, the prompt is byte-identical to before. The Selection fingerprint hashes the prompt,
so a changed preference cannot reuse a checkpoint. The list is recorded in
`selection_input_snapshot.presentation_preferences`. Wording never receives it.

## Revising approved evidence

`ReviseApprovedEvidence` (`PATCH /career-facts/{fact}/revise`, "Evidence you confirmed" on
the job page). A revision is a new approved `EvidenceProposal` (`revision` + 1,
`revises_proposal_id`) sharing the original answer; the earlier one becomes `superseded` with
its previous values in its history. The `CareerFact` keeps its stable key and shows the current
revision, and each revision appends a `user_confirmed` evidence row quoting the original answer.
Same rules as first approval (scope guard, explicit visibility, figure acknowledgement),
duplicates refused, one active fact per topic and attribution, and `evidence_revision` is bumped
so matches go stale and Selection checkpoints cannot match. Export includes every evidence row
(with `revision`); an older file reports a conflict instead of rolling a revision back.

## Import and export

```
php artisan career:export-approved [path]     # DB -> data/approved-career-evidence.json (idempotent)
php artisan career:sync-approved   [path]     # file -> DB; creates missing, reports conflicts
php artisan career:sync-approved --accept-file  # explicitly replace differing DB facts
```

- Export contains approved facts (by stable key, with provenance and a content
  hash), limitations and preferences. Never proposed or rejected proposals.
  Attribution is by natural key (employer and role dates, or project slug).
- Sync leaves identical rows alone and reports a conflict for anything that
  differs; it never picks a side without `--accept-file`.
- `career:import` refuses to overwrite a fact created from approved evidence and
  prints a conflict for each such key.

## Files

`app/Support/Clarification/*`, `app/Models/{ClarificationSession,ClarificationQuestion,ClarificationAnswer,EvidenceProposal,CareerLimitation,PresentationPreference}.php`,
`app/Http/Controllers/ClarificationController.php`,
`app/Support/CareerData/ApprovedEvidenceFile.php`,
`resources/js/components/clarification-panel.tsx`,
`config/clarification.php`,
migration `2026_11_05_000000_create_clarification_and_evidence_proposal_tables`.

## Not built yet

Resume Profiles, wording reuse, a model-written question or statement, retiring
(not just revising) an approved fact, and batch use.
