<?php

namespace App\Support\Clarification;

use App\Enums\JobAnalysisFindingCategory;
use App\Enums\JobAnalysisRequirementStrength;
use App\Enums\JobMatchCoverage;
use App\Enums\Visibility;
use App\Models\CareerFact;
use App\Models\CareerFactMatch;
use App\Models\CareerLimitation;
use App\Models\CareerProfile;
use App\Models\ClarificationQuestion;
use App\Models\Evidence;
use App\Models\JobAnalysisFinding;
use App\Models\JobMatch;
use App\Models\JobMatchFinding;
use App\Models\PresentationPreference;
use App\Models\Project;
use App\Models\Role;
use App\Support\ResumeVariant\ResumeEligibility;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Picks the few questions most likely to improve this resume, from data
 * the pipeline already persisted. No model call; fully deterministic:
 * the same match and the same approved evidence always produce the same
 * ordered questions, and every score is explained by its breakdown.
 *
 * A finding drives a question only when the match found it partially or
 * not at all covered, it is a real requirement, and the topic has not
 * already been answered by approved evidence, an explicit limitation or a
 * recently skipped question. Private facts never appear in a question's
 * context: only resume-eligible evidence is shown or counted.
 */
final class DiscoverClarificationQuestions
{
    private const VISIBILITY_RANK = ['public' => 0, 'restricted' => 1, 'private' => 2];

    public function __construct(private readonly TopicCatalog $topics) {}

    /**
     * @return Collection<int, ClarificationCandidate>
     */
    public function discover(JobMatch $match, string $mode = 'standard'): Collection
    {
        $policy = (array) config($mode === 'deep' ? 'clarification.deep' : 'clarification.standard');
        $profile = $match->careerProfile;
        $suppressed = $this->suppressedTopics($profile);

        $findings = $match->findings()
            ->with(['jobAnalysisFinding', 'careerFactMatches.careerFact.attributable'])
            ->get();

        /** @var array<string, array<int, int>> $relatedByTopic */
        $relatedByTopic = [];
        /** @var array<string, array{score: float, finding: JobMatchFinding, breakdown: array<string, float|string>, label: string}> $top */
        $top = [];

        foreach ($findings as $matchFinding) {
            $analysisFinding = $matchFinding->jobAnalysisFinding;
            $breakdown = $this->score($matchFinding, $analysisFinding);

            if ($breakdown === null) {
                continue;
            }

            $topic = $this->topics->resolve($analysisFinding);

            if (isset($suppressed[$topic['key']])) {
                continue;
            }

            $relatedByTopic[$topic['key']][] = $analysisFinding->id;

            if (! isset($top[$topic['key']]) || $breakdown['score'] > $top[$topic['key']]['score']) {
                $top[$topic['key']] = ['score' => (float) $breakdown['score'], 'finding' => $matchFinding, 'breakdown' => $breakdown, 'label' => $topic['label']];
            }
        }

        $best = collect($top)->map(fn (array $entry, string $key) => [...$entry, 'related' => $relatedByTopic[$key]])->all();

        return collect($best)
            ->filter(fn (array $entry) => $entry['score'] >= (float) $policy['min_score'])
            ->map(fn (array $entry, string $key) => [$key, $entry])
            ->values()
            // Highest score first; ties broken by topic key so the order is stable.
            ->sort(fn (array $a, array $b) => [$b[1]['score'], $a[0]] <=> [$a[1]['score'], $b[0]])
            ->take((int) $policy['max_questions'])
            ->map(fn (array $pair) => $this->toCandidate($pair[0], $pair[1], $profile))
            ->values();
    }

