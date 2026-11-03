<?php

namespace App\Support\ResumeVariant\Prompts;

/**
 * A text-only revision of ResumeSelectionPromptV5 (a new class, since
 * `resume-selection-v5` is a persisted version). V5's text is reused
 * verbatim and one section is appended.
 *
 * Why (Sprint 2 investigation): the Spotify resume gave Liquid Gravity
 * a single bullet although 10 eligible role facts existed. It was not
 * an eligibility rule or a cap: Selection merged five facts (the
 * technical build and both measured outcomes) into one bullet group and
 * left the rest unused, because V5 said weakly relevant roles get "one
 * modest bullet group" and to group reinforcing facts. V6 adds guidance
 * to keep technical implementation and measurable business impact as
 * separate bullet groups when the evidence states them separately, and
 * to treat a role's one-bullet floor as a floor, never a ceiling. No
 * quota is introduced; response shape and validator are unchanged.
 */
final readonly class ResumeSelectionPromptV6
{
    private ResumeSelectionPromptV5 $base;

    public function __construct()
    {
        $this->base = new ResumeSelectionPromptV5;
    }

    public function version(): string
    {
        return 'resume-selection-v6';
    }

    public function schemaVersion(): string
    {
        return $this->base->schemaVersion();
    }

    public function systemPrompt(): string
    {
        return $this->base->systemPrompt()."\n\n".<<<'PROMPT'
        ## Implementation versus impact, and the one-bullet floor

        The "one modest bullet group" for a weakly relevant role is a
        floor, never a ceiling. A role with several distinct, supported
        accomplishments that show breadth the job values (full-stack
        delivery, integrations, automation, ecommerce or payment flows,
        analytics, working directly with stakeholders) should carry more
        than one bullet group even when the role is older or only partly
        relevant. Do not leave a distinct, relevant accomplishment unused
        merely because the role is not the job's closest match.

        When a role's CareerFacts state technical implementation and, as
        separate facts, a measurable business result, do not merge them
        into one bullet group. Put the implementation facts (what was
        built and with what) in one bullet group and the quantified
        outcome facts in another, so each reads as its own clear claim.
        Combine facts only when they genuinely describe the same single
        accomplishment; never combine unrelated facts to reduce bullet
        count. Each CareerFact still appears in only one place, and no
        quota applies: include a bullet group only when it adds a distinct,
        supported claim.
        PROMPT;
    }

    /**
     * @param  array<string, mixed>  $candidatePayload
     * @param  array<string, mixed>  $jobPayload
     * @param  array<int, array<string, mixed>>  $targetTerminology
     * @param  array<int, array{topic_key: string, topic: string, guidance: string}>  $presentationPreferences  User-approved emphasis guidance. With none, the prompt is byte-identical to before.
     */
    public function userPrompt(array $candidatePayload, array $jobPayload, array $targetTerminology, array $presentationPreferences = []): string
    {
        $prompt = $this->base->userPrompt($candidatePayload, $jobPayload, $targetTerminology);

        if ($presentationPreferences === []) {
            return $prompt;
        }

        $json = json_encode($presentationPreferences, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // The rules travel with the block so the persisted system prompt
        // text stays untouched and a job without preferences is unchanged.
        return $prompt."\n\n--- Presentation preferences (emphasis guidance only) ---\n"
            ."{$json}\n"
            ."Rules for these preferences:\n"
            ."- They are the candidate's wishes about EMPHASIS. They are not facts and add no evidence.\n"
            ."- Use them only to choose and order among CareerFacts that are already eligible and relevant to THIS job: when facts are comparably relevant, prefer and place first the one a preference favors.\n"
            ."- Job relevance always comes first. Never select a CareerFact, Skill or Project only because a preference mentions its topic, and never leave out a clearly more relevant fact for it.\n"
            ."- Never cite a preference as evidence, and never assume it describes something the candidate did. Every claim still needs a cited CareerFact.\n"
            .'--- End of presentation preferences ---';
    }

    /**
     * @param  array<int, int>  $validRoleIds
     * @param  array<int, string>  $validFactKeys
     * @param  array<int, int>  $validSkillIds
     * @param  array<int, int>  $validFindingIds
     * @param  array<int, string>  $validTargetTerms
     * @param  array<int, string>  $validTitleChoiceKeys
     * @param  array<int, int>  $validProjectIds
     * @return array<string, mixed>
     */
    public function jsonSchema(
        array $validRoleIds,
        array $validFactKeys,
        array $validSkillIds,
        array $validFindingIds,
        array $validTargetTerms,
        array $validTitleChoiceKeys,
        array $validProjectIds,
    ): array {
        return $this->base->jsonSchema(
            $validRoleIds, $validFactKeys, $validSkillIds, $validFindingIds,
            $validTargetTerms, $validTitleChoiceKeys, $validProjectIds,
        );
    }
}
