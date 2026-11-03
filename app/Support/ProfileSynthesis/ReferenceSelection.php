<?php

namespace App\Support\ProfileSynthesis;

use App\Models\Role;
use App\Support\ResumeProfile\CapabilityCoverage;

/**
 * Turns an approved profile into a Selection response for a reference resume, deterministically. It is
 * exactly the shape the model's Selection returns, so the same ResumeSelectionResponseValidator judges it
 * (eligibility, one-employer attribution, whole-career facts never in Experience, role completeness,
 * budgets). Every decision is the user's own (the include, exclude, pin and order of the profile) plus a
 * fixed budget: bullets are allotted to roles by how much of the profile's strongest evidence each holds,
 * a project's outcomes are grouped with its lead implementation, and no project dominates.
 *
 * Within a role the choice is by kind of evidence, not by raw rank. Each statement has a facet (architecture,
 * backend/API, security and reliability, frontend, measured outcome, ownership, leadership, or generic
 * development). Distinctive facets weigh more; a facet already chosen weighs less; overlapping facts of one
 * project in one facet share a bullet instead of making two; a statement that merely restates one already
 * chosen is skipped; and generic "built it in TypeScript" or leadership claims do not crowd out concrete
 * implementation. The profile is an evidence pool, not a checklist: only the user's top few employment
 * projects are protected, and only by a small, bounded extra allowance when swapping cannot cover them.
 * The trace records what was selected and what was left out, and why.
 */
final class ReferenceSelection
{
    public const VERSION = 'reference-selection-v2';

    /** How much each kind of evidence is worth to a Full-Stack reference resume. */
    private const FACET_WEIGHT = [
        'architecture' => 1.25, 'security_reliability' => 1.25, 'backend_api' => 1.2, 'outcome' => 1.2, 'frontend' => 1.1,
        'ownership' => 1.1, 'implementation' => 1.0, 'leadership' => 0.7, 'generic_dev' => 0.6, 'platform_use' => 0.5,
    ];

    /**
     * The second and later bullet of a facet within one role are worth less. Concrete engineering kinds barely
     * (different projects' architecture are different claims); generic kinds a lot (a second "owned it" or
     * "built it in TypeScript" says little more than the first).
     */
    private const NOVELTY = ['distinctive' => [1.0, 0.9, 0.8], 'generic' => [1.0, 0.6, 0.4]];

    private const DISTINCTIVE = ['architecture', 'security_reliability', 'backend_api', 'outcome', 'frontend'];

    public function __construct(private readonly CapabilityCoverage $coverage) {}