    /**
     * Topic keys that must not be asked again: an active limitation,
     * approved evidence of any kind, or a question the user explicitly
     * skipped inside the cooldown window.
     *
     * @return array<string, true>
     */
    public function suppressedTopics(CareerProfile $profile): array
    {
        $keys = [];

        foreach (CareerLimitation::query()->where('career_profile_id', $profile->id)->active()->pluck('topic_key') as $key) {
            $keys[(string) $key] = true;
        }

        foreach (PresentationPreference::query()->where('career_profile_id', $profile->id)->active()->pluck('topic_key') as $key) {
            $keys[(string) $key] = true;
        }

        // Evidence-based, not proposal-based, so facts that arrived through
        // `career:sync-approved` on another database also count.
        $approved = Evidence::query()
            ->where('metadata->origin', ReviewEvidenceProposal::EVIDENCE_ORIGIN)
            ->whereHas('careerFact', fn ($q) => $q->where('career_profile_id', $profile->id))
            ->get();

        foreach ($approved as $evidence) {
            if (isset($evidence->metadata['topic_key'])) {
                $keys[(string) $evidence->metadata['topic_key']] = true;
            }
        }

        $cutoff = now()->subDays((int) config('clarification.skip_cooldown_days', 30));

        foreach (ClarificationQuestion::query()->where('status', 'skipped')->where('updated_at', '>=', $cutoff)->pluck('topic_key') as $key) {
            $keys[(string) $key] = true;
        }

        return $keys;
    }

    /**
     * @return array<string, float|string>|null null when the finding cannot or should not drive a question
     */
    private function score(JobMatchFinding $matchFinding, JobAnalysisFinding $finding): ?array
    {
        $coverage = $matchFinding->coverage;

        if (! in_array($coverage, [JobMatchCoverage::Partial, JobMatchCoverage::NoEvidence], true)) {
            return null;
        }

        if (! in_array($finding->category->value, (array) config('clarification.eligible_categories'), true)) {
            return null;
        }

        if ($finding->requirement_strength === JobAnalysisRequirementStrength::NotRequired) {
            return null;
        }

        // A years-of-experience floor is settled by the dated career
        // timeline, not by anything a person could add in an answer.
        if ($finding->years_experience_min !== null || $finding->years_experience_max !== null) {
            return null;
        }

        $weights = (array) config('clarification.weights');
        $isResponsibility = $finding->category === JobAnalysisFindingCategory::Responsibility;

        $strength = $finding->requirement_strength === null
            ? ($isResponsibility ? $weights['strength']['responsibility_default'] : $weights['strength']['default'])
            : $weights['strength'][$finding->requirement_strength->value];
        $emphasis = $weights['emphasis'][$finding->emphasis->value];
        $leverage = $weights['leverage'][$finding->category->value] ?? 0.5;
        $gap = $weights['gap'][$coverage->value];

        $answerability = $weights['answerability']['no_evidence'];

        if ($coverage === JobMatchCoverage::Partial) {
            $adjacency = $this->adjacency($matchFinding, $weights['answerability']);
            $answerability = $weights['answerability']['partial_floor'] + $weights['answerability']['partial_span'] * $adjacency;
        }

        return [
            'score' => round($strength * $emphasis * $leverage * $gap * $answerability, 4),
            'strength' => (float) $strength,
            'emphasis' => (float) $emphasis,
            'leverage' => (float) $leverage,
            'gap' => (float) $gap,
            'answerability' => round($answerability, 4),
            'coverage' => $coverage->value,
        ];
    }

    /**
     * 0..1: how much resume-eligible adjacent evidence the match cited.
     *
     * @param  array<string, mixed>  $weights
     */
    private function adjacency(JobMatchFinding $matchFinding, array $weights): float
    {
        $total = 0.0;

        foreach ($matchFinding->careerFactMatches as $factMatch) {
            if ($factMatch->careerFact !== null && ResumeEligibility::isEligible($factMatch->careerFact)) {
                $total += (float) ($weights['relationship'][$factMatch->relationship->value] ?? 0.0);
            }
        }

        return min(1.0, $total / (float) $weights['adjacency_cap']);
    }

