<?php

namespace App\Support\ResumeVariant;

/**
 * Everything ResumeWordingResponseValidator needs to judge generated
 * text, bundled once so strict validation, triage and recovery re-checks
 * all use identical inputs.
 */
final readonly class WordingCheckContext
{
    /**
     * @param  array<int, int>  $validRoleIds
     * @param  array<int, array<int, int>>  $expectedBulletGroupIndexesByRole
     * @param  array<int, int>  $validSelectedProjectIds
     * @param  array<int, string>  $denylistTerms
     * @param  array<string, array<int, string>>  $careerFactKeysByLocation
     * @param  array<string, string|null>  $guardrailByFactKey
     * @param  array<string, array<int, int>>  $skillIdsByFactKey
     * @param  array<int, string>  $canonicalSkillsById
     * @param  array<string, array<int, int>>  $textRecognizedSkillIdsByFactKey
     * @param  array<string, array<int, string>>  $directTargetTermsByLocation
     * @param  array<int, string>  $restrictedFactKeys
     * @param  array<string, string>  $factTextByKey
     * @param  array<int, string>  $restrictedTerms
     * @param  array<int, string>  $genericTerms
     * @param  array<int, string>  $limitationTerms  Terms the user has confirmed they have no direct experience with; never claimable. See App\Models\CareerLimitation.
     * @param  array<string, array<int, string>>  $declaredScopesByKey  Fact key => the ResponsibilityScope values the user selected when confirming it. Used by ClaimScopeChecker.
     */
    public function __construct(
        public array $validRoleIds,
        public array $expectedBulletGroupIndexesByRole,
        public array $validSelectedProjectIds,
        public array $denylistTerms,
        public array $careerFactKeysByLocation,
        public array $guardrailByFactKey,
        public array $skillIdsByFactKey,
        public array $canonicalSkillsById,
        public array $textRecognizedSkillIdsByFactKey,
        public array $directTargetTermsByLocation,
        public array $restrictedFactKeys,
        public array $factTextByKey,
        public array $restrictedTerms,
        public array $genericTerms,
        public array $limitationTerms = [],
        public array $declaredScopesByKey = [],
    ) {}
}
