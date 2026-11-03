# Market-informed Resume Profiles (Phase 1)

A Resume Profile studies a sample of real postings for one kind of role, compares what the market
asks for with the user's approved career evidence, and interviews the user about the gaps. Answers
go into the shared career database through the existing clarification and evidence-proposal workflow.
Nothing is copied into the profile. No model call is made anywhere in this phase.

```
build  ->  sample postings  ->  extract + aggregate requirements  ->  coverage  ->  rank topics  ->  interview
```

Run it: **Resume Profiles** in the nav, "Build My Full-Stack Profile" (queued), or
`php artisan profile:build [slug] [--pilot] [--no-fetch] [--force]`.

## Entities

| Table | Role |
|---|---|
| `resume_profiles` | name, slug, role family, purpose, optional `search_parameters`, status, `revision`, current `market_sample_id`, the `evidence_revision` its coverage was last computed against, `presentation_guidance` (guidance only). References evidence by fact key; holds no facts. |
| `market_samples` | one collection: status, pilot flag, target size, extraction version, per-provider fetch log, decision counts, the aggregated capability map. |
| `market_sample_postings` | every posting considered, included or not, with provider, source URL, company, title, retrieval date, content hash, the decision and its reason, the duplicate it points at, and its cached extraction. |
| `clarification_sessions` | now allows no job (`job_posting_id`/`job_analysis_id` nullable) and carries `career_profile_id` and `resume_profile_id`. An interview is a session with `mode = profile`. |

Definitions live in `config/resume_profiles.php` (`ProfileDefinitions`). A second category is a config
entry; nothing in the code is specific to Full-Stack.

## Market sampling (`MarketSampleCollector`)

Reuses, rather than duplicates, the discovery code: the existing providers' `retrieve()` (returns
candidates, writes nothing), `FilterDiscoveredCandidates` with criteria built from the profile's search,
and already-stored job postings. Sampling never writes `job_postings`, so nothing sampled is an
opportunity or an application.

Sources, in order: stored postings; postings an earlier sample retrieved inside the refetch window;
fresh provider retrieval (one request per provider, skipped for `refetch_hours` unless `--force`).
Adzuna is not sampled: it only returns a preview. A provider error is recorded (exception class only
for unexpected errors) and the others continue.

Every candidate gets one decision, in this order: `incomplete_description`, `description_too_short`,
`stale`, `outside_search`, `not_full_stack`, `duplicate_exact` (same text or same provider posting),
`duplicate_near` (same company and title, or description shingle similarity >= 0.82). Survivors are
taken newest first under caps per company, provider share and primary stack, up to the target
(`company_cap`, `provider_cap`, `stack_cap`, `over_target`). The page shows the counts and examples.

## Requirements (`RequirementExtractor`, `AggregateMarketRequirements`)

Deterministic: a 51-capability taxonomy in config (technologies, practices, architecture/integration,
responsibilities, collaboration, domain). Each mention is `required`, `preferred`, `duty` or
`mentioned`, from the line's cues and the section heading; benefits and "about us" are ignored. Close
neighbours stay separate (third-party API integration, webhooks, webhook handlers, retry/idempotency).
Per capability: postings, share, required and preferred share, seniority split, supporting posting ids
and example lines. Extraction is cached on each posting by `(content_hash, extraction_version)` and
copied from any posting with the same hash, so unchanged text is never read twice. A market
capability is never treated as something the user has.

## Coverage and ranking

`CapabilityCoverage` compares each capability with eligible facts only (Private never counts or
appears): `directly_supported`, `transferable` (a related capability is supported), `underdocumented`
(only a Skill tag or an unconfirmed fact), `limitation`, `no_evidence`. A child's evidence supports
its parent (handler -> webhooks), not the reverse.

`RankDiscoveryTopics`: `score = importance x gap x answerability x usefulness`; documented capabilities,
limitations, topics with approved evidence or a preference, and topics skipped inside 30 days are
never asked. Capabilities in fewer than 2 postings or 8% of the sample are dropped.

## Interview (`ProfileInterview`)

One long-lived session per profile, up to 16 topics in groups of 4. Questions are stored with their
score, market numbers and suggested anchor (the role of related evidence, with its most restrictive
visibility; otherwise the user chooses). Unanswered questions are refreshed from the current sample
and record, resolved to `covered` when the record now covers them, or `retired` when the market no
longer favours them; answered and skipped ones are never changed, and a skipped question can be
reopened. Answers use `SubmitClarificationAnswer`, review and approval use `ReviewEvidenceProposal`
(explicit visibility, scope guard, limitations, revision), so evidence revision tracking and
`ReviseApprovedEvidence` apply unchanged.

