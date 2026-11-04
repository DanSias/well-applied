<?php

namespace App\Support\ResumeVariant\Prompts;

/**
 * Reference wording V3: V2's text plus one rule about support-only evidence. A new class and version because
 * `resume-wording-reference-v2` is persisted. Some approved facts are written in the first person ("I've used
 * JavaScript extensively…"); they may be cited in the summary evidence, marked `support_only`, to show what the summary
 * is allowed to claim. Schema and validators are unchanged, and a first-person sentence is also refused afterwards.
 */
final readonly class ReferenceWordingPromptV3
{
    private ReferenceWordingPromptV2 $base;

    public function __construct()
    {
        $this->base = new ReferenceWordingPromptV2;
    }

    public function version(): string
    {
        return 'resume-wording-reference-v3';
    }

    public function schemaVersion(): string
    {
        return $this->base->schemaVersion();
    }

    public function systemPrompt(): string
    {
        return $this->base->systemPrompt()."\n\n".<<<'PROMPT'
        ## Support-only evidence

        A summary evidence fact marked `"support_only": true` is written in the first person. It tells you what the
        summary may claim (for example that JavaScript is part of the candidate's experience). Never copy its wording and
        never write in the first person: state the capability in the summary's own resume voice.
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
