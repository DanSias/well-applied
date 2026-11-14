<?php

namespace App\Support\JobQualification;

use App\Enums\QualificationEligibility;
use App\Enums\QualificationVerdict;

/**
 * The qualification policy as code. The model proposes a verdict and structured facts; this decides what the facts allow.
 * It exists so the policy is a tested guarantee rather than a hope about a prompt:
 *
 * - an unfamiliar mandatory technology that is central to the role and explicitly needs deep production experience, with no
 *   transferable allowance, is always Poor; a mandatory technology that is not central (a clause in a list of duties, a
 *   detail) never caps anything;
 * - an unfamiliar mandatory technology where equivalents are explicitly welcome lands in NeedsReview..Good;
 * - a claim that the role is people management only caps a verdict when the posting sentences the model cited for it
 *   actually describe people-management responsibility; otherwise the claim is set aside as unsupported;
 * - preferred or incidental technologies never lower a verdict, and Poor needs a structural reason (a deep unfamiliar
 *   primary stack, a non-engineering role, or a role that is primarily management or infrastructure), otherwise it is
 *   raised to NeedsReview;
 * - Strong is reserved for a hands-on product-engineering role with nothing unfamiliar and clear eligibility. Downstream
 *   decisions read the reconciled role, not the raw label: a management label that was set aside as unsupported leaves the role
 *   unclassified (neither management nor, by default, application work), and Strong then needs positive evidence of the latter.
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
     * @param  array<string, string>  $segments  Segment id => posting sentence, to check the management claim's cited evidence.
     * @return array{verdict: QualificationVerdict, technologies: list<array<string, mixed>>, adjustments: list<string>}
     */
    public function reconcile(array $assessment, QualificationEligibility $eligibility, array $candidateSkillNames, array $segments = []): array
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

        // Only a technology the role is built around can count against it; the rest stay visible but never cap.
        $mandatoryUnfamiliar = array_filter($technologies, fn (array $t) => $t['requirement'] === 'mandatory' && $t['familiarity'] === 'unfamiliar' && $t['central']);
        $deepUnfamiliar = array_filter($mandatoryUnfamiliar, fn (array $t) => $t['depth'] === 'deep_production' && ! $t['transferable_accepted']);
        $acceptedUnfamiliar = array_filter($mandatoryUnfamiliar, fn (array $t) => $t['transferable_accepted']);
        $unacceptedUnfamiliar = array_filter($mandatoryUnfamiliar, fn (array $t) => ! $t['transferable_accepted']);

        $roleType = $assessment['role_type'];
        $claimsManagement = $assessment['hands_on'] === 'primarily_management' || $roleType === 'engineering_management';
        $management = $claimsManagement && $this->managementIsEvidenced($assessment['management_evidence_refs'], $segments);
        if ($claimsManagement && ! $management) {
            $adjustments[] = 'management_claim_unsupported';
        }
        // A "primarily management" label with no cited people-management duties is no evidence of either; it is not read as hands-on.
        $handsOn = $assessment['hands_on'] === 'primarily_management' && ! $management ? 'unclear' : $assessment['hands_on'];
        // The role type as the evidence leaves it: an engineering_management label with no cited duties is set aside, which leaves the
        // real type unknown (null), not product engineering and not management. Downstream decisions read this, never the raw label.
        $effectiveRoleType = $roleType === 'engineering_management' && ! $management ? null : $roleType;
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

        if ($rank >= 3 && ! $this->mayBeStrong($effectiveRoleType, $handsOn, $technologies, $mandatoryUnfamiliar, $eligibility)) {
            $rank = 2;
            $adjustments[] = 'strong_not_supported';
        }

        return ['verdict' => QualificationVerdict::fromRank($rank), 'technologies' => $technologies, 'adjustments' => $adjustments];
    }

    /**
     * Whether any sentence the model cited for "this is people management" really describes people-management duties.
     *
     * @param  list<string>  $refs
     * @param  array<string, string>  $segments
     */
    private function managementIsEvidenced(array $refs, array $segments): bool
    {
        foreach ($refs as $ref) {
            foreach (QualificationPolicy::PEOPLE_MANAGEMENT_EVIDENCE as $pattern) {
                if (isset($segments[$ref]) && preg_match($pattern, $segments[$ref]) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Strong is reserved for a hands-on application-engineering role with nothing unfamiliar and clear eligibility.
     *
     * A null role type means the model's label was set aside as unsupported, so the role is unclassified. Being unclassified is
     * not a promotion: Strong then needs positive evidence that this is hands-on application work, namely the model saying the
     * job is hands-on (not mixed or unclear) AND the job being built around a technology the candidate demonstrably has.
     *
     * @param  list<array<string, mixed>>  $technologies
     * @param  array<int, array<string, mixed>>  $mandatoryUnfamiliar
     */
    private function mayBeStrong(?string $roleType, string $handsOn, array $technologies, array $mandatoryUnfamiliar, QualificationEligibility $eligibility): bool
    {
        $roleIsApplicationWork = $roleType === null
            ? $handsOn === 'hands_on' && array_filter($technologies, fn (array $t) => $t['requirement'] === 'mandatory' && $t['central'] && $t['familiarity'] === 'familiar') !== []
            : in_array($roleType, self::PRODUCT_ROLES, true) && in_array($handsOn, ['hands_on', 'mixed'], true);

        return $roleIsApplicationWork
            && $mandatoryUnfamiliar === []
            && $eligibility === QualificationEligibility::Eligible;
    }

    private static function normalize(string $name): string
    {
        return preg_replace('/[^a-z0-9+#]/', '', mb_strtolower($name)) ?? '';
    }
}