## Quality pass

**Sample review.** Every posting stores the signals behind its inclusion (title terms, or the front-end
and back-end terms a generic title needed) and whether its description was complete. The page's
"Review the sample" lists them with a "Not a good fit" action. `SampleReview` records a
`market_sample_exclusions` row (matched by content hash, provider id, or company plus title), marks the
posting `user_excluded`, re-aggregates (extraction is cached) and re-syncs the interview, so the map
and ranking follow. Exclusions survive rebuilds and can be undone; job postings and applications are
never touched. No minimum sample size is required.

**Extraction precision** (`market-extract-v2`). A capability has `patterns` (explicit phrases) and
optional `weak_patterns` (ambiguous words). Each hit keeps the phrase matched, the line, the level and a
confidence (`high`, `medium`, `low`); `low` hits are kept as evidence but never counted towards
prevalence. "Scalable" is its own weak capability and no longer means distributed systems; Docker and
Kubernetes are separate; API integration does not feed webhook handlers; architecture ownership needs
an explicit "own/lead/define ... architecture" phrase, not broad ownership language.

**Question kinds.** `CapabilityCoverage` also reports depth (`deep`, `moderate`, `shallow`) and the real
roles and projects behind the evidence. A capability that is absent or only related is a *discovery*
question; one the user clearly has but the record documents thinly (a Skill tag, one fact, no outcome)
is an *evidence deepening* question, weighted lower than a true gap, and a deeply documented one is not
asked. Deepening questions ask for the most substantial example, what the user personally designed or
implemented, the problems solved and a quantifiable result, naming the user's real projects. Discovery
questions about architecture or responsibility anchor on the best-documented real projects.

**Optional model phrasing** (`QuestionEnrichment`, `profile:enrich`, `profile:build --enrich`,
`RESUME_PROFILE_ENRICH`). Off by default. One batched local-model call rewrites the open questions;
output is schema-bound and each question is validated (a question, on topic, no invented numbers, no
asserted experience, no plain yes/no for deepening). Cached by a fingerprint of the evidence revision,
sample and question; a failed call or rejected text falls back to the deterministic wording; a page
load never calls a model. Latency and token usage are recorded on each question.

## Evidence expansion (one answer, several facts)

An optional, user-triggered step on each answered question ("Expand into separate facts"; `evidence:expand`).
`ExpandAnswerClaims` sends the existing local model (through `QuestionWriter`) the verbatim answer, the
question and the projects it named, the user's real roles and projects as the only allowed attribution targets,
and short eligible facts already on them (Private evidence and Private projects are excluded). The bounded
schema returns up to 8 claims (capability, statement, 1-5 attributions, scopes, verbatim source quote, confidence,
rationale) and at most 2 follow-up questions.

Every claim is validated against the answer before it becomes anything: targets must be ones it was given; the
source quote must appear verbatim; scopes must be a subset of those the user ticked; numbers, outcome and metric
language and scale or breadth qualifiers must come from the answer; a technology named must be in the answer or the
place's facts; implementation or ownership verbs pass the same `ScopeGuard` as approval; repeats are dropped. A dropped
claim is listed with its reason. Strong phrasing and reasonable inference are allowed, invention is not.

A claim becomes `EvidenceProposal`s in the normal `proposed` state: one per place, sharing a `claim_group`, each with
`expansion_id`, `source_quote`, confidence, rationale and the original answer. A claim spanning several projects is
therefore several reviewable facts, and a claim covering an employer's projects can be attributed to its role. Nothing is
approved and no existing fact is changed. Results are cached by a fingerprint of the prompt version, the answer, the
evidence revision and the attribution context; re-reading replaces only the earlier run's undecided claims. If the model
fails, the answer's original single proposal is untouched and remains the fallback.

Review (`ExpandedClaims`, per proposal through `ReviewEvidenceProposal`): edit wording and scope, add or remove places,
approve (explicit visibility required, one fact per place, all-or-nothing in a transaction), reject, or merge a claim into
another. Each place shows how it relates to what is recorded (`ExpansionDuplicates`): `same_topic` (approving requires an
explicit replace, which revises the earlier fact and keeps its history) or `near_duplicate`. Approved facts carry
`claim_group` and `source_quote` in their evidence metadata, and `quoted_text` stays the full original answer.
A suggested follow-up becomes an interview question only when the user clicks "Ask this".

## Not in this phase

Profile-assisted generation, wording reuse, multiple profile families, pagination beyond each
provider's single page, bulk or scheduled resampling, any model-assisted normalization.
