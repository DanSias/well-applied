# Profile synthesis and the reference resume (Stages 0 to 2)

A Resume Profile can now be synthesized into a persistent, reviewable **profile** and a **reference resume**
composed from it. Job tailoring from the profile is a later stage and is not built.

```
approved evidence + market sample + CapabilityCoverage
   -> ProfileAssembly          deterministic evidence inventory (no model)
   -> ProfileSynthesisPrompt   ONE local-model call: positioning language only
   -> ProfilePresentationValidator   deterministic; rejects unsupported language (one retry with the exact problems)
   -> resume_profile_syntheses (versioned draft)   user: include / exclude / pin / reorder / reword
   -> approve                  locks the version
   -> ReferenceSelection       deterministic Selection from the approved profile
   -> ResumeSelectionResponseValidator -> Wording (reference prompt) -> triage / recovery / guards -> ResumeVariant -> PDF
```

## Inventory versus language

`content.inventory` is computed from eligible evidence (Public and Restricted; Private is only counted) and
never contains presentation language. `content.presentation` (headline, summary, differentiators, project
notes) is the model's writing. `edits` holds the user's decisions apart from both, so regenerating never
discards them and the original stays inspectable. `EffectiveProfile::resolve()` applies the edits; everything
downstream reads that.

Projects are ranked on six signals (ownership, architecture, technical complexity, market relevance blended
with full-stack breadth, distinctiveness, outcomes), each 0 to 1 and stored with the project. Fact count is not a
signal. Employment projects rank before personal ones. A shared claim (`claim_group`) is one accomplishment,
shown under the place whose project ranks highest, and still counts as evidence for each of its projects.

## Rules the validator enforces

Every sentence cites eligible fact keys; names no technology or capability the cited facts do not state; claims
only **direct** capabilities (transferable ones and confirmed limitations are never claimed); invents no figure
or years-of-experience claim; keeps the fact's own ownership verb; names an employer only if a cited fact belongs
to it; and passes `RestrictedEvidenceGuard` and `ClaimScopeChecker`. Restricted facts' figures are withheld from
the model's input. Reworded text is held to the same rules.

## Reference resume (no job)

`resume_variants.job_match_id` is nullable and `resume_profile_synthesis_id` links a reference resume to the
profile it came from. `GenerateResumeVariant::generateReference()` is the job pipeline with two differences: the
Selection is `ReferenceSelection` (deterministic, budgeted, trace of what was left out) and it is judged by the
same Selection validator; and the wording prompt (`resume-wording-reference-v1`) states the reference context
instead of assuming a job. There is no synthetic job posting and no target terms. Whole-career and personal
evidence can support the summary and Selected Projects only; the Selection validator already bars it from any
role's bullets. Only an approved, current (not stale) profile can generate one.

Run: Resume Profile page, "Build the profile" (queued) or `php artisan profile:synthesize [--force]`; approve on
the page; "Generate reference resume" (queued). `profile:synthesize --inventory` shows the inventory with no
model call.

## How the reference selection chooses (reference-selection-v2)

Within a role, evidence is chosen by **kind**, not raw rank. Each statement has a facet (architecture, security and
reliability, backend/API, frontend, measured outcome, ownership, leadership, generic development). Distinctive facets
weigh more; a second bullet of a generic facet (another "owned it", "built it in TypeScript") weighs much less; facts of
one project and kind that overlap share one bullet (at most three facts per bullet); a statement that restates one already
chosen is skipped; role-scope descriptions (narrative facts) never become bullets. The profile score only breaks ties,
because on a small market sample it mostly reflects keyword counts. The user's project order, pins and exclusions decide
priority: their top few employment projects (`priority_projects`) are protected, first by swapping out a weaker bullet
and only then by a bounded extra allowance (`coverage_extra_bullets`, at most one per role). Other projects compete on
their merits and may be absent: the profile is an evidence pool, not a checklist. The trace records the facet of every
bullet and the reason each omitted fact was left out.

## Fidelity checks for the reference wording (reference-wording v2)

The first real reference resume showed wording that read well but added claims no fact made. A reference resume's
wording is now judged on its **material claims**, against the combined evidence of the facts cited at that location
(`ClaimFidelityChecker`, reference resumes only; job-linked generation is unchanged until reviewed):

| Finding | Example | Repair (deterministic, never adds a claim) |
|---|---|---|
| `scope_expansion` | "... across managed client engagements" | the phrase is removed |
| `unsupported_cause` | "reduced onboarding effort ... through improved documentation" | the clause is removed |
| `ownership_upgrade` | "Designed and implemented" for "Owned the implementation of" | "Designed and" is dropped; a bare unsupported lead verb takes the evidence's own ("Drove" becomes "Delivered") |
| `figure_precision` | "20+ hours" for "approximately 20+ hours"; a figure no cited fact contains | the evidence's wording is restored; an invented figure omits the bullet |

Ordinary words ("through", "by", "enabling") are never findings: a cause is unsupported only when what it names is absent
from the evidence, and a scope only when its breadth words are. A repaired bullet is kept as `generalized` and flagged in
the review report; when no repair exists the bullet is omitted.

Two retention problems earn **one** corrective retry and then ship flagged ("Approved profile coverage" in the review
report), never a failed run: the summary dropped a technology or project the approved summary names and the cited evidence
supports, and a bullet cites a fact it never states.

## Selection follows the approved profile

Besides the facet scoring above, facts the approved profile cites (differentiators, summary) weigh a little more; a
claim shared by several projects and phrased generally weighs less; a result whose figure is confidential is not an outcome
bullet (it cannot be stated); a developer or engineer role always shows at least one thing that was built; the user's first
three projects are protected in order. A fact the profile cites that is only one place of a shared claim is represented by
the offered place, so approved summary content never loses its evidence to claim collapsing, and every fact the summary
cites stays in its evidence. **Skills** come from the curated profile (kept direct capabilities weighted by market demand,
the stacks of included projects with priority projects counting fully, and a little for being cited), never from restricted
evidence, at most two per capability and three practice tags, 14 in all.