    /**
     * @param  array{score: float, finding: JobMatchFinding, breakdown: array<string, float|string>, related: array<int, int>, label?: string}  $entry
     */
    private function toCandidate(string $topicKey, array $entry, CareerProfile $profile): ClarificationCandidate
    {
        $matchFinding = $entry['finding'];
        $finding = $matchFinding->jobAnalysisFinding;
        $label = $entry['label'] ?? $topicKey;

        $related = $matchFinding->careerFactMatches
            ->filter(fn (CareerFactMatch $m) => $m->careerFact !== null && ResumeEligibility::isEligible($m->careerFact))
            ->values();

        [$type, $id] = $this->suggestAttribution($related);

        return new ClarificationCandidate(
            topicKey: $topicKey,
            topicLabel: $label,
            primaryFindingId: $finding->id,
            relatedFindingIds: array_values(array_unique($entry['related'])),
            questionText: $this->questionText($label, $finding, $entry['breakdown']['coverage'] === 'no_evidence', $related->count()),
            score: $entry['score'],
            scoreBreakdown: $entry['breakdown'],
            context: [
                'requirement' => $finding->statement,
                'category' => $finding->category->value,
                'strength' => $finding->requirement_strength?->value,
                'emphasis' => $finding->emphasis->value,
                'coverage' => $entry['breakdown']['coverage'],
                'related_evidence' => $related->take(4)->map(fn (CareerFactMatch $m) => [
                    'key' => $m->careerFact->key,
                    'statement' => Str::limit($m->careerFact->statement, 220),
                    'visibility' => $m->careerFact->visibility->value,
                    'relationship' => $m->relationship->value,
                ])->all(),
            ],
            suggestedAttributableType: $type,
            suggestedAttributableId: $id,
            suggestedVisibility: $this->suggestVisibility($type, $id, $related, $profile),
        );
    }

    private function questionText(string $label, JobAnalysisFinding $finding, bool $noEvidence, int $relatedCount): string
    {
        $asks = '"'.Str::limit(trim($finding->statement), 220).'"';

        if ($noEvidence) {
            return "This role asks for: {$asks} Nothing in your career data covers {$label}. Do you have any experience with it?";
        }

        $found = $relatedCount > 0 ? "We found {$relatedCount} related ".Str::plural('item', $relatedCount).' in your career data' : 'We found little related material in your career data';

        return "This role asks for: {$asks} {$found}, but nothing that shows {$label} directly. What have you actually done with it, and what was your part?";
    }

    /**
     * The role most of the related evidence belongs to, weighted by how
     * directly each fact was matched. A suggestion only: the user can
     * change it before approving.
     *
     * @param  Collection<int, CareerFactMatch>  $related
     * @return array{0: string|null, 1: int|null}
     */
    private function suggestAttribution(Collection $related): array
    {
        $weights = (array) config('clarification.attribution_weights');
        $scores = [];

        foreach ($related as $match) {
            $roleId = $this->roleIdOf($match->careerFact);

            if ($roleId !== null) {
                $scores[$roleId] = ($scores[$roleId] ?? 0.0) + (float) ($weights[$match->relationship->value] ?? 0.0);
            }
        }

        if ($scores === []) {
            return [null, null];
        }

        arsort($scores);
        $top = array_keys($scores, max($scores), true);
        sort($top);

        return ['role', (int) $top[0]];
    }

    private function roleIdOf(CareerFact $fact): ?int
    {
        $attributable = $fact->attributable;

        return match (true) {
            $attributable instanceof Role => $attributable->id,
            $attributable instanceof Project => $attributable->role_id,
            default => null,
        };
    }

    /**
     * The most restrictive visibility among related evidence in the
     * suggested role. With nothing to anchor on, Restricted: the default
     * never starts at Public.
     *
     * @param  Collection<int, CareerFactMatch>  $related
     */
    private function suggestVisibility(?string $type, ?int $id, Collection $related, CareerProfile $profile): string
    {
        $rank = -1;

        foreach ($related as $match) {
            if ($type === 'role' && $this->roleIdOf($match->careerFact) === $id) {
                $rank = max($rank, self::VISIBILITY_RANK[$match->careerFact->visibility->value]);
            }
        }

        if ($rank < 0) {
            return Visibility::Restricted->value;
        }

        return array_flip(self::VISIBILITY_RANK)[$rank];
    }
}
