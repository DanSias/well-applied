<?php

namespace App\Support\ResumeVariant\Prompts;

/**
 * Resume Wording V3 for a reference resume. A new class and version (never an edit of V3, which is a
 * persisted `wording_prompt_version`): V3's text is reused verbatim and one section names the reference
 * context explicitly. There is no job, so there are no target terms and the deny list is empty; the
 * approved profile's positioning is input, not evidence. Response shape, schema and every validator
 * are V3's.
 */
final readonly class ReferenceWordingPrompt
{
    private ResumeWordingPromptV3 $base;

    public function __construct()
    {
        $this->base = new ResumeWordingPromptV3;
    }

    public function version(): string
    {
        return 'resume-wording-reference-v1';
    }

    public function schemaVersion(): string
    {
        return $this->base->schemaVersion();
    }

    public function systemPrompt(): string
    {
        return $this->base->systemPrompt()."\n\n".<<<'PROMPT'
        ## Reference resume context (overrides any assumption of a target job)

        This is a broadly applicable reference resume for a Full-Stack Developer, not a resume tailored to a
        specific job. There is no job description, no target terms and no posting to fit: ignore every
        instruction above that assumes one. The input carries a `reference_context` object with the identity,
        the user's approved positioning summary and approved differentiators for this resume.

        - Write the summary as that approved positioning, in your own tight wording. You may shorten, reorder
          and sharpen it, but you may not add a claim, technology, number or responsibility that the cited
          `summary_evidence` facts do not support. If an approved sentence is not supported by those facts, omit
          it. Open with the Full-Stack Developer identity and lead with end-to-end ownership and the strongest
          technical evidence.
        - Each bullet words exactly one bullet group. When a group has an implementation fact and outcome facts,
          write one strong sentence that says what was built and what it achieved, keeping every figure exactly as
          stated. Never combine facts from different groups.
        - Facts marked personal or whole_career carry no employer. They may support the summary only. Never
          present them as work done for an employer.
        - Prefer concrete engineering language (what was designed, owned, integrated, decided) over adjectives.
          No filler such as "passionate", "results-driven" or "proven track record".
        PROMPT;
    }

    /**
     * @param  array<string, mixed>  $approvedSelection
     * @param  array<int, string>  $denylistTerms
     */
    public function userPrompt(array $approvedSelection, array $denylistTerms): string
    {
        return $this->base->userPrompt($approvedSelection, $denylistTerms);
    }

    /**
     * @param  array<int, int>  $rolesInSelection
     * @param  array<int, int>  $selectedProjectIds
     * @return array<string, mixed>
     */
    public function jsonSchema(array $rolesInSelection, array $selectedProjectIds): array
    {
        return $this->base->jsonSchema($rolesInSelection, $selectedProjectIds);
    }
}
