<?php

namespace App\Support\ResumeVariant\Prompts;

/**
 * Wording for a resume tailored to one job from an approved profile, second version. A new class and version (never an
 * edit of V1, which is a persisted `wording_prompt_version`): V1's text is reused whole and two sections are added.
 *
 *   Summary   a length and a job, so a summary is neither one generic sentence nor a list of the bullets; it may name only
 *             what its own evidence supports (the evidence contract the validators already enforce, now stated up front).
 *   Bullets   a bullet group that combines several facts is one accomplishment (what was built, how, where it was used,
 *             what it achieved), written with confident verbs, and the same ownership claim is not made twice.
 *
 * Schema and every validator are V1's. This class also owns the narrow prompt for correcting a summary alone (see
 * CorrectSummary), so the wording guidance lives in one place.
 */
final readonly class ProfileJobWordingPromptV2
{
    private ProfileJobWordingPromptV1 $base;

    public function __construct()
    {
        $this->base = new ProfileJobWordingPromptV1;
    }

    public function version(): string
    {
        return 'resume-wording-profile-job-v2';
    }

    public function schemaVersion(): string
    {
        return $this->base->schemaVersion();
    }

    public function systemPrompt(): string
    {
        return $this->base->systemPrompt()."\n\n".<<<'PROMPT'
        ## Summary: length, evidence and voice

        - Write about 45 to 65 words in two to four sentences. Do not pad a strong shorter summary and do not cut a
          strong one to fit.
        - Position the candidate for this role in the first sentence, then give two or three concrete strengths that
          the cited `summary_evidence` states (what was built, the kind of problem solved, who it served).
        - Name only technologies listed in `summary_authorized_skills` and only figures that a `summary_evidence` fact
          states. A technology or number that appears only in a bullet's facts does not belong in the summary.
        - Do not restate a bullet. The summary says what kind of engineer this is; the bullets prove it.
        - Natural, confident resume voice. No first person, no hedging, no "passionate" or "results-driven".

        ## Bullets: one complete accomplishment each

        - When a bullet group has several facts, they describe one piece of work. Each fact has a `role` in
          `fact_roles` (built, implementation, deployment, outcome). Write one sentence that says what was built and
          how, then where or by whom it was used, then what it achieved, using only the facts that are present. A group
          with fewer facts needs fewer parts; never invent a missing part.
        - Say what the facts say and no more. Do not turn a deployment into adoption scale, an outcome into a cause the
          facts do not state, or a contribution into leadership.
        - Open with a specific verb for the work (built, designed, integrated, automated, deployed). Do not begin more
          than one bullet with "Independently" or make the same ownership claim twice on the resume; state ownership
          once, where it is strongest, and let the other bullets show the work.
        - Do not describe the same project in two bullets in the same terms. A second bullet about a project must add
          a different dimension (design, integration, operation or result).
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

    public function correctionSystemPrompt(): string
    {
        return <<<'PROMPT'
        You rewrite one thing: the professional summary of a resume, after an earlier summary lost content because part of
        it was not supported by the candidate's evidence. Return JSON with a single `summary` string.

        - About 45 to 65 words in two to four sentences. A strong shorter summary is fine; do not pad.
        - Position the candidate for the target role first, then give two or three concrete strengths.
        - Use only the facts in `summary_evidence`. Name only technologies in `summary_authorized_skills` and only figures
          a `summary_evidence` fact states. Never add a technology, number, scope, leadership or responsibility they do
          not state, and never hint at anything in `not_supported`.
        - Fix exactly the problems in `problems` by saying less or saying it from the evidence; do not repeat them.
        - Natural, confident resume voice. No first person, no em dashes, no hedging, no filler such as "passionate".
        PROMPT;
    }

    /**
     * @param  array<string, mixed>  $input  target, summary_evidence, summary_authorized_skills, not_supported, problems, surviving_text
     */
    public function correctionUserPrompt(array $input): string
    {
        return "--- Summary correction input ---\n"
            .json_encode($input, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            ."\n--- End of summary correction input ---";
    }

    /**
     * @return array<string, mixed>
     */
    public function correctionSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['summary' => ['type' => 'string']],
            'required' => ['summary'],
            'additionalProperties' => false,
        ];
    }
}
