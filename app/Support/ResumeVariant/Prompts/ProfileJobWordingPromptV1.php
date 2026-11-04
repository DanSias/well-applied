<?php

namespace App\Support\ResumeVariant\Prompts;

/**
 * Wording for a resume tailored to one job from an approved profile. A new class and version: the reference wording
 * prompt (V3) is reused whole, so the fidelity, support-only and no-invention rules are unchanged, and one section
 * says what is different (there IS a job; the approved summary is positioning to adapt, not a text to preserve).
 * Schema and every validator are the reference path's.
 */
final readonly class ProfileJobWordingPromptV1
{
    private ReferenceWordingPromptV3 $base;

    public function __construct()
    {
        $this->base = new ReferenceWordingPromptV3;
    }

    public function version(): string
    {
        return 'resume-wording-profile-job-v1';
    }

    public function schemaVersion(): string
    {
        return $this->base->schemaVersion();
    }

    public function systemPrompt(): string
    {
        return $this->base->systemPrompt()."\n\n".<<<'PROMPT'
        ## This resume is tailored to one job (overrides the reference framing above)

        The input carries a `job_context` object: the role, what the job asks for (`emphasis`), the requirements the
        candidate's approved evidence supports directly (`supported`), and requirements it does not (`not_supported`).
        The approved summary in `reference_context` is the candidate's positioning, not a text to preserve. Adapt it.

        - Write the summary for this role. Open with the Full-Stack identity, then lead with the evidence that speaks
          most directly to what the job emphasizes, using the cited `summary_evidence` only. Keep it three to four
          sentences. You may drop a project or technology the approved summary names if it does not serve this job.
        - Each bullet still words exactly its own bullet group. Put the part of the cited facts that matters to the
          job first (for example payment APIs, webhooks, onboarding, integrations, conversion work) and keep the rest
          brief. Never add what the facts do not say.
        - Use the job's own vocabulary only where a cited fact states the same thing. Never name a technology, tool,
          framework or domain from `not_supported` as experience, and never hint at it ("familiar with", "exposure
          to", "eager to learn"). If the candidate lacks something the job prefers, say nothing about it.
        - Do not repeat the same project or claim in two bullets. No keyword stuffing, no filler, no em dashes.
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
