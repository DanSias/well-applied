<?php

namespace App\Support\ResumeVariant;

use App\Models\CareerProfile;
use App\Support\Clarification\ActiveLimitations;
use App\Support\Clarification\DeclaredScopes;

/**
 * The evidence-derived inputs every wording check needs (restricted facts, evidence-bearing text, guardrails, the
 * skills each fact authorizes, the user's limitations and declared scopes), built once from the eligible facts. Resume
 * generation and resume edits both build their WordingCheckContext from this, so an edited sentence is judged by
 * exactly the rules a generated one is.
 */
final class WordingContextInputs
{
    public function __construct(private readonly ResumeWordingResponseValidator $validator) {}

    /**
     * @param  array<string, array<string, mixed>>  $factsByKey  eligible facts (ResumeCandidatePayloadBuilder), keyed by fact key
     * @param  array<int, array<string, mixed>>  $eligibleSkills
     * @return array{guardrailByFactKey: array<string, mixed>, restrictedFactKeys: array<int, string>, factTextByKey: array<string, string>, skillIdsByFactKey: array<string, array<int, int>>, canonicalSkillsById: array<int, string>, textRecognizedSkillIdsByFactKey: array<string, array<int, int>>, restrictedTerms: array<int, string>, genericTerms: array<int, string>, limitationTerms: array<int, string>, declaredScopesByKey: array<string, array<int, string>>}
     */
    public function build(CareerProfile $profile, array $factsByKey, array $eligibleSkills): array
    {
        $guardrailByFactKey = array_map(fn (array $fact) => $fact['metric']['guardrail'] ?? null, $factsByKey);
        $restrictedFactKeys = array_keys(array_filter($factsByKey, fn (array $fact) => $fact['visibility'] === 'restricted'));
        $factTextByKey = array_map(fn (array $fact) => trim($fact['statement'].' '.($fact['metric']['scope_note'] ?? '').' '.($fact['metric']['guardrail'] ?? '').' '.(isset($fact['metric']['value']) ? (string) $fact['metric']['value'] : '')), $factsByKey);
        $skillIdsByFactKey = array_map(fn (array $fact) => array_column($fact['skills'], 'id'), $factsByKey);
        $canonicalSkillsById = array_column($eligibleSkills, 'name', 'id');

        // A canonical Skill genuinely named in a fact's own evidence-bearing text is positive evidence for that fact.
        $textRecognizedSkillIdsByFactKey = array_map(
            fn (array $fact) => $this->validator->recognizedSkillIds(trim($fact['statement'].' '.($fact['metric']['scope_note'] ?? '').' '.($fact['metric']['guardrail'] ?? '')), $canonicalSkillsById),
            $factsByKey,
        );

        return [
            'guardrailByFactKey' => $guardrailByFactKey,
            'restrictedFactKeys' => $restrictedFactKeys,
            'factTextByKey' => $factTextByKey,
            'skillIdsByFactKey' => $skillIdsByFactKey,
            'canonicalSkillsById' => $canonicalSkillsById,
            'textRecognizedSkillIdsByFactKey' => $textRecognizedSkillIdsByFactKey,
            'restrictedTerms' => (array) config('resume.restricted_terms', []),
            'genericTerms' => (array) config('resume.generic_terms', []),
            'limitationTerms' => ActiveLimitations::terms($profile),
            'declaredScopesByKey' => DeclaredScopes::forFacts($profile, array_keys($factsByKey)),
        ];
    }

    /**
     * The check context for edited text: no selection structure to validate, only the cited evidence per location.
     *
     * @param  array<string, mixed>  $inputs  build()
     * @param  array<string, array<int, string>>  $careerFactKeysByLocation
     */
    public function contextFor(array $inputs, array $careerFactKeysByLocation): WordingCheckContext
    {
        return new WordingCheckContext(
            validRoleIds: [],
            expectedBulletGroupIndexesByRole: [],
            validSelectedProjectIds: [],
            denylistTerms: [],
            careerFactKeysByLocation: $careerFactKeysByLocation,
            guardrailByFactKey: $inputs['guardrailByFactKey'],
            skillIdsByFactKey: $inputs['skillIdsByFactKey'],
            canonicalSkillsById: $inputs['canonicalSkillsById'],
            textRecognizedSkillIdsByFactKey: $inputs['textRecognizedSkillIdsByFactKey'],
            directTargetTermsByLocation: [],
            restrictedFactKeys: $inputs['restrictedFactKeys'],
            factTextByKey: $inputs['factTextByKey'],
            restrictedTerms: $inputs['restrictedTerms'],
            genericTerms: $inputs['genericTerms'],
            limitationTerms: $inputs['limitationTerms'],
            declaredScopesByKey: $inputs['declaredScopesByKey'],
            claimFidelity: true,
        );
    }
}
