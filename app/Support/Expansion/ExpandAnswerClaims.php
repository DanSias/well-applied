<?php

namespace App\Support\Expansion;

use App\Enums\ProposalKind;
use App\Enums\ProposalStatus;
use App\Enums\Verification;
use App\Enums\Visibility;
use App\Models\CareerFact;
use App\Models\CareerProfile;
use App\Models\ClarificationAnswer;
use App\Models\EvidenceExpansion;
use App\Models\EvidenceProposal;
use App\Support\Clarification\EvidenceRevision;
use App\Support\ResumeProfile\QuestionWriter;
use App\Support\ResumeVariant\ResumeEligibility;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Reads one clarification answer into separate, individually reviewable claims.
 *
 * Optional and user-triggered. The model (the existing local model, through QuestionWriter)
 * proposes claims; every one is validated against the answer (ExpansionValidator) and becomes
 * EvidenceProposals in the normal `proposed` state: nothing is approved, nothing existing is
 * changed. A claim spanning several projects is several proposals sharing a `claim_group`, each
 * carrying the exact source quote and the original answer.
 *
 * Cached by fingerprint (prompt version, the answer and its scopes, the evidence revision, and the
 * attribution context), so asking again for an unchanged answer costs nothing. A newer run
 * supersedes only the earlier run's still-undecided proposals for the same answer. If the call
 * fails the answer's original single proposal is untouched and remains the fallback.
 */
final class ExpandAnswerClaims
{
    public function __construct(
        private readonly ExpansionContext $context,
        private readonly ExpansionValidator $validator,
        private readonly QuestionWriter $writer,
    ) {}

    public function expand(ClarificationAnswer $answer, bool $force = false): EvidenceExpansion
    {
        $profile = $answer->question->session->ownerCareerProfile();
        $ctx = $this->context->build($answer);
        $fingerprint = hash('sha256', json_encode([ExpansionPrompt::VERSION, $ctx, EvidenceRevision::current($profile)], JSON_THROW_ON_ERROR));

        if (! $force) {
            $cached = EvidenceExpansion::query()->where('clarification_answer_id', $answer->id)->where('fingerprint', $fingerprint)->where('status', 'ready')->latest('id')->first();

            if ($cached !== null) {
                return $cached;
            }
        }

        $base = [
            'career_profile_id' => $profile->id, 'clarification_answer_id' => $answer->id, 'fingerprint' => $fingerprint,
            'prompt_version' => ExpansionPrompt::VERSION, 'evidence_revision' => EvidenceRevision::current($profile),
        ];

        if ($answer->mode !== 'experience') {
            return EvidenceExpansion::create([...$base, 'status' => 'failed', 'failure' => 'Only an experience answer can be expanded.']);
        }

        $started = microtime(true);

        try {
            $response = $this->writer->write(ExpansionPrompt::system(), ExpansionPrompt::user($ctx), ExpansionPrompt::schema($ctx));
        } catch (Throwable $e) {
            Log::warning('Evidence expansion failed; the original proposal is unchanged.', ['exception' => $e::class, 'answer_id' => $answer->id]);

            return EvidenceExpansion::create([...$base, 'status' => 'failed', 'latency_ms' => (int) round((microtime(true) - $started) * 1000), 'failure' => 'The model call failed. Your original proposal is unchanged.']);
        }

        $latency = (int) round((microtime(true) - $started) * 1000);
        $checked = $this->validator->validate($response['content'], $ctx);

        return DB::transaction(function () use ($answer, $base, $response, $latency, $checked, $profile) {
            $expansion = EvidenceExpansion::create([
                ...$base, 'status' => 'ready', 'model' => $response['model'], 'latency_ms' => $latency, 'usage' => $response['usage'],
                'result' => ['claims' => $checked['claims'], 'followups' => $checked['followups'], 'rejected' => $checked['rejected']],
            ]);

            // An earlier run's undecided claims for this answer are replaced; decided ones are never touched.
            foreach (EvidenceProposal::query()->where('clarification_answer_id', $answer->id)->whereNotNull('expansion_id')->where('status', ProposalStatus::Proposed)->get() as $old) {
                $old->status = ProposalStatus::Superseded;
                $old->recordHistory('superseded', ['by_expansion_id' => $expansion->id]);
                $old->save();
            }

            foreach ($checked['claims'] as $claim) {
                $group = (string) Str::uuid();

                foreach ($claim['attributions'] as $target) {
                    [$type, $id] = explode(':', $target);
                    $proposal = new EvidenceProposal([
                        'career_profile_id' => $profile->id, 'clarification_answer_id' => $answer->id, 'expansion_id' => $expansion->id, 'claim_group' => $group,
                        'job_posting_id' => $answer->question->session->job_posting_id, 'kind' => ProposalKind::Fact, 'status' => ProposalStatus::Proposed,
                        'topic_key' => $claim['capability'], 'topic_label' => (string) config("resume_profiles.capabilities.{$claim['capability']}.label"),
                        'statement' => $claim['statement'], 'scopes' => $claim['scopes'], 'attributable_type' => $type, 'attributable_id' => (int) $id,
                        // A suggestion only: approval still requires an explicit visibility.
                        'visibility' => $this->suggestedVisibility($profile->id, $type, (int) $id), 'verification' => Verification::StronglySupported,
                        'source_quote' => $claim['source_quote'], 'confidence' => $claim['confidence'], 'rationale' => $claim['rationale'], 'history' => [],
                    ]);
                    $proposal->recordHistory('proposed', ['answer_id' => $answer->id, 'expansion_id' => $expansion->id, 'source_quote' => $claim['source_quote']]);
                    $proposal->save();
                }
            }

            return $expansion;
        });
    }

    /**
     * The most restrictive visibility among the place's existing eligible facts, else Restricted.
     */
    private function suggestedVisibility(int $profileId, string $type, int $id): Visibility
    {
        $rank = ['public' => 0, 'restricted' => 1];
        $levels = ResumeEligibility::eligibleCareerFactsQuery(CareerProfile::query()->findOrFail($profileId))
            ->where('attributable_type', $type)->where('attributable_id', $id)->get()->map(fn (CareerFact $f) => $f->visibility->value)->all();

        if ($levels === []) {
            return Visibility::Restricted;
        }

        usort($levels, fn (string $a, string $b) => ($rank[$b] ?? 1) <=> ($rank[$a] ?? 1));

        return Visibility::from($levels[0]);
    }
}
