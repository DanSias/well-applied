<?php

namespace App\Support\ResumeVariant\Prompts;

/**
 * A text-only revision of ResumeWordingPromptV2 — a new class, never an
 * in-place edit, because `resume-wording-v2` is a persisted
 * `wording_prompt_version`. V2's text is reused verbatim and two
 * sections are appended (Sprint 1: resume quality):
 *
 *  - Punctuation: a resume never contains an em dash. Enforced
 *    deterministically as well (ResumeText::clean() at generation and
 *    render time; the validator rejects any that survive), so this is
 *    guidance that avoids awkward rewrites, not the safety net.
 *  - Restricted evidence: a CareerFact flagged `visibility: restricted`
 *    is usable only as a generalized, accurate claim about the
 *    candidate's own engineering work. Enforced by
 *    RestrictedEvidenceGuard (named parties, configured confidential
 *    terms, hostnames, and figures that exist only in restricted
 *    evidence are rejected).
 *
 * Response shape and schema are identical to V2.
 */
final readonly class ResumeWordingPromptV3
{
    private ResumeWordingPromptV2 $base;

    public function __construct()
    {
        $this->base = new ResumeWordingPromptV2;
    }

    public function version(): string
    {
        return 'resume-wording-v3';
    }

    public function schemaVersion(): string
    {
        return $this->base->schemaVersion();
    }

    public function systemPrompt(): string
    {
        return $this->base->systemPrompt()."\n\n".<<<'PROMPT'
        ## Punctuation

        Never use an em dash (the long dash character) anywhere in the
        summary or any bullet. Use a comma, a period, a colon, or
        parentheses instead, and prefer two short sentences or a clean
        clause over a dash-joined aside. Do not end a bullet or the summary
        on a single short word that would sit alone on its own line when
        the sentence can be phrased to end on a fuller phrase.

        ## Restricted evidence

        A CareerFact marked `"visibility": "restricted"` describes work
        whose specifics are confidential. You may still describe the
        candidate's own engineering contribution, but only as a
        generalized claim that remains accurate and supported by that
        fact:

        - Describe what you built and the engineering approach (for
          example safe, reviewable, verified data correction tooling) and
          never name a customer, merchant, client, or counterparty, and
          never name internal hostnames, endpoints, field names, or other
          operational details.
        - Never state a number, count, percentage, or amount that appears
          only in restricted evidence. A figure may appear only if a
          non-restricted fact cited at that same location also states it.
        - Do not strengthen a restricted fact into a broader claim than it
          makes. If a faithful generalization is not possible, say less.
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