    /**
     * @param  array<string, mixed>  $effective  EffectiveProfile::resolve()
     * @param  array{career_facts: array<int, array<string, mixed>>}  $payload  ResumeCandidatePayloadBuilder::buildForCareer()
     * @return array{content: array<string, mixed>, trace: array<string, mixed>}
     */
    public function build(array $effective, array $payload): array
    {
        $cfg = (array) config('resume_profiles.reference');
        $offered = array_column($payload['career_facts'], null, 'key');
        $roleIds = array_values(array_unique(array_filter(array_map(fn (array $f) => $f['attribution']['role_id'] ?? null, $offered))));
        $excludedProjects = array_column(array_filter($effective['projects'], fn (array $p) => ! $p['included']), 'project_id');
        $omitted = [];
        $candidates = [];

        foreach ($effective['accomplishments'] as $i => $a) {
            $fact = $offered[$a['fact_key']] ?? null;
            $roleId = $fact['attribution']['role_id'] ?? null;

            $reason = match (true) {
                ! $a['included'] => 'Excluded by you.',
                $a['project_id'] !== null && in_array($a['project_id'], $excludedProjects, true) => 'Its project is excluded.',
                $fact === null || $roleId === null => 'Not offered for this resume (it is shown under another place of a shared claim).',
                ($effective['facts'][$a['fact_key']]['type'] ?? null) === 'narrative' => 'A description of the role\'s scope, not an accomplishment: it informs the summary only.',
                default => null,
            };

            if ($reason !== null) {
                $omitted[] = ['fact_key' => $a['fact_key'], 'reason' => $reason];

                continue;
            }

            $candidates[$roleId][] = [...$a, 'order_index' => $i, 'project_id' => $fact['attribution']['project_id'] ?? null];
        }

        $titles = Role::query()->whereIn('id', $roleIds)->pluck('title', 'id');
        $allocation = $this->allocate($roleIds, $candidates, (int) $cfg['total_bullets'], (int) $cfg['per_role_max'], (int) $cfg['developer_role_floor'], $titles->all());
        $weights = $this->projectWeights($effective, (int) $cfg['priority_projects']);
        $priority = array_keys(array_filter($weights, fn (float $w) => $w >= 1.2));
        $extraLeft = (int) $cfg['coverage_extra_bullets'];
        $experience = [];
        $traceRoles = [];
        $byRole = [];

        // Roles with the most bullets first, so the bounded extra allowance goes where coverage matters most.
        $order = $roleIds;
        usort($order, fn (int $a, int $b) => ($allocation[$b] ?? 1) <=> ($allocation[$a] ?? 1));

        foreach ($order as $roleId) {
            $units = $this->units($candidates[$roleId] ?? [], $offered, (int) $cfg['outcomes_per_lead'], (float) $cfg['overlap_limit']);
            $fallback = false;

            if ($units === []) {
                // Role completeness: every role that has eligible evidence must appear. Use its strongest fact.
                $best = array_values(array_filter($offered, fn (array $f) => ($f['attribution']['role_id'] ?? null) === $roleId))[0];
                $units = [$this->unit([$best['key']], PHP_INT_MAX, $best['attribution']['project_id'] ?? null, 0.0, 'implementation', false, false)];
                $fallback = true;
            }

            $result = $this->pick($units, $allocation[$roleId] ?? 1, [
                'per_project_max' => (int) $cfg['per_project_max'], 'weights' => $weights, 'priority' => $priority,
                'overlap_limit' => (float) $cfg['overlap_limit'], 'extra' => min(1, $extraLeft), 'role_has_projects' => array_filter($units, fn (array $u) => $u['project_id'] !== null) !== [], 'texts' => array_map(fn (array $f) => $f['statement'], $offered),
            ]);
            $extraLeft -= $result['extra_used'];

            foreach ($result['left'] as [$unit, $why]) {
                foreach ($unit['facts'] as $key) {
                    $omitted[] = ['fact_key' => $key, 'reason' => $why];
                }
            }

            $byRole[$roleId] = [
                'role_id' => $roleId, 'title_choice' => $this->titleChoice((string) $titles[$roleId]),
                'bullet_groups' => array_map(fn (array $unit, int $n) => ['order' => $n + 1, 'career_fact_keys' => $unit['facts'], 'job_analysis_finding_ids' => []], $result['chosen'], array_keys($result['chosen'])),
            ];
            $traceRoles[] = [
                'role_id' => $roleId, 'bullets' => count($result['chosen']), 'fallback' => $fallback, 'groups' => array_column($result['chosen'], 'facts'),
                'facets' => array_column($result['chosen'], 'facet'), 'extra_bullets' => $result['extra_used'], 'uncovered_priority_projects' => $result['uncovered'],
            ];
        }

        foreach ($roleIds as $roleId) {
            $experience[] = $byRole[$roleId];
        }

        $summary = $this->summaryFacts($effective, $offered, $candidates);

        return [
            'content' => [
                'summary_evidence' => $summary, 'skills' => [], 'experience' => $experience,
                'selected_projects' => $this->personalProjects($effective, $offered, (int) $cfg['personal_projects_max']),
                'target_term_usages' => [],
            ],
            'trace' => ['roles' => $traceRoles, 'summary_facts' => $summary, 'omitted' => $omitted, 'extra_bullets_used' => (int) $cfg['coverage_extra_bullets'] - $extraLeft],
        ];
    }

