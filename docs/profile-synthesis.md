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
