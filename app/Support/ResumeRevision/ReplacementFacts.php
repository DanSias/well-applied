<?php

namespace App\Support\ResumeRevision;

use App\Models\CareerFact;
use App\Models\JobPosting;
use App\Models\ResumeVariant;
use App\Models\Role;
use App\Support\JobTailoring\JobRelevance;
use App\Support\ProfileSynthesis\EffectiveProfile;
use App\Support\ResumeVariant\CompositionChecks;
use App\Support\ResumeVariant\ResumeCandidatePayloadBuilder;
use App\Support\ResumeVariant\RoleEligibleFacts;

/**
 * The facts a bullet under one role can be replaced with: resume-eligible (never Private), eligible for that role, not
 * excluded by the approved profile, and not a claim already on the resume. Facts a composition check pointed to and the
 * ones that best answer the job come first; the rest of the eligible inventory can be searched.
 */
final class ReplacementFacts
{
    private const LIMIT = 40;

    private const SUGGESTED = 6;

    public function __construct(
        private readonly ResumeCandidatePayloadBuilder $candidates,
        private readonly RoleEligibleFacts $eligible,
        private readonly CompositionChecks $composition,
    ) {}

    /**
     * @return array<int, array{key: string, statement: string, visibility: string, type: string, project: string|null, suggested: bool, reason: string|null}>
     */
    public function forRole(JobPosting $posting, ResumeVariant $variant, int $roleId, string $query = ''): array
    {
        $rows = $this->candidates($posting, $variant, $roleId, $query);
        $best = 0;

        return array_map(function (array $r) use (&$best) {
            $reason = match (true) {
                $r['pointed'] => 'Suggested by a composition check',
                $best < self::SUGGESTED && $r['build'] => 'Shows software you built',
                $best < self::SUGGESTED && $r['score'] >= 0.5 => 'Answers the job',
                default => null,
            };
            $best += $reason === null ? 0 : 1;

            return ['key' => $r['key'], 'statement' => $r['statement'], 'visibility' => $r['visibility'], 'type' => $r['type'], 'project' => $r['project'], 'suggested' => $reason !== null, 'reason' => $reason];
        }, array_slice($rows, 0, self::LIMIT));
    }

    /**
     * Every eligible fact for a role that is not already on the resume, best first, with what ranks it: its relevance to the
     * job (including the specificity adjustments applied), whether it shows software that was built, and the project it
     * belongs to. The same ranking forRole() offers an editor; the layout finisher reads it whole.
     *
     * @return array<int, array{key: string, statement: string, visibility: string, type: string, project: string|null, project_id: int|null, score: float, adjustments: array<int, string>, pointed: bool, build: bool}>
     */
    public function candidates(JobPosting $posting, ResumeVariant $variant, int $roleId, string $query = ''): array
    {
        $variant->loadMissing(['careerProfile', 'synthesis', 'jobAnalysis', 'experienceRoles.bullets.citations.careerFact']);
        $profile = $variant->careerProfile;
        $facts = collect($this->candidates->buildForCareer($profile)['career_facts'])->keyBy('key')->all();
        $roles = Role::query()->whereHas('employer', fn ($q) => $q->where('career_profile_id', $profile->id))->with('projects')->get();
        $allowed = $this->eligible->forRoles($roles, $profile)[$roleId] ?? [];
        $excluded = array_column(array_filter(EffectiveProfile::resolve($variant->synthesis)['accomplishments'], fn (array $a) => ! $a['included']), 'fact_key');
        $relevance = app(JobRelevance::class)->analyze($variant->jobAnalysis, $variant->synthesis->content['inventory']['facts'], $profile);
        $sizes = $this->composition->claimGroupSizes($profile);
        $groups = CareerFact::query()->where('career_profile_id', $profile->id)->pluck('claim_group', 'key')->all();

        $onResume = [];

        foreach ($variant->experienceRoles as $role) {
            foreach ($role->bullets as $bullet) {
                foreach ($bullet->citations as $citation) {
                    $onResume[$groups[$citation->careerFact->key] ?? $citation->careerFact->key] = true;
                }
            }
        }

        $pointedTo = [];

        foreach ((array) ($variant->selection_input_snapshot['tailoring']['composition'] ?? []) as $finding) {
            array_push($pointedTo, ...$finding['available']);
        }

        $rows = [];

        foreach (array_keys($allowed) as $key) {
            $fact = $facts[$key] ?? null;

            if ($fact === null || $fact['fact_type'] === 'capability' || in_array($key, $excluded, true) || isset($onResume[$groups[$key] ?? $key])) {
                continue;
            }

            if ($query !== '' && stripos($fact['statement'].' '.($fact['attribution']['project'] ?? ''), $query) === false) {
                continue;
            }

            $score = (float) ($relevance['facts'][$key]['score'] ?? 0);
            $rows[] = [
                'key' => $key, 'statement' => $fact['statement'], 'visibility' => $fact['visibility'], 'type' => $fact['fact_type'],
                'project' => $fact['attribution']['project'] ?? null, 'project_id' => $fact['attribution']['project_id'] ?? null, 'score' => $score,
                'adjustments' => (array) ($relevance['facts'][$key]['adjustments'] ?? []), 'pointed' => in_array($key, $pointedTo, true),
                'build' => $this->composition->isConcreteBuild($key, $facts, $sizes),
            ];
        }

        usort($rows, fn (array $a, array $b) => [$b['pointed'], $b['build'], $b['score']] <=> [$a['pointed'], $a['build'], $a['score']]);

        return $rows;
    }
}
