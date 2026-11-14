<?php

namespace App\Support\JobQualification;

use App\Enums\QualificationEligibility;
use App\Enums\QualificationVerdict;

/**
 * The qualification policy as code. The model proposes a verdict and structured facts; this decides what the facts allow.
 * It exists so the policy is a tested guarantee rather than a hope about a prompt:
 *
 * - an unfamiliar mandatory technology that explicitly needs deep production experience, with no transferable allowance,
 *   is always Poor;
 * - an unfamiliar mandatory technology where equivalents are explicitly welcome lands in NeedsReview..Good;
 * - preferred or incidental technologies never lower a verdict, and Poor needs a structural reason (a deep unfamiliar
 *   primary stack, a non-engineering role, or a role that is primarily management or infrastructure), otherwise it is
 *   raised to NeedsReview;
 * - Strong is reserved for a hands-on product-engineering role with nothing unfamiliar and clear eligibility.
 *
 * A technology the model calls unfamiliar but whose name is one of the candidate's own skills is corrected to familiar: the
 * profile is the authority on what the candidate has, and absence of evidence is never invented.
 *
 * Returns the verdict, the technologies as corrected, and a code for every adjustment so it stays visible.
 */
final class ReconcileVerdict
{
    private const PRODUCT_ROLES = ['product_engineering', 'frontend', 'backend', 'full_stack'];

    /**
     * @param  array<string, mixed>  $assessment  A validated model response.
     * @param  list<string>  $candidateSkillNames
     * @return array{verdict: QualificationVerdict, technologies: list<array<string, mixed>>, adjustments: list<string>}
     */
    public function reconcile(array $assessment, QualificationEligibility $eligibility, array $candidateSkillNames): array
    {
        $adjustments = [];
        $known = array_flip(array_map(self::normalize(...), $candidateSkillNames));

        $technologies = [];
        foreach ($assessment['technologies'] as $technology) {
            if ($technology['familiarity'] !== 'familiar' && isset($known[self::normalize($technology['name'])])) {
                $technology['familiarity'] = 'familiar';
                $adjustments[] = 'familiarity_corrected:'.$technology['name'];
            }
            $technologies[] = $technology;
        }

        $mandatoryUnfamiliar = array_filter($technologies, fn (array $t) => $t['requirement'] === 'mandatory' && $t['familiarity'] === 'unfamiliar');
        $deepUnfamiliar = array_filter($mandatoryUnfamiliar, fn (array $t) => $t['depth'] === 'deep_production' && ! $t['transferable_accepted']);
        $acceptedUnfamiliar = array_filter($mandatoryUnfamiliar, fn (array $t) => $t['transferable_accepted']);
        $unacceptedUnfamiliar = array_filter($mandatoryUnfamiliar, fn (array $t) => ! $t['transferable_accepted']);

        $roleType = $assessment['role_type'];
        $management = $assessment['hands_on'] === 'primarily_management' || $roleType === 'engineering_management';
        $infrastructure = $roleType === 'infrastructure_devops';
        $nonEngineering = $roleType === 'non_engineering';

        $rank = QualificationVerdict::from($assessment['verdict'])->rank();

        if ($deepUnfamiliar !== []) {
            $rank = 0;
            $adjustments[] = 'deep_unfamiliar_primary_stack';
        } elseif ($nonEngineering) {
            $rank = 0;
            $adjustments[] = 'not_an_engineering_role';
        } else {
            if ($management && $rank > 1) {
                $rank = 1;
                $adjustments[] = 'primarily_management_capped';
            }
            if ($infrastructure && $rank > 1) {
                $rank = 1;
                $adjustments[] = 'infrastructure_focus_capped';
            }
            if ($acceptedUnfamiliar !== [] && $unacceptedUnfamiliar === []) {
                $clamped = max(1, min(2, $rank));
                if ($clamped !== $rank) {
                    $rank = $clamped;
                    $adjustments[] = 'transferable_stack_clamped';
                }
            } elseif ($unacceptedUnfamiliar !== [] && $rank > 1) {
                $rank = 1;
                $adjustments[] = 'unfamiliar_mandatory_stack_capped';
            }
            if ($rank === 0 && ! $management && ! $infrastructure) {
                $rank = 1;
                $adjustments[] = 'poor_without_structural_reason_raised';
            }
        }

        if ($rank >= 3 && ! $this->mayBeStrong($roleType, $assessment['hands_on'], $mandatoryUnfamiliar, $eligibility)) {
            $rank = 2;
            $adjustments[] = 'strong_not_supported';
        }

        return ['verdict' => QualificationVerdict::fromRank($rank), 'technologies' => $technologies, 'adjustments' => $adjustments];
    }

    /**
     * @param  array<int, array<string, mixed>>  $mandatoryUnfamiliar
     */
    private function mayBeStrong(string $roleType, string $handsOn, array $mandatoryUnfamiliar, QualificationEligibility $eligibility): bool
    {
        return in_array($roleType, self::PRODUCT_ROLES, true)
            && in_array($handsOn, ['hands_on', 'mixed'], true)
            && $mandatoryUnfamiliar === []
            && $eligibility === QualificationEligibility::Eligible;
    }

    private static function normalize(string $name): string
    {
        return preg_replace('/[^a-z0-9+#]/', '', mb_strtolower($name)) ?? '';
    }
}