    /**
     * What the wording stage is told about the approved positioning.
     *
     * @param  array<string, mixed>  $effective
     * @return array<string, mixed>
     */
    public static function wordingContext(array $effective): array
    {
        $p = $effective['presentation'];

        return [
            'kind' => 'reference_resume',
            'target_identity' => $p['headline'],
            'approved_summary' => $p['summary'],
            'approved_differentiators' => array_values(array_map(fn (array $d) => $d['statement'], array_filter($p['differentiators'], fn (array $d) => $d['included']))),
            'priorities' => array_slice(array_values(array_map(fn (array $c) => $c['label'], array_filter($effective['capabilities']['direct'], fn (array $c) => $c['included']))), 0, 8),
        ];
    }

    /**
     * Bullets per role, in proportion to the strongest evidence each holds, at least one each.
     *
     * @param  array<int, int>  $roleIds
     * @param  array<int, array<int, array<string, mixed>>>  $candidates
     * @param  array<int, string>  $titles
     * @return array<int, int>
     */
    private function allocate(array $roleIds, array $candidates, int $total, int $perRoleMax, int $developerFloor, array $titles): array
    {
        $relevance = [];

        foreach ($roleIds as $roleId) {
            $relevance[$roleId] = array_sum(array_map(fn (array $a) => $a['score'], array_slice($candidates[$roleId] ?? [], 0, 6)));
        }

        $sum = array_sum($relevance) ?: 1.0;
        $alloc = [];

        foreach ($roleIds as $roleId) {
            // A role titled developer or engineer is the resume's identity, so it never shrinks below the floor.
            $floor = preg_match('/\b(?:developer|engineer)\b/i', (string) ($titles[$roleId] ?? '')) === 1 ? $developerFloor : 1;
            $alloc[$roleId] = max($floor, min($perRoleMax, (int) round($total * $relevance[$roleId] / $sum)));
        }

        while (array_sum($alloc) > $total) {
            $least = null;

            foreach ($alloc as $roleId => $n) {
                if ($n > 1 && $n > (preg_match('/\b(?:developer|engineer)\b/i', (string) ($titles[$roleId] ?? '')) === 1 ? $developerFloor : 1) && ($least === null || $relevance[$roleId] < $relevance[$least])) {
                    $least = $roleId;
                }
            }

            if ($least === null) {
                break;
            }

            $alloc[$least]--;
        }

        return $alloc;
    }

    /**
     * How strongly each project is protected: the user's top few employment projects (pinned first) are
     * priorities; other included employment projects are neutral.
     *
     * @param  array<string, mixed>  $effective
     * @return array<int, float>
     */
    private function projectWeights(array $effective, int $priorityCount): array
    {
        $weights = [];
        $rank = 0;

        foreach ($effective['projects'] as $p) {
            if ($p['personal'] || ! $p['included']) {
                continue;
            }

            $weights[$p['project_id']] = $p['pinned'] ? 1.35 : ($rank < $priorityCount ? 1.2 : 1.0);
            $rank++;
        }

        return $weights;
    }

