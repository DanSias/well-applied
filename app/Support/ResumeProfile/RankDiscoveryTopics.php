<?php

namespace App\Support\ResumeProfile;

use App\Enums\ProposalKind;
use App\Enums\ProposalStatus;
use App\Models\ClarificationQuestion;
use App\Models\EvidenceProposal;
use App\Models\PresentationPreference;
use App\Models\ResumeProfile;

/**
 * Orders the market's capabilities by how much it is worth asking about them.
 *
 *   score = importance x gap x answerability x usefulness
 *
 *   importance     how much of the sample asks for it (required counts most;
 *                  the senior-level share is used when it is higher)
 *   gap            how little the approved record already says
 *   answerability  how likely the user holds undocumented experience: related
 *                  or thinly documented evidence makes that likelier than
 *                  nothing nearby
 *   usefulness     how much an answer can change a resume (responsibilities
 *                  and integrations more than a tool name)
 *
 * Nothing already documented is asked about. Suppressed: supported
 * capabilities, confirmed limitations, topics with approved evidence or a
 * presentation preference, and topics skipped inside the cooldown. Weights
 * live in config/resume_profiles.php. Deterministic; no model call.
 *
 * @phpstan-type Candidate array{key: string, score: float, breakdown: array<string, mixed>, status: string}
 */
final class RankDiscoveryTopics
{
    /**
     * @param  array<string, mixed>  $aggregate
     * @param  array<string, mixed>  $coverage
     * @return array<int, array<string, mixed>>
     */
    public function rank(ResumeProfile $profile, array $aggregate, array $coverage): array
    {
        $cfg = (array) ProfileDefinitions::config($profile->slug)['interview'];
        $weights = (array) config('resume_profiles.ranking');
        $suppressed = $this->suppressedTopics($profile, (int) $cfg['skip_cooldown_days']);
        $n = max(1, (int) ($aggregate['postings'] ?? 0));
        $ranked = [];

        foreach ((array) ($aggregate['capabilities'] ?? []) as $key => $market) {
            $status = $coverage['capabilities'][$key]['status'] ?? CapabilityCoverage::NONE;

            if (in_array($status, [CapabilityCoverage::DIRECT, CapabilityCoverage::LIMITATION], true) || isset($suppressed[$key])) {
                continue;
            }

            if ($market['postings'] < (int) $cfg['min_postings'] || $market['share'] < (float) $cfg['min_share']) {
                continue;
            }

            $importance = max((float) $market['importance'], (float) ($market['senior_importance'] ?? 0));
            $gap = (float) $weights['gap'][$status];
            $answerability = (float) $weights['answerability'][$status === CapabilityCoverage::NONE ? ($this->hasAdjacent($key, $coverage) ? 'no_evidence_with_adjacent' : 'no_evidence') : $status];
            $usefulness = (float) $weights['usefulness'][$market['category']];
            $score = round($importance * $gap * $answerability * $usefulness, 4);

            if ($score < (float) $cfg['min_score']) {
                continue;
            }

            $ranked[] = [
                'key' => (string) $key, 'score' => $score, 'status' => $status, 'market' => $market, 'postings_total' => $n,
                'breakdown' => ['importance' => round($importance, 3), 'gap' => $gap, 'answerability' => $answerability, 'usefulness' => $usefulness, 'coverage' => $status],
            ];
        }

        usort($ranked, fn (array $a, array $b) => [$b['score'], $a['key']] <=> [$a['score'], $b['key']]);

        return $ranked;
    }

    /**
     * @param  array<string, mixed>  $coverage
     */
    private function hasAdjacent(string $key, array $coverage): bool
    {
        foreach ((array) config("resume_profiles.capabilities.{$key}.related", []) as $related) {
            if (in_array($coverage['capabilities'][$related]['status'] ?? null, [CapabilityCoverage::DIRECT, CapabilityCoverage::TRANSFERABLE], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, true>
     */
    private function suppressedTopics(ResumeProfile $profile, int $cooldownDays): array
    {
        $careerId = $profile->career_profile_id;
        $topics = [];

        EvidenceProposal::query()
            ->where('career_profile_id', $careerId)
            ->where('status', ProposalStatus::Approved)
            ->whereIn('kind', [ProposalKind::Fact, ProposalKind::Preference])
            ->pluck('topic_key')
            ->each(function (string $topic) use (&$topics) {
                $topics[$topic] = true;
            });

        PresentationPreference::query()->where('career_profile_id', $careerId)->active()->pluck('topic_key')
            ->each(function (string $topic) use (&$topics) {
                $topics[$topic] = true;
            });

        // A skipped question stays quiet for the cooldown, on any job or profile.
        ClarificationQuestion::query()
            ->where('status', 'skipped')
            ->where('updated_at', '>=', now()->subDays($cooldownDays))
            ->whereHas('session', fn ($q) => $q->where('career_profile_id', $careerId)->orWhereHas('jobPosting', fn ($j) => $j->where('career_profile_id', $careerId)))
            ->pluck('topic_key')
            ->each(function (string $topic) use (&$topics) {
                $topics[$topic] = true;
            });

        return $topics;
    }
}
