<?php

namespace App\Support\ResumeVariant\Prompts;

/**
 * Reference wording V2: V1's text (itself V3's plus the reference context) with one section added after the first
 * real reference resume showed three kinds of drift. A new class and version because `resume-wording-reference-v1`
 * is persisted. Schema and every validator are unchanged.
 *
 *  - the approved summary was treated as optional and its TypeScript and Workflow Intelligence sentences were dropped;
 *  - a bullet that cited a backend/API fact never said what was built;
 *  - claims the evidence does not make crept in: breadth ("across client engagements"), causes ("through improved
 *    documentation"), a stronger role ("designed" for "owned the implementation of") and a lost "approximately".
 */
final readonly class ReferenceWordingPromptV2
{
    private ReferenceWordingPrompt $base;

    public function __construct()
    {
        $this->base = new ReferenceWordingPrompt;
    }

    public function version(): string
    {
        return 'resume-wording-reference-v2';
    }

    public function schemaVersion(): string
    {
        return $this->base->schemaVersion();
    }

    public function systemPrompt(): string
    {
        return $this->base->systemPrompt()."\n\n".<<<'PROMPT'
        ## Fidelity to the approved profile and to the evidence

        The approved summary in `reference_context` is the baseline, not a suggestion. Keep every project and every
        technology it names that the cited `summary_evidence` supports (for example a named flagship project, TypeScript,
        a cloud platform). Tighten and reorder freely; drop a named project or technology only if no cited fact states
        it. The summary stays three to four sentences.

        Each bullet says what its cited facts say. When a bullet group cites several facts, the bullet must reflect each
        one: if a fact says what was built (for example the backend, or the APIs integrated), say that in the bullet
        rather than only describing the result. If the facts are too many for one sentence, use the strongest and do not
        cite the rest in your wording.

        Do not add to the evidence:
        - Scope. Do not widen what the work covered ("across client engagements", "for multiple customers") unless a
          cited fact says so.
        - Cause. A measured result may be tied to something only if a cited fact ties them. Do not explain a result
          ("through improved documentation", "by streamlining X") that the cited facts do not connect to it.
        - Role. Keep the strength of the fact's own verb. "Owned the implementation of" is not "designed"; "delivered"
          is not "drove" or "led"; "contributed to" is not "led".
        - Precision. Keep a figure exactly as stated, including "approximately", "+" and ranges.
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