    /**
     * The kind of evidence a statement is. Order matters: the most specific kinds are tested first.
     */
    private function facet(string $statement, string $kind): string
    {
        if ($kind === 'platform_use' || $kind === 'outcome') {
            return $kind === 'outcome' ? 'outcome' : 'platform_use';
        }

        $caps = $this->coverage->capabilitiesIn($statement);
        $has = fn (array $keys) => array_intersect($caps, $keys) !== [];

        return match (true) {
            preg_match('/\bfront-?end (?:experience|implementation|architecture)/i', $statement) === 1 => 'frontend',
            preg_match('/^(?:Independently\s+)?owned\b/i', $statement) === 1 => 'ownership',
            preg_match('/\btechnical leadership\b/i', $statement) === 1, $has(['mentoring_leadership']) => 'leadership',
            preg_match('/^(?:Designed|Architected)\b.*\barchitectures?\b/i', $statement) === 1 => 'architecture',
            preg_match('/\b(?:encrypt\w*|credential\w*|idempot\w*|resumable|dry[- ]run|throttl\w*|audit\w*|local[- ]first|post-write|recover\w*)\b/i', $statement) === 1, $has(['auth_security', 'retry_idempotency']) => 'security_reliability',
            preg_match('/\b(?:backend|back-end|REST|Jira|GitLab|webhook\w*|APIs?)\b/', $statement) === 1, $has(['rest_api_design', 'api_integration', 'webhooks']) => 'backend_api',
            preg_match('/\b(?:deterministic|evidence[- ]linked|single source of truth|component boundaries|architecture\w*|provider[- ]abstracted|data model)\b/i', $statement) === 1, $has(['system_design', 'architecture_ownership', 'data_pipelines']) => 'architecture',
            preg_match('/\bfront-?end\b/i', $statement) === 1, $has(['frontend_architecture']) => 'frontend',
            $caps !== [] && array_diff($caps, ['typescript', 'automated_testing', 'javascript_core', 'react', 'nodejs']) === [] => 'generic_dev',
            default => 'implementation',
        };
    }

    /**
     * @param  array<int, string>  $facts
     * @return array{facts: array<int, string>, rank: int, project_id: int|null, score: float, facet: string, has_outcome: bool, pinned: bool}
     */
    private function unit(array $facts, int $rank, ?int $projectId, float $score, string $facet, bool $hasOutcome, bool $pinned): array
    {
        return ['facts' => $facts, 'rank' => $rank, 'project_id' => $projectId, 'score' => $score, 'facet' => $facet, 'has_outcome' => $hasOutcome, 'pinned' => $pinned];
    }

