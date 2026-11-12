<?php

namespace App\Support\ResumeVariant\Prompts;

/**
 * Wording for a resume tailored to one job from an approved profile, fourth version. A new class and version (V3 is a
 * persisted `wording_prompt_version`). Same schema and the same validators; what changes is what the model has to read.
 *
 * The live test of V3 sent a 21.6k-token prompt and the model spent its whole output budget reasoning over it (one empty
 * reply, one truncation). Measured, the cost was:
 *   - the evidence was pretty-printed JSON: more than twice the characters of the same JSON compact;
 *   - three sections of the inherited text do not apply to a resume for a job with no target terms (the target-term
 *     guidance and deny list, and the "reference resume" framing that the job section itself says it overrides), and the
 *     reference framing told the model to lead the summary with end-to-end ownership, which is what it did;
 *   - the summary section asked for three to four sentences, then for 45 to 65 words, then for a number to lead with.
 * V4 removes the inapplicable sections from the inherited text (every other rule is kept word for word), replaces the
 * summary instructions with one that asks for specialization, systems and problems, and sends the evidence compactly.
 */
final readonly class ProfileJobWordingPromptV4
{
    private ProfileJobWordingPromptV3 $base;

    public function __construct()
    {
        $this->base = new ProfileJobWordingPromptV3;
    }

    public function version(): string
    {
        return 'resume-wording-profile-job-v4';
    }

    public function schemaVersion(): string
    {
        return $this->base->schemaVersion();
    }

    public function systemPrompt(): string
    {
        $inherited = (string) preg_replace(
            '/^## (?:Direct target-term guidance|The target-term deny list|Reference resume context)[^\n]*\n.*?(?=^## |\z)/ms',
            '',
            $this->base->systemPrompt(),
        );

        return rtrim($inherited)."\n\n".<<<'PROMPT'
        ## Summary: specialization, systems and problems (replaces every earlier summary instruction)

        - Two to four sentences, about 45 to 65 words. Do not pad a strong shorter summary.
        - Establish, in this order: the engineering specialization the job asks for; two or three specific technical
          strengths (name only technologies in `summary_authorized_skills`); the kinds of systems this candidate has
          delivered; the kinds of problems they solve. Use only facts listed in `summary_evidence_keys`.
        - Do not lean on a claim of ownership or leadership ("owned", "end-to-end ownership", "technical leadership").
          Say what was built and how it was used.
        - Do not state a number or percentage in the summary; results belong to the bullets that earn them.
        - Facts named in `summary_evidence_keys` are shown in full inside the bullet groups, or in `summary_evidence`.
        PROMPT;
    }

    /**
     * The same delimited block the earlier versions use, with the evidence as compact JSON and no empty deny list.
     *
     * @param  array<string, mixed>  $approvedSelection
     * @param  array<int, string>  $denylistTerms
     */
    public function userPrompt(array $approvedSelection, array $denylistTerms): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        $prompt = "--- Approved selection (evidence, structure, exactly what to word) ---\n"
            .json_encode($approvedSelection, $flags)."\n"
            .'--- End of approved selection ---';

        return $denylistTerms === [] ? $prompt : $prompt."\n\n--- denylist_terms (must never appear in your free text) ---\n".json_encode($denylistTerms, $flags)."\n--- End of denylist_terms ---";
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

    public function correctionSystemPrompt(): string
    {
        return $this->base->correctionSystemPrompt();
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function correctionUserPrompt(array $input): string
    {
        return $this->base->correctionUserPrompt($input);
    }

    /**
     * @return array<string, mixed>
     */
    public function correctionSchema(): array
    {
        return $this->base->correctionSchema();
    }

    public function additionSystemPrompt(): string
    {
        return $this->base->additionSystemPrompt();
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function additionUserPrompt(array $input): string
    {
        return $this->base->additionUserPrompt($input);
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<string, mixed>
     */
    public function additionSchema(array $ids): array
    {
        return $this->base->additionSchema($ids);
    }
}
