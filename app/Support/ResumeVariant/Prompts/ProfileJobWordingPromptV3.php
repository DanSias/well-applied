<?php

namespace App\Support\ResumeVariant\Prompts;

/**
 * Wording for a resume tailored to one job from an approved profile, third version. A new class and version (V2 is a
 * persisted `wording_prompt_version`): V2's text and schema are reused whole and one section is added for the editorial plan
 * the input now carries, so bullets are written as parts of one career story and not as isolated lines. Every validator is
 * unchanged; the plan adds no evidence.
 */
final readonly class ProfileJobWordingPromptV3
{
    private ProfileJobWordingPromptV2 $base;

    public function __construct()
    {
        $this->base = new ProfileJobWordingPromptV2;
    }

    public function version(): string
    {
        return 'resume-wording-profile-job-v3';
    }

    public function schemaVersion(): string
    {
        return $this->base->schemaVersion();
    }

    public function systemPrompt(): string
    {
        return $this->base->systemPrompt()."\n\n".<<<'PROMPT'
        ## Editorial plan: write the resume as one story

        The input carries an `editorial_plan` (`career_narrative`, `ownership_in`) and each bullet group carries `editorial`:
        `why_it_matters` (the job requirement it answers), `demonstrates` (design, implementation, integration, delivery,
        collaboration, outcome), `adjacent_bullets_establish` (what the bullets before and after it already show) and
        `do_not_repeat`. The plan tells you how to write a bullet, never what it may claim: claims still come only from the
        bullet's own facts.

        - Write each bullet to do its own job in the story. Lead with the dimension it demonstrates and, where its facts
          allow, with what the job asks for. Do not restate what an adjacent bullet establishes about the same project.
        - Never repeat a result or a claim listed in `do_not_repeat`. A figure belongs in the one bullet that earns it.
        - Only the bullet named in `ownership_in` may state ownership of the work. Every other bullet shows the work itself.
        - Vary how bullets open. Do not open two bullets in a row with the same verb, and do not use "Responsible for",
          "Provided technical leadership", "Spearheaded" or similar leadership language the facts do not state.
        - Use a technology's name once where it matters; never list technologies to fill a sentence.
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

    public function additionSystemPrompt(): string
    {
        return <<<'PROMPT'
        You write resume bullets, nothing else. Each item in `additions` is one accomplishment that is missing from a resume;
        write exactly one bullet for each, using only the facts listed for it. Return JSON: `bullets`, one {id, text} per item.

        - One sentence of about 20 to 32 words. Each fact has a `role` (built, implementation, deployment, outcome): say what
          was built and how, then where or by whom it was used, then what it achieved, using only the parts its facts state.
        - Say what the facts say and no more. Do not turn a deployment into adoption scale, an outcome into a cause the facts
          do not state, or a contribution into leadership. Keep every figure exactly as stated.
        - Name only technologies the facts name. Never add a technology, number, scope or responsibility they do not state.
        - Do not repeat a figure listed in `figures_already_on_resume`, and do not open with a word in `openings_already_used`.
        - Open with a specific verb for the work. No "Responsible for", no "Provided technical leadership", no em dashes, no
          first person, no filler such as "passionate" or "results-driven".
        PROMPT;
    }

    /**
     * @param  array<string, mixed>  $input  job, additions[], figures_already_on_resume, openings_already_used
     */
    public function additionUserPrompt(array $input): string
    {
        return "--- Missing accomplishments ---\n"
            .json_encode($input, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            ."\n--- End of missing accomplishments ---";
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<string, mixed>
     */
    public function additionSchema(array $ids): array
    {
        return [
            'type' => 'object',
            'properties' => ['bullets' => [
                'type' => 'array', 'minItems' => count($ids), 'maxItems' => count($ids),
                'items' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer', 'enum' => array_values($ids)], 'text' => ['type' => 'string']], 'required' => ['id', 'text'], 'additionalProperties' => false],
            ]],
            'required' => ['bullets'],
            'additionalProperties' => false,
        ];
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
}