    /**
     * Fills one role's bullets by kind of evidence (see the class comment).
     *
     * @param  array<int, array<string, mixed>>  $units
     * @param  array{per_project_max: int, weights: array<int, float>, priority: array<int, int>, overlap_limit: float, extra: int, role_has_projects: bool, texts: array<string, string>}  $ctx
     * @return array{chosen: array<int, array<string, mixed>>, left: array<int, array{0: array<string, mixed>, 1: string}>, extra_used: int, uncovered: array<int, int>}
     */
    private function pick(array $units, int $slots, array $ctx): array
    {
        $left = [];
        // The profile's score reflects a small market sample's keyword counts as much as the engineering. It only breaks
        // ties here: what the evidence is (its facet), whose project it is and what is already chosen decide the choice.
        $maxScore = max([0.0001, ...array_column($units, 'score')]);
        $state = new SelectionState;

        $adjusted = function (array $u) use ($state, $ctx, $maxScore): float {
            $project = $u['project_id'];
            $novelty = self::NOVELTY[in_array($u['facet'], self::DISTINCTIVE, true) ? 'distinctive' : 'generic'][min($state->facetCount[$u['facet']] ?? 0, 2)];
            $sameFacetInProject = $project !== null && isset($state->projectFacet[$project.'|'.$u['facet']]) ? 0.3 : 1.0;
            // A role-level statement is less specific than a project's (often a list of areas worked in), except a measured result, which stands alone.
            $weight = $project === null ? ($u['facet'] === 'outcome' || ! $ctx['role_has_projects'] ? 1.0 : 0.9) : ($ctx['weights'][$project] ?? 0.9);

            return (0.8 + 0.2 * $u['score'] / $maxScore) * (self::FACET_WEIGHT[$u['facet']] ?? 1.0) * $novelty * $sameFacetInProject * $weight * ($u['pinned'] ? 1.4 : 1.0);
        };

        $eligible = function (array $u) use ($state, $ctx): bool {
            // Two bullets per project. A priority project may carry a third only for a different kind of concrete
            // engineering (architecture, backend, security, frontend or a measured outcome), never for more of the same.
            if ($u['project_id'] !== null) {
                $count = $state->perProject[$u['project_id']] ?? 0;
                $third = in_array($u['project_id'], $ctx['priority'], true) && in_array($u['facet'], self::DISTINCTIVE, true) && ! isset($state->projectFacet[$u['project_id'].'|'.$u['facet']]);

                if ($count >= $ctx['per_project_max'] || ($count >= $ctx['per_project_max'] - 1 && ! $third)) {
                    return false;
                }
            }

            // A statement that restates one already chosen adds nothing.
            foreach ($state->chosen as $c) {
                foreach ($u['facts'] as $a) {
                    foreach ($c['facts'] as $b) {
                        if ($this->overlap($ctx['texts'][$a] ?? '', $ctx['texts'][$b] ?? '') >= $ctx['overlap_limit']) {
                            return false;
                        }
                    }
                }
            }

            return true;
        };

        $take = function (array $u, float $score) use ($state): void {
            $state->chosen[] = [...$u, 'selected_score' => $score];
            $state->facetCount[$u['facet']] = ($state->facetCount[$u['facet']] ?? 0) + 1;

            if ($u['project_id'] !== null) {
                $state->projectFacet[$u['project_id'].'|'.$u['facet']] = true;
                $state->perProject[$u['project_id']] = ($state->perProject[$u['project_id']] ?? 0) + 1;
            }
        };

        $best = function (array $pool) use ($adjusted, $eligible): ?array {
            $top = null;

            foreach ($pool as $u) {
                if (! $eligible($u)) {
                    continue;
                }

                $score = $adjusted($u);

                if ($top === null || $score > $top[1]) {
                    $top = [$u, $score];
                }
            }

            return $top;
        };

        $remaining = $units;

        while (count($state->chosen) < $slots && ($top = $best($remaining)) !== null) {
            $take($top[0], $top[1]);
            $remaining = array_values(array_filter($remaining, fn (array $u) => $u !== $top[0]));
        }

        // A role with a measured outcome shows one: swap out its weakest bullet if none was chosen.
        if (array_filter($state->chosen, fn (array $u) => $u['has_outcome']) === []) {
            $outcome = $best(array_filter($remaining, fn (array $u) => $u['has_outcome']));

            if ($outcome !== null && $state->chosen !== []) {
                usort($state->chosen, fn (array $a, array $b) => $a['selected_score'] <=> $b['selected_score']);
                $weakest = array_shift($state->chosen);

                // Only when the result is nearly as strong as what it replaces.
                if (! $weakest['pinned'] && $outcome[1] >= 0.85 * $weakest['selected_score']) {
                    $remaining[] = $weakest;
                    $take($outcome[0], $outcome[1]);
                    $remaining = array_values(array_filter($remaining, fn (array $u) => $u !== $outcome[0]));
                } else {
                    array_unshift($state->chosen, $weakest);
                }
            }
        }

        // The user's top projects each deserve a bullet. Swap first; spend the small extra allowance only if a swap cannot.
        $extraUsed = 0;
        $uncovered = [];

        foreach ($ctx['priority'] as $projectId) {
            $inRole = array_filter($remaining, fn (array $u) => $u['project_id'] === $projectId);
            $covered = array_filter($state->chosen, fn (array $u) => $u['project_id'] === $projectId) !== [];

            if ($covered || $inRole === []) {
                continue;
            }

            $candidate = $best($inRole);

            if ($candidate === null) {
                $uncovered[] = $projectId;

                continue;
            }

            // Weakest bullet whose removal does not uncover another priority project.
            usort($state->chosen, fn (array $a, array $b) => $a['selected_score'] <=> $b['selected_score']);
            $victimIndex = null;

            foreach ($state->chosen as $i => $c) {
                $soleCover = $c['project_id'] !== null && in_array($c['project_id'], $ctx['priority'], true)
                    && count(array_filter($state->chosen, fn (array $o) => $o['project_id'] === $c['project_id'])) === 1;

                if (! $c['pinned'] && ! $soleCover) {
                    $victimIndex = $i;

                    break;
                }
            }

            if ($victimIndex !== null && $candidate[1] >= 0.75 * $state->chosen[$victimIndex]['selected_score']) {
                $remaining[] = $state->chosen[$victimIndex];
                array_splice($state->chosen, $victimIndex, 1);
                $take($candidate[0], $candidate[1]);
            } elseif ($extraUsed < $ctx['extra']) {
                $take($candidate[0], $candidate[1]);
                $extraUsed++;
            } else {
                $uncovered[] = $projectId;

                continue;
            }

            $remaining = array_values(array_filter($remaining, fn (array $u) => $u !== $candidate[0]));
        }

        // Pinned bullets first, then the strongest.
        usort($state->chosen, fn (array $a, array $b) => [$b['pinned'], $b['selected_score']] <=> [$a['pinned'], $a['selected_score']]);

        foreach ($remaining as $u) {
            $left[] = [$u, 'Left out to keep the role concise: weaker or more repetitive than what was chosen.'];
        }

        return ['chosen' => $state->chosen, 'left' => $left, 'extra_used' => $extraUsed, 'uncovered' => $uncovered];
    }

