<?php

namespace App\Support\Expansion;

use App\Enums\ProposalKind;
use App\Enums\ProposalStatus;
use App\Models\CareerFact;
use App\Models\EvidenceProposal;
use App\Support\ResumeVariant\ResumeEligibility;

/**
 * How a proposed claim relates to what is already recorded on the same place, so duplicates are
 * visible before approval:
 *   same_topic       an approved fact on this capability and place already exists (approving needs an
 *                    explicit "replace", which revises it and keeps its history)
 *   near_duplicate   another eligible fact there says almost the same thing
 * Private facts are never compared or shown.
 */
final class ExpansionDuplicates
{
    public function __construct(private readonly ExpansionValidator $validator) {}

    /**
     * @return array{relation: string, fact_key: string, statement: string}|null
     */
    public function relation(EvidenceProposal $proposal): ?array
    {
        if ($proposal->attributable_type === null || $proposal->attributable_id === null) {
            return null;
        }

        $same = EvidenceProposal::query()
            ->where('career_profile_id', $proposal->career_profile_id)->where('kind', ProposalKind::Fact)->where('status', ProposalStatus::Approved)
            ->where('topic_key', $proposal->topic_key)->where('attributable_type', $proposal->attributable_type)->where('attributable_id', $proposal->attributable_id)
            ->whereHas('careerFact')->with('careerFact')->latest('id')->first();

        if ($same !== null && ResumeEligibility::isEligible($same->careerFact)) {
            return ['relation' => 'same_topic', 'fact_key' => $same->careerFact->key, 'statement' => $same->careerFact->statement];
        }

        $facts = ResumeEligibility::eligibleCareerFactsQuery($proposal->careerProfileModel())
            ->where('attributable_type', $proposal->attributable_type)->where('attributable_id', $proposal->attributable_id)->get();

        foreach ($facts as $fact) {
            /** @var CareerFact $fact */
            if ($this->validator->similarity($proposal->statement, $fact->statement) >= 0.55) {
                return ['relation' => 'near_duplicate', 'fact_key' => $fact->key, 'statement' => $fact->statement];
            }
        }

        return null;
    }
}