    /**
     * Share of significant words two statements have in common (0 to 1).
     */
    private function overlap(string $a, string $b): float
    {
        $words = fn (string $t) => array_unique(array_filter(preg_split('/[^a-z0-9]+/', strtolower($t)) ?: [], fn (string $w) => strlen($w) >= 4));
        $x = $words($a);
        $y = $words($b);
        $union = count(array_unique([...$x, ...$y]));

        return $union === 0 ? 0.0 : count(array_intersect($x, $y)) / $union;
    }

    /**
     * One role's candidate bullets. Within a project, up to two facts of the same kind share one bullet (so
     * "backend" and "Jira and GitLab integration" read as one claim); a project's measured outcomes ride with
     * its strongest bullet; role-level statements stand alone.
     *
     * @param  array<int, array<string, mixed>>  $candidates  one role's accomplishments in the profile's order
     * @param  array<string, array<string, mixed>>  $offered
     * @return array<int, array<string, mixed>>
     */
    private function units(array $candidates, array $offered, int $outcomesPerLead, float $overlapLimit): array
    {
        // A statement that only says a technology was used is a skill, not a bullet: it fills a slot last.
        $candidates = array_map(fn (array $a) => $a['kind'] === 'platform_use' ? [...$a, 'score' => $a['score'] * 0.5] : $a, $candidates);
        $facetOf = fn (array $a) => $this->facet((string) ($offered[$a['fact_key']]['statement'] ?? ''), (string) $a['kind']);
        $units = [];
        $byProject = [];

        foreach ($candidates as $a) {
            if ($a['project_id'] === null) {
                $units[] = $this->unit([$a['fact_key']], $a['order_index'], null, (float) $a['score'], $facetOf($a), $a['kind'] === 'outcome', (bool) $a['pinned']);

                continue;
            }

            $byProject[$a['project_id']][] = $a;
        }

        foreach ($byProject as $projectId => $items) {
            $outcomes = array_values(array_filter($items, fn (array $a) => $a['kind'] === 'outcome'));
            $others = array_values(array_filter($items, fn (array $a) => $a['kind'] !== 'outcome'));
            usort($others, fn (array $a, array $b) => $b['score'] <=> $a['score']);
            /** facet of each group, and its member accomplishments */
            $facets = [];
            $members = [];

            foreach ($others as $a) {
                $facet = $facetOf($a);
                $slot = null;

                foreach ($facets as $i => $groupFacet) {
                    // Overlapping facts share a bullet; ones that restate each other do not (the second is then dropped as redundant).
                    $restates = array_filter($members[$i], fn (array $m) => $this->overlap((string) ($offered[$m['fact_key']]['statement'] ?? ''), (string) ($offered[$a['fact_key']]['statement'] ?? '')) >= $overlapLimit) !== [];

                    if ($groupFacet === $facet && count($members[$i]) < 2 && ! $restates) {
                        $slot = $i;

                        break;
                    }
                }

                if ($slot === null) {
                    $facets[] = $facet;
                    $members[] = [$a];
                } else {
                    $members[$slot][] = $a;
                }
            }

            if ($facets === [] && $outcomes !== []) {
                $facets[] = 'outcome';
                $members[] = array_splice($outcomes, 0, 1);
            }

            // A bullet carries at most three facts. A project's measured outcomes ride with its strongest bullets;
            // any left over stand alone only when there is no bullet to carry them.
            foreach ($facets as $i => $facet) {
                $take = max(0, min($i === 0 ? $outcomesPerLead : 1, 3 - count($members[$i])));
                $attached = array_splice($outcomes, 0, $take);
                $all = [...$members[$i], ...$attached];

                $units[] = $this->unit(
                    array_column($all, 'fact_key'),
                    (int) min([PHP_INT_MAX, ...array_column($all, 'order_index')]),
                    (int) $projectId,
                    max([0.0, ...array_column($all, 'score')]) + 0.05 * count($attached),
                    $facet,
                    $attached !== [] || $facet === 'outcome',
                    in_array(true, array_column($all, 'pinned'), true),
                );
            }

            foreach ($outcomes as $a) {
                $units[] = $this->unit([$a['fact_key']], $a['order_index'], (int) $projectId, (float) $a['score'], 'outcome', true, (bool) $a['pinned']);
            }
        }

        usort($units, fn (array $a, array $b) => $a['rank'] <=> $b['rank']);

        return $units;
    }

    /**
     * @param  array<string, mixed>  $effective
     * @param  array<string, array<string, mixed>>  $offered
     * @param  array<int, array<int, array<string, mixed>>>  $candidates
     * @return array<int, string>
     */
    private function summaryFacts(array $effective, array $offered, array $candidates): array
    {
        $keys = $effective['presentation']['summary_fact_keys'];

        foreach (array_filter($effective['presentation']['differentiators'], fn (array $d) => $d['included']) as $d) {
            $keys = [...$keys, ...$d['fact_keys']];
        }

        $keys = array_values(array_filter(array_unique($keys), fn (string $k) => isset($offered[$k])));

        if ($keys === []) {
            $keys = array_slice(array_column(array_merge(...array_values($candidates ?: [[]])), 'fact_key'), 0, 3);
        }

        return array_slice($keys, 0, (int) config('resume_profiles.reference.summary_facts'));
    }

    /**
     * Included independent projects: shown as Selected Projects, never as employment.
     *
     * @param  array<string, mixed>  $effective
     * @param  array<string, array<string, mixed>>  $offered
     * @return array<int, array<string, mixed>>
     */
    private function personalProjects(array $effective, array $offered, int $max): array
    {
        $out = [];

        foreach ($effective['projects'] as $p) {
            if (! $p['personal'] || ! $p['included'] || count($out) >= $max) {
                continue;
            }

            $keys = array_values(array_filter($p['fact_keys'], fn (string $k) => ($offered[$k]['attribution']['project_id'] ?? null) === $p['project_id']));

            if ($keys !== []) {
                $out[] = ['project_id' => $p['project_id'], 'order' => count($out) + 1, 'career_fact_keys' => array_slice($keys, 0, 3)];
            }
        }

        return $out;
    }

    private function titleChoice(string $title): string
    {
        $segments = array_map('trim', explode('/', $title));

        if (count($segments) === 1) {
            return 'full';
        }

        foreach ($segments as $i => $segment) {
            if (preg_match('/\b(?:developer|engineer)\b/i', $segment) === 1) {
                return 'segment_'.($i + 1);
            }
        }

        return 'full';
    }
}
