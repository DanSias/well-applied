<?php

namespace App\Support\ProfileSynthesis;

use App\Models\CareerFact;
use App\Models\Role;
use App\Support\ResumeDocument\SkillPresentation;
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
        'ownership' => 1.1, 'implementation' => 1.0, 'leadership' => 0.7, 'generic_dev' => 0.6, 'platform_use' => 0.5, 'deployment' => 0.6,
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
        $omitted = [];
        $candidates = $this->candidatesFor($effective, $offered, $omitted);

        $roleRows = Role::query()->whereIn('id', $roleIds)->get(['id', 'title', 'start_year', 'end_year'])->keyBy('id');
        $titles = $roleRows->pluck('title', 'id');
        $meta = $this->meta($effective, $offered);
        $forJob = isset($effective['job_priority_findings']);
        $allocation = $forJob
            ? $this->allocateForJob($roleIds, $candidates, $titles->all(), $roleRows->map(fn (Role $r) => ['start' => $r->start_year, 'end' => $r->end_year])->all())
            : $this->allocate($roleIds, $candidates, (int) $cfg['total_bullets'], (int) $cfg['per_role_max'], (int) $cfg['developer_role_floor'], $titles->all());
        // A long role keeps its distinct responsibilities in a resume for a job, whatever the job asks (see coveragePlan()).
        $unitsCache = [];
        $unitsFor = function (int $roleId) use (&$unitsCache, $candidates, $offered, $cfg, $meta, $forJob): array {
            return $unitsCache[$roleId] ??= $this->units($candidates[$roleId] ?? [], $offered, (int) $cfg['outcomes_per_lead'], (float) $cfg['overlap_limit'], $meta, $forJob);
        };
        $coverage = $forJob ? $this->coveragePlan($roleIds, $unitsFor, $candidates, $titles->all(), $roleRows->map(fn (Role $r) => ['start' => $r->start_year, 'end' => $r->end_year])->all(), $allocation, $offered) : [];

        if ($forJob) {
            $allocation = $coverage['allocation'];
        }

        $weights = $this->projectWeights($effective, (int) $cfg['priority_projects']);
        // The user's top few projects are guaranteed a bullet on a reference resume. For a job only the pinned ones are: the
        // rest compete on what the job asks for.
        $priority = array_keys(array_filter($weights, fn (float $w) => $w >= (isset($effective['job_priority_findings']) ? 1.35 : 1.1)));
        $extraLeft = (int) $cfg['coverage_extra_bullets'];
        $experience = [];
        $traceRoles = [];
        $byRole = [];

        // Roles with the most bullets first, so the bounded extra allowance goes where coverage matters most.
        $order = $roleIds;
        usort($order, fn (int $a, int $b) => ($allocation[$b] ?? 1) <=> ($allocation[$a] ?? 1));

        foreach ($order as $roleId) {
            $units = $unitsFor($roleId);
            $fallback = false;

            if ($units === []) {
                // Role completeness: every role that has eligible evidence must appear. Use its strongest fact.
                $best = array_values(array_filter($offered, fn (array $f) => ($f['attribution']['role_id'] ?? null) === $roleId))[0];
                $units = [$this->unit([$best['key']], PHP_INT_MAX, $best['attribution']['project_id'] ?? null, 0.0, 'implementation', false, false)];
                $fallback = true;
            }

            $result = $this->pick($units, $allocation[$roleId] ?? 1, [
                'per_project_max' => (int) $cfg['per_project_max'], 'weights' => $weights, 'priority' => $priority,
                'overlap_limit' => (float) $cfg['overlap_limit'], 'extra' => min(1, $extraLeft), 'role_has_projects' => array_filter($units, fn (array $u) => $u['project_id'] !== null) !== [],
                'job_priority' => $effective['job_priority_findings'] ?? [],
                // For a job, evidence the job barely speaks to does not fill a slot once the role has a bullet.
                'min_job' => $forJob ? (float) config('resume.composition.allocation.filler_relevance') : null,
                // A general claim of ownership or leadership says the same thing wherever it is made; a role keeps a few and the rest of its slots go to the work.
                'generic_max' => $forJob ? (int) config('resume.composition.allocation.generic_per_role') : null,
                'technical_floor' => preg_match('/\b(?:developer|engineer)\b/i', (string) $titles[$roleId]) === 1, 'texts' => array_map(fn (array $f) => $f['statement'], $offered),
                'coverage' => $forJob && ($coverage['roles'][$roleId]['lifted'] ?? false) ? min((int) $coverage['roles'][$roleId]['floor'], $allocation[$roleId] ?? 1) : 0,
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
                ...($forJob ? ['coverage' => $this->coverageOutcome($coverage['roles'][$roleId], $result['chosen'], $offered, (string) $titles[$roleId])] : []),
            ];
        }

        foreach ($roleIds as $roleId) {
            $experience[] = $byRole[$roleId];
        }

        $summary = $this->summaryFacts($effective, $offered, $candidates);

        return [
            'content' => [
                'summary_evidence' => $summary, 'skills' => $this->skills($effective, $offered, $experience, $summary, $effective['skills_layout'] ?? null), 'experience' => $experience,
                'selected_projects' => $this->personalProjects($effective, $offered, (int) $cfg['personal_projects_max']),
                'target_term_usages' => [],
            ],
            'trace' => ['roles' => $traceRoles, 'summary_facts' => $summary, 'omitted' => $omitted, 'extra_bullets_used' => (int) $cfg['coverage_extra_bullets'] - $extraLeft],
        ];
    }

    /**
     * Each role's accomplishments that may be chosen, in the profile's order. What is left out (excluded, its project excluded, not
     * offered, a description of the role's scope) is appended to `$omitted` with the reason.
     *
     * @param  array<string, mixed>  $effective
     * @param  array<string, array<string, mixed>>  $offered
     * @param  array<int, array{fact_key: string, reason: string}>  $omitted
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function candidatesFor(array $effective, array $offered, array &$omitted): array
    {
        $excludedProjects = array_column(array_filter($effective['projects'], fn (array $p) => ! $p['included']), 'project_id');
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

            // A result whose figure is confidential cannot be stated, so it is not an outcome bullet: it keeps its place
            // as evidence (the engagement it belongs to) without being promised a number.
            $kind = $a['kind'] === 'outcome' && ($fact['visibility'] ?? null) === 'restricted' && preg_match('/\d/', (string) $fact['statement']) === 1 ? 'implementation' : $a['kind'];
            $candidates[$roleId][] = [...$a, 'kind' => $kind, 'order_index' => $i, 'project_id' => $fact['attribution']['project_id'] ?? null];
        }

        return $candidates;
    }

    /**
     * Role coverage for a resume as it stands (a generated selection, a revision, a reviewed variant), read from the facts its
     * bullets cite rather than from a selection trace, so it is the same wherever the resume came from.
     *
     * @param  array<string, mixed>  $content  experience[].role_id and bullet_groups[].career_fact_keys
     * @param  array<string, mixed>  $effective  the profile as adapted to the job
     * @param  array<string, array<string, mixed>>  $offered  eligible facts by key
     * @return array<int, array{role_id: int, coverage: array<string, mixed>}>
     */
    public function coverageOf(array $content, array $effective, array $offered): array
    {
        $cfg = (array) config('resume_profiles.reference');
        $omitted = [];
        $candidates = $this->candidatesFor($effective, $offered, $omitted);
        $roleIds = array_values(array_map(fn (array $r) => (int) $r['role_id'], $content['experience']));
        $roleRows = Role::query()->whereIn('id', $roleIds)->get(['id', 'title', 'start_year', 'end_year'])->keyBy('id');
        $meta = $this->meta($effective, $offered);
        $unitsFor = fn (int $roleId): array => $this->units($candidates[$roleId] ?? [], $offered, (int) $cfg['outcomes_per_lead'], (float) $cfg['overlap_limit'], $meta, true);
        $allocation = [];
        $chosen = [];

        foreach ($content['experience'] as $role) {
            $allocation[(int) $role['role_id']] = count($role['bullet_groups']);
            $chosen[(int) $role['role_id']] = array_map(fn (array $g) => ['facts' => $g['career_fact_keys']], $role['bullet_groups']);
        }

        $plan = $this->coveragePlan($roleIds, $unitsFor, $candidates, $roleRows->pluck('title', 'id')->all(), $roleRows->map(fn (Role $r) => ['start' => $r->start_year, 'end' => $r->end_year])->all(), $allocation, $offered);

        return array_map(fn (int $roleId) => ['role_id' => $roleId, 'coverage' => $this->coverageOutcome($plan['roles'][$roleId], $chosen[$roleId], $offered, (string) ($roleRows[$roleId]->title ?? ''))], $roleIds);
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
            // For a job, strong evidence counts more than a pile of middling evidence (a role with two exact matches
            // outweighs one with six loose ones).
            $relevance[$roleId] = array_sum(array_map(fn (array $a) => isset($a['job_relevance']) ? $a['score'] ** 2 : $a['score'], array_slice($candidates[$roleId] ?? [], 0, 6)));
        }

        $sum = array_sum($relevance) ?: 1.0;
        $alloc = [];

        foreach ($roleIds as $roleId) {
            // A role titled developer or engineer is the resume's identity, so it never shrinks below the floor.
            $floor = preg_match('/\b(?:developer|engineer)\b/i', (string) ($titles[$roleId] ?? '')) === 1 ? $developerFloor : 1;
            // A role that is not a developer or engineer role (an analyst role) never takes more than two bullets of a developer resume.
            $cap = $floor === 1 && preg_match('/\b(?:developer|engineer)\b/i', (string) ($titles[$roleId] ?? '')) !== 1 ? min(2, $perRoleMax) : $perRoleMax;
            $alloc[$roleId] = max($floor, min($cap, (int) round($total * $relevance[$roleId] / $sum)));
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
     * Bullets per role for a resume written for a job. The shares follow how strong and how relevant each role's evidence is,
     * weighted by tenure, so a role whose evidence the job barely speaks to takes few bullets whatever its title, and a long
     * recent or earlier engineering role is not reduced to a token. Every role keeps at least one bullet (role completeness).
     *
     * A substantial earlier engineering role (a developer or engineer title, at least `continuity_min_years`, with at least
     * `continuity_floor` facts the job speaks to at all) keeps that floor; a role with no such evidence has none. A role that is
     * not an engineering role takes at most two. The total is the planning target for a two-page senior resume, lowered
     * to the amount of evidence the job actually speaks to, never raised to fill space.
     *
     * @param  array<int, int>  $roleIds
     * @param  array<int, array<int, array<string, mixed>>>  $candidates
     * @param  array<int, string>  $titles
     * @param  array<int, array{start: int|null, end: int|null}>  $tenure
     * @return array<int, int>
     */
    private function allocateForJob(array $roleIds, array $candidates, array $titles, array $tenure): array
    {
        $cfg = (array) config('resume.composition');
        $alloc = (array) $cfg['allocation'];
        $perRoleMax = (int) $cfg['bullets']['per_role_max'];
        $thisYear = (int) date('Y');
        $isEngineering = fn (int $roleId): bool => preg_match('/\b(?:developer|engineer)\b/i', (string) ($titles[$roleId] ?? '')) === 1;

        $relevant = 0;

        foreach ($candidates as $list) {
            $relevant += count(array_filter($list, fn (array $a) => ($a['job_relevance'] ?? 0.0) >= $alloc['relevant_at'] && $a['kind'] !== 'platform_use'));
        }

        $planned = max((int) $cfg['bullets']['min'], min((int) $cfg['bullets']['target'], (int) ceil($relevant / (float) $alloc['facts_per_bullet_unit'])));
        $weight = [];
        $years = [];

        foreach ($roleIds as $roleId) {
            $start = $tenure[$roleId]['start'] ?? null;
            $end = $tenure[$roleId]['end'] ?? null;
            $years[$roleId] = $start === null ? 0 : max(0, ($end ?? $thisYear) - $start);
            $recent = $end === null || $end >= $thisYear - (int) $alloc['recent_years'];
            $tenureFactor = $recent ? 1.0 : 0.7 + 0.3 * min(1.0, $years[$roleId] / max(1, (int) $alloc['tenure_full_years']));
            $strength = array_sum(array_map(fn (array $a) => ($a['job_relevance'] ?? $a['score']) ** 2 + 0.25 * $a['score'] ** 2, array_slice($candidates[$roleId] ?? [], 0, 6)));
            $weight[$roleId] = $strength * $tenureFactor;
        }

        $sum = array_sum($weight) ?: 1.0;
        $floor = [];
        $out = [];

        foreach ($roleIds as $roleId) {
            $speaksToJob = count(array_filter($candidates[$roleId] ?? [], fn (array $a) => ($a['job_relevance'] ?? 0.0) >= $alloc['filler_relevance'] && $a['kind'] !== 'platform_use'));
            $floor[$roleId] = $isEngineering($roleId) && $years[$roleId] >= (int) $alloc['continuity_min_years'] && $speaksToJob >= (int) $alloc['continuity_floor']
                ? (int) $alloc['continuity_floor'] : 1;
            $cap = $isEngineering($roleId) ? $perRoleMax : min(2, $perRoleMax);
            $out[$roleId] = max($floor[$roleId], min($cap, (int) round($planned * $weight[$roleId] / $sum)));
        }

        while (array_sum($out) > $planned) {
            $least = null;

            foreach ($out as $roleId => $n) {
                if ($n > $floor[$roleId] && ($least === null || $weight[$roleId] < $weight[$least])) {
                    $least = $roleId;
                }
            }

            if ($least === null) {
                break;
            }

            $out[$least]--;
        }

        return $out;
    }

    /**
     * Which roles keep bullets for their distinct responsibilities in a resume for a job, and how many (see
     * config('resume.composition.coverage')). Job relevance alone decides the plan above: it ranks evidence by how many of the
     * posting's words it shares, so a long earlier role whose work is described in terms of clients, ownership and delivery
     * can score near zero for every job and be reduced to one bullet however much approved evidence it has.
     *
     * A role qualifies by tenure (`min_years`), not by title or by what this job says. Its floor is the target, lowered to
     * `minimum` when the job speaks to nothing the role did, never above the number of bullets its evidence has to offer (the
     * units of a minimal cover of the responsibilities it states) or the role's cap (an analyst role keeps two). The floor is added to the plan rather than taken
     * from another role, up to `bullets.max`, longest tenure first.
     *
     * @param  array<int, int>  $roleIds
     * @param  array<int, array<int, array<string, mixed>>>  $candidates
     * @param  array<int, string>  $titles
     * @param  array<int, array{start: int|null, end: int|null}>  $tenure
     * @param  array<int, int>  $allocation  bullets per role from the relevance plan
     * @param  array<string, array<string, mixed>>  $offered
     * @return array{allocation: array<int, int>, roles: array<int, array<string, mixed>>}
     */
    private function coveragePlan(array $roleIds, \Closure $unitsFor, array $candidates, array $titles, array $tenure, array $allocation, array $offered): array
    {
        $cfg = (array) config('resume.composition.coverage');
        $alloc = (array) config('resume.composition.allocation');
        $perRoleMax = (int) config('resume.composition.bullets.per_role_max');
        $thisYear = (int) date('Y');
        $roles = [];

        foreach ($roleIds as $roleId) {
            $start = $tenure[$roleId]['start'] ?? null;
            $years = $start === null ? 0 : max(0, ($tenure[$roleId]['end'] ?? $thisYear) - $start);
            $engineering = preg_match('/\b(?:developer|engineer)\b/i', (string) ($titles[$roleId] ?? '')) === 1;
            $speaks = count(array_filter($candidates[$roleId] ?? [], fn (array $a) => ($a['job_relevance'] ?? 0.0) >= $alloc['filler_relevance'] && $a['kind'] !== 'platform_use'));
            $target = $speaks > 0 ? (int) $cfg['target'] : (int) $cfg['minimum'];
            // Distinct responsibilities, not a count of statements: three "built X" facts are one responsibility, so a role whose
            // evidence only says that it built things has one bullet to show for it. `cover` is the fewest units that show them all
            // (one fact can state two), which is how many bullets of evidence the role actually has to offer.
            $cover = $this->responsibilityCover(array_map(fn (array $unit) => $this->responsibilitiesOf($unit, $offered), $unitsFor($roleId)));
            $supported = array_values(array_unique(array_merge([], ...$cover)));
            $stating = count($cover);

            $applies = $years >= (int) $cfg['min_years'];
            $cap = $engineering ? $perRoleMax : min(2, $perRoleMax);
            $roles[$roleId] = [
                'applies' => $applies, 'years' => $years, 'target' => $target, 'cap' => $cap, 'cover_size' => $stating, 'responsibilities_supported' => $supported,
                'floor' => $applies ? max(0, min($cap, $target, $stating)) : 0,
            ];
        }

        // Longest tenure first: the claim on the budget is continuity of the career story.
        $byTenure = $roleIds;
        usort($byTenure, fn (int $a, int $b) => $roles[$b]['years'] <=> $roles[$a]['years']);
        $room = max(0, (int) config('resume.composition.bullets.max') - array_sum($allocation));

        foreach ($byTenure as $roleId) {
            $short = max(0, $roles[$roleId]['floor'] - ($allocation[$roleId] ?? 1));
            // Coverage lifts a role the relevance plan left below its floor; a role the plan already gave that many bullets has
            // had its evidence chosen by what the job asks for, and keeps that choice.
            $roles[$roleId]['lifted'] = $short > 0;
            $add = min($short, $room);
            $allocation[$roleId] = ($allocation[$roleId] ?? 1) + $add;
            $room -= $add;
            $roles[$roleId]['budget_limited'] = $add < $short;
        }

        return ['allocation' => $allocation, 'roles' => $roles];
    }

    /**
     * The responsibilities a unit (a bullet's worth of facts) states.
     *
     * @param  array<string, mixed>  $unit
     * @param  array<string, array<string, mixed>>  $offered
     * @return array<int, string>
     */
    private function responsibilitiesOf(array $unit, array $offered): array
    {
        return array_values(array_unique(array_merge([], ...array_map(fn (string $k) => StoryDimensions::responsibilities((string) ($offered[$k]['statement'] ?? '')), $unit['facts']))));
    }

    /**
     * A greedy minimal cover: repeatedly the unit that adds the most responsibilities not yet shown, until none adds any.
     * Ties keep the profile's order. Each member of the result is the responsibilities its unit contributed.
     *
     * @param  array<int, array<int, string>>  $unitResponsibilities  one list per unit, in the profile's order
     * @return array<int, array<int, string>>
     */
    private function responsibilityCover(array $unitResponsibilities): array
    {
        $covered = [];
        $cover = [];

        while (true) {
            $best = null;

            foreach ($unitResponsibilities as $i => $shown) {
                $gain = array_values(array_diff($shown, $covered));

                if ($gain !== [] && ($best === null || count($gain) > count($best[1]))) {
                    $best = [$i, $gain];
                }
            }

            if ($best === null) {
                return $cover;
            }

            $cover[] = $best[1];
            $covered = [...$covered, ...$best[1]];
            unset($unitResponsibilities[$best[0]]);
        }
    }

    /**
     * What a role's coverage came to, for the trace and the composition check: how many bullets, which responsibilities they
     * show, and why a role short of its target is short (`evidence`: it has little approved evidence stating a responsibility;
     * `budget`: the resume is at its bullet ceiling; `selection`: evidence was available and not chosen).
     *
     * @param  array<string, mixed>  $plan  coveragePlan() for the role
     * @param  array<int, array<string, mixed>>  $chosen
     * @param  array<string, array<string, mixed>>  $offered
     * @return array<string, mixed>
     */
    private function coverageOutcome(array $plan, array $chosen, array $offered, string $title): array
    {
        $shows = array_values(array_unique(array_merge([], ...array_map(fn (array $u) => $this->responsibilitiesOf($u, $offered), $chosen))));
        $minimum = (int) config('resume.composition.coverage.minimum');
        $achieved = count($chosen);
        $short = $plan['applies'] && $achieved < $minimum;

        return [
            'title' => $title, 'applies' => $plan['applies'], 'years' => $plan['years'], 'target' => $plan['target'], 'floor' => $plan['floor'], 'achieved' => $achieved,
            'shows' => array_values(array_intersect(StoryDimensions::RESPONSIBILITIES, $shows)),
            'supported' => array_values(array_intersect(StoryDimensions::RESPONSIBILITIES, $plan['responsibilities_supported'])),
            'cover_size' => $plan['cover_size'],
            'short_of' => $short ? $minimum : null,
            'reason' => ! $short ? null : match (true) {
                $plan['cover_size'] < $minimum => 'evidence',
                $plan['budget_limited'] => 'budget',
                default => 'selection',
            },
        ];
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

            // The user's own order counts: their first project is protected most, then the next two.
            $weights[$p['project_id']] = $p['pinned'] ? 1.35 : ($rank < $priorityCount ? 1.3 - 0.1 * min($rank, 2) : 1.0);
            $rank++;
        }

        return $weights;
    }

    /**
     * The kind of evidence a statement is. Order matters: the most specific kinds are tested first.
     */
    private function facet(string $statement, string $kind, bool $story = false): string
    {
        if ($kind === 'platform_use' || $kind === 'outcome') {
            return $kind === 'outcome' ? 'outcome' : 'platform_use';
        }

        $caps = $this->coverage->capabilitiesIn($statement);
        $has = fn (array $keys) => array_intersect($caps, $keys) !== [];

        return match (true) {
            $story && StoryRole::isDeployment($statement) => 'deployment',
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
     * @return array{facts: array<int, string>, rank: int, project_id: int|null, score: float, facet: string, has_outcome: bool, pinned: bool, boost: float, shared: bool, technical: bool, story: bool, general: bool}
     */
    private function unit(array $facts, int $rank, ?int $projectId, float $score, string $facet, bool $hasOutcome, bool $pinned): array
    {
        return ['facts' => $facts, 'rank' => $rank, 'project_id' => $projectId, 'score' => $score, 'facet' => $facet, 'has_outcome' => $hasOutcome, 'pinned' => $pinned, 'boost' => 1.0, 'shared' => false, 'technical' => false, 'story' => false, 'general' => false];
    }

    /**
     * Fills one role's bullets by kind of evidence (see the class comment).
     *
     * @param  array<int, array<string, mixed>>  $units
     * @param  array{per_project_max: int, weights: array<int, float>, priority: array<int, int>, overlap_limit: float, extra: int, role_has_projects: bool, technical_floor: bool, texts: array<string, string>, job_priority: array<int, int>, min_job: float|null, generic_max: int|null, coverage?: int}  $ctx
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
            $weight = $project === null ? ($u['facet'] === 'outcome' || ! $ctx['role_has_projects'] || ($u['job'] ?? 0.0) >= 0.5 ? 1.0 : 0.9) : ($ctx['weights'][$project] ?? 0.9);

            // A claim shared by several projects and phrased in general terms ("owned the frontend", "built it in TypeScript")
            // is weaker as a bullet than concrete work specific to one.
            $shared = $u['shared'] && in_array($u['facet'], ['frontend', 'ownership', 'generic_dev'], true) ? 0.8 : 1.0;

            // What the job asks for weighs heavily; without a job (a reference resume) this factor is 1.
            $job = ($u['job'] ?? null) === null ? 1.0 : 0.25 + 2.2 * $u['job'] ** 1.5;

            $facet = self::FACET_WEIGHT[$u['facet']] ?? 1.0;

            // The profile's preferences (kinds of evidence, its priority projects) are a prior for a job, not the verdict:
            // they count half as much, so evidence the job asks for can compete with the profile's favorites.
            if (($u['job'] ?? null) !== null) {
                // A second bullet of a generic kind is not redundant when the job asks for exactly that evidence.
                $novelty = $novelty + (1.0 - $novelty) * $u['job'];

                // Three bullets that answer the same requirement say less than three that answer three.
                $answered = array_unique(array_merge([], ...array_map(fn (array $c) => $c['findings'] ?? [], $state->chosen)));
                $own = (array) ($u['findings'] ?? []);

                if ($own !== []) {
                    $fresh = array_diff($own, $answered);
                    $novelty *= $fresh === [] ? 0.7 : (count($fresh) < count($own) ? 0.9 : 1.0);
                }
                $facet = 1.0 + ($facet - 1.0) * 0.5;
                $weight = 1.0 + ($weight - 1.0) * 0.5;
            }

            // A complete story of what was built is a more persuasive bullet than a general claim of the same relevance.
            $story = $u['story'] ? (float) config('resume.composition.allocation.story_preference') : 1.0;

            // A result with no work attached says little however well it is quantified.
            if ($u['facet'] === 'outcome' && ($u['job'] ?? null) !== null) {
                $story *= (float) config('resume.composition.allocation.bare_outcome');
            }

            return $job * (0.8 + 0.2 * $u['score'] / $maxScore) * $facet * $novelty * $sameFacetInProject * $weight * $shared * $story * $u['boost'] * ($u['pinned'] ? 1.4 : 1.0);
        };

        $eligible = function (array $u, bool $coverage = false) use ($state, $ctx): bool {
            if ($ctx['generic_max'] !== null && in_array($u['facet'], ['ownership', 'leadership'], true) && ! $u['has_outcome'] && ! $u['pinned']
                && count(array_filter($state->chosen, fn (array $c) => in_array($c['facet'], ['ownership', 'leadership'], true) && ! $c['has_outcome'])) >= $ctx['generic_max']) {
                return false;
            }

            // A role keeps its first bullet whatever the job asks (role completeness); after that, a slot is not filled with
            // evidence the job does not ask for. Pinned evidence is the user's decision and always stays eligible.
            // A coverage slot (see below) is filled from the role's own responsibilities, which a job's keywords do not rank.
            if (! $coverage && $ctx['min_job'] !== null && $state->chosen !== [] && ! $u['pinned'] && ($u['job'] ?? null) !== null && $u['job'] < $ctx['min_job']) {
                return false;
            }

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

        // A developer or engineer role shows at least one thing that was built, not only results and engagements.
        if ($ctx['technical_floor'] && array_filter($state->chosen, fn (array $u) => $u['technical']) === [] && array_filter($remaining, fn (array $u) => $u['technical']) !== []) {
            usort($state->chosen, fn (array $a, array $b) => $a['selected_score'] <=> $b['selected_score']);
            $weakest = array_shift($state->chosen);

            if ($weakest !== null && ! $weakest['pinned']) {
                // Judge the candidate as if the bullet it would replace were gone (its kind no longer counts against it).
                $state->facetCount[$weakest['facet']] = max(0, ($state->facetCount[$weakest['facet']] ?? 0) - 1);

                if ($weakest['project_id'] !== null) {
                    unset($state->projectFacet[$weakest['project_id'].'|'.$weakest['facet']]);
                    $state->perProject[$weakest['project_id']] = max(0, ($state->perProject[$weakest['project_id']] ?? 0) - 1);
                }

                $technical = $best(array_filter($remaining, fn (array $u) => $u['technical']));

                if ($technical !== null && $technical[1] >= 0.6 * $weakest['selected_score']) {
                    $remaining[] = $weakest;
                    $take($technical[0], $technical[1]);
                    $remaining = array_values(array_filter($remaining, fn (array $u) => $u !== $technical[0]));
                } else {
                    $take($weakest, $weakest['selected_score']);
                }
            } elseif ($weakest !== null) {
                array_unshift($state->chosen, $weakest);
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

        // A job's strongest requirements each deserve a bullet, as its top projects do: swap in the best unit that answers a
        // requirement nothing chosen answers, for the weakest bullet that is not the only answer to another.
        foreach ($ctx['job_priority'] as $findingId) {
            $answered = fn (array $us) => array_filter($us, fn (array $u) => in_array($findingId, $u['findings'] ?? [], true)) !== [];
            // A general claim of ownership or leadership answers a requirement in name only; it has to earn a slot by its score.
            $inRole = array_values(array_filter($remaining, fn (array $u) => in_array($findingId, $u['findings'] ?? [], true) && ! $u['general']));

            if ($answered($state->chosen) || $inRole === [] || $state->chosen === []) {
                continue;
            }

            $candidate = $best($inRole);

            if ($candidate === null) {
                continue;
            }

            usort($state->chosen, fn (array $a, array $b) => $a['selected_score'] <=> $b['selected_score']);
            $victimIndex = null;

            foreach ($state->chosen as $i => $c) {
                $soleCover = array_filter($ctx['job_priority'], fn (int $f) => in_array($f, $c['findings'] ?? [], true) && count(array_filter($state->chosen, fn (array $o) => in_array($f, $o['findings'] ?? [], true))) === 1) !== [];
                $soleProject = $c['project_id'] !== null && in_array($c['project_id'], $ctx['priority'], true)
                    && count(array_filter($state->chosen, fn (array $o) => $o['project_id'] === $c['project_id'])) === 1;

                if (! $c['pinned'] && ! $soleCover && ! $soleProject) {
                    $victimIndex = $i;

                    break;
                }
            }

            if ($victimIndex !== null && $candidate[1] >= 0.6 * $state->chosen[$victimIndex]['selected_score']) {
                $remaining[] = $state->chosen[$victimIndex];
                array_splice($state->chosen, $victimIndex, 1);
                $take($candidate[0], $candidate[1]);
                $remaining = array_values(array_filter($remaining, fn (array $u) => $u !== $candidate[0]));
            }
        }

        // Role coverage: a long role shows each responsibility its approved evidence states (what was built, owning the work
        // across its lifecycle, working directly with clients, the business and delivery side), in up to `coverage` bullets.
        // Relevance has already filled what the job asks for. A free slot goes to the bullet that shows the most responsibilities
        // not yet shown; with no free slot, a bullet the job does not value (below `relevant_at`) and whose responsibilities
        // others already show gives way. Only evidence that states a responsibility is ever added, a bullet that adds nothing new
        // is never coverage, and a role with less evidence than the target simply has fewer bullets.
        if (($ctx['coverage'] ?? 0) > 0) {
            $texts = array_map(fn (string $text) => ['statement' => $text], $ctx['texts']);
            $responsibilities = fn (array $u): array => $this->responsibilitiesOf($u, $texts);
            $shownBy = fn (array $units): array => array_values(array_unique(array_merge([], ...array_map($responsibilities, $units))));
            $relevantAt = (float) config('resume.composition.allocation.relevant_at');

            while (true) {
                $covered = $shownBy($state->chosen);
                $top = null;

                foreach ($remaining as $u) {
                    $gain = count(array_diff($responsibilities($u), $covered));

                    if ($gain === 0 || ! $eligible($u, true)) {
                        continue;
                    }

                    $rank = [$gain, $adjusted($u)];

                    if ($top === null || $rank > $top[1]) {
                        $top = [$u, $rank];
                    }
                }

                if ($top === null) {
                    break;
                }

                if (count($state->chosen) >= $ctx['coverage']) {
                    $victim = null;

                    foreach ($state->chosen as $i => $c) {
                        $others = array_values(array_filter($state->chosen, fn (array $o) => $o !== $c));
                        $soleCover = array_filter($ctx['job_priority'], fn (int $f) => in_array($f, $c['findings'] ?? [], true) && count(array_filter($others, fn (array $o) => in_array($f, $o['findings'] ?? [], true))) === 0) !== [];
                        $soleProject = $c['project_id'] !== null && in_array($c['project_id'], $ctx['priority'], true) && count(array_filter($others, fn (array $o) => $o['project_id'] === $c['project_id'])) === 0;

                        if (! $c['pinned'] && ($c['job'] ?? 0.0) < $relevantAt && ! $soleCover && ! $soleProject && array_diff($responsibilities($c), $shownBy($others)) === [] && ($victim === null || $c['selected_score'] < $state->chosen[$victim]['selected_score'])) {
                            $victim = $i;
                        }
                    }

                    if ($victim === null) {
                        break;
                    }

                    // Judge the replacement as if the bullet it takes the place of were gone (its kind and project no longer count).
                    $gone = $state->chosen[$victim];
                    $state->facetCount[$gone['facet']] = max(0, ($state->facetCount[$gone['facet']] ?? 0) - 1);

                    if ($gone['project_id'] !== null) {
                        unset($state->projectFacet[$gone['project_id'].'|'.$gone['facet']]);
                        $state->perProject[$gone['project_id']] = max(0, ($state->perProject[$gone['project_id']] ?? 0) - 1);
                    }

                    array_splice($state->chosen, $victim, 1);
                    $remaining[] = $gone;
                }

                $take($top[0], $top[1][1]);
                $remaining = array_values(array_filter($remaining, fn (array $u) => $u !== $top[0]));
            }
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
     * @param  array{boost?: array<string, float>, shared?: array<string, bool>}  $meta
     * @return array<int, array<string, mixed>>
     */
    private function units(array $candidates, array $offered, int $outcomesPerLead, float $overlapLimit, array $meta = [], bool $story = false): array
    {
        // A statement that only says a technology was used is a skill, not a bullet: it fills a slot last.
        $candidates = array_map(fn (array $a) => $a['kind'] === 'platform_use' ? [...$a, 'score' => $a['score'] * 0.5] : $a, $candidates);
        $facetOf = fn (array $a) => $this->facet((string) ($offered[$a['fact_key']]['statement'] ?? ''), (string) $a['kind'], $story);
        $units = [];
        $byProject = [];

        $ridesWith = $story ? $this->pairRoleLevelResults($candidates, $offered) : [];
        $rides = array_merge([], ...array_values($ridesWith));
        $score = array_column($candidates, 'score', 'fact_key');

        foreach ($candidates as $a) {
            if ($a['project_id'] === null) {
                // A role-level result with no project is part of the work it describes, not a bullet of its own.
                if (in_array($a['fact_key'], $rides, true)) {
                    continue;
                }

                $with = $ridesWith[$a['fact_key']] ?? [];
                $units[] = $this->unit([$a['fact_key'], ...$with], $a['order_index'], null, max([(float) $a['score'], ...array_map(fn (string $k) => (float) $score[$k], $with)]) + 0.05 * count($with), $facetOf($a), $a['kind'] === 'outcome' || $with !== [], (bool) $a['pinned']);

                continue;
            }

            $byProject[$a['project_id']][] = $a;
        }

        // A reference resume keeps its original grouping; a project's full story (deployment, how, a fourth fact) is composed for a job.
        $maxFacts = $story ? (int) config('resume.composition.story.max_facts', 3) : 3;
        $leadMaxFacts = $story ? (int) config('resume.composition.story.lead_max_facts', 4) : 3;

        foreach ($byProject as $projectId => $items) {
            $outcomes = array_values(array_filter($items, fn (array $a) => $a['kind'] === 'outcome'));
            $rest = array_values(array_filter($items, fn (array $a) => $a['kind'] !== 'outcome'));
            // Delivered or deployed to a named audience: part of the project's story, not a bullet of its own.
            $deploy = $story ? array_values(array_filter($rest, fn (array $a) => StoryRole::isDeployment((string) ($offered[$a['fact_key']]['statement'] ?? '')))) : [];
            $others = array_values(array_filter($rest, fn (array $a) => ! in_array($a, $deploy, true)));
            // A bare platform mention ("worked with Azure identity management") is not appended to a project's bullet: it stays
            // evidence for the skills list. A project that has nothing else keeps it.
            $others = $story ? (array_values(array_filter($others, fn (array $a) => ! ($a['bare_tag'] ?? false))) ?: $others) : $others;
            usort($others, fn (array $a, array $b) => $b['score'] <=> $a['score']);
            usort($deploy, fn (array $a, array $b) => $b['score'] <=> $a['score']);
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

            if ($facets === [] && $deploy !== []) {
                $facets[] = 'deployment';
                $members[] = array_splice($deploy, 0, 1);
            }

            // A project's story hangs off the bullet that says what was built (a generic ownership or design claim is not
            // the story): it gets, as room allows, its measured result, where it was deployed, a second result and one
            // distinct way it was implemented. The lead may carry `lead_max_facts`, any other bullet `max_facts`, and the
            // other bullets of the project take a result of their own. Not every story has every part.
            $leadIndex = 0;

            foreach ($story ? $facets : [] as $i => $facet) {
                if (StoryRole::isBuild((string) ($offered[$members[$i][0]['fact_key']]['statement'] ?? ''))) {
                    $leadIndex = $i;

                    break;
                }
            }

            $merged = [];
            $order = array_values(array_unique([$leadIndex, ...array_keys($facets)]));

            foreach ($order as $i) {
                if (isset($merged[$i])) {
                    continue;
                }

                $facet = $facets[$i];
                $isLead = $i === $leadIndex;
                $room = ($isLead ? $leadMaxFacts : $maxFacts) - count($members[$i]);
                $wantOutcomes = $isLead ? $outcomesPerLead : 1;
                $attached = [];
                $takeOutcome = function () use (&$outcomes, &$attached, &$room, &$wantOutcomes): void {
                    if ($room > 0 && $wantOutcomes > 0 && $outcomes !== []) {
                        $attached[] = array_shift($outcomes);
                        $room--;
                        $wantOutcomes--;
                    }
                };

                $takeOutcome();

                if ($isLead && $room > 0 && $deploy !== []) {
                    $attached[] = array_shift($deploy);
                    $room--;
                }

                $takeOutcome();

                $lead = (string) ($offered[$members[$i][0]['fact_key']]['statement'] ?? '');

                if ($story && $isLead && $room > 0 && count($members[$i]) === 1 && StoryRole::isBuild($lead)) {
                    foreach ($facets as $j => $other) {
                        $text = (string) ($offered[$members[$j][0]['fact_key']]['statement'] ?? '');

                        if ($j !== $i && ! isset($merged[$j]) && count($members[$j]) === 1 && in_array($other, ['architecture', 'backend_api', 'security_reliability', 'implementation', 'frontend'], true)
                            && $other !== $facet && $this->overlap($lead, $text) < $overlapLimit) {
                            $attached[] = $members[$j][0];
                            $merged[$j] = true;

                            break;
                        }
                    }
                }

                $all = [...$members[$i], ...$attached];

                $unit = $this->unit(
                    array_column($all, 'fact_key'),
                    (int) min([PHP_INT_MAX, ...array_column($all, 'order_index')]),
                    (int) $projectId,
                    max([0.0, ...array_column(array_filter($all, fn (array $m) => ! ($m['bare_tag'] ?? false)) ?: $all, 'score')]) + 0.05 * count(array_filter($attached, fn (array $a) => $a['kind'] === 'outcome')),
                    $facet,
                    array_filter($attached, fn (array $a) => $a['kind'] === 'outcome') !== [] || $facet === 'outcome',
                    in_array(true, array_column($all, 'pinned'), true),
                );
                // A complete story: it says what was built and adds how, where it was used or what it achieved.
                $unit['story'] = $story && $isLead && $attached !== [] && StoryRole::isBuild($lead);
                $units[] = $unit;
            }

            // Evidence the story had no room for stands alone rather than disappearing.
            foreach ([...$outcomes, ...$deploy] as $a) {
                $units[] = $this->unit([$a['fact_key']], $a['order_index'], (int) $projectId, (float) $a['score'], $a['kind'] === 'outcome' ? 'outcome' : 'deployment', $a['kind'] === 'outcome', (bool) $a['pinned']);
            }
        }

        // What the approved profile says about these facts: its cited ones weigh a little more; a claim shared by several places
        // is flagged; and whether the lead fact states something that was built.
        // For a job-tailored resume the accomplishments carry the job's relevance; a reference resume has none.
        $jobByKey = array_column($candidates, 'job_relevance', 'fact_key');
        $findingsByKey = array_column($candidates, 'job_findings', 'fact_key');
        $generalByKey = array_column($candidates, 'general', 'fact_key');
        $tagByKey = array_column($candidates, 'bare_tag', 'fact_key');
        $units = array_map(function (array $u) use ($meta, $offered, $jobByKey, $findingsByKey, $generalByKey, $tagByKey, $story) {
            $lead = (string) ($offered[$u['facts'][0]]['statement'] ?? '');
            // A bare platform mention riding with real work adds nothing to how relevant the work is.
            $substantive = array_values(array_filter($u['facts'], fn (string $k) => ! ($tagByKey[$k] ?? false))) ?: $u['facts'];
            $jobScores = array_values(array_filter(array_map(fn (string $k) => $jobByKey[$k] ?? null, $substantive), fn ($v) => $v !== null));

            return [
                ...$u,
                'general' => $story && array_filter($u['facts'], fn (string $k) => ! ($generalByKey[$k] ?? false)) === [],
                'job' => $jobScores === [] ? null : max($jobScores),
                'findings' => array_values(array_unique(array_merge([], ...array_map(fn (string $k) => $findingsByKey[$k] ?? [], $u['facts'])))),
                'boost' => max([1.0, ...array_map(fn (string $k) => $meta['boost'][$k] ?? 1.0, $u['facts'])]),
                'shared' => array_filter($u['facts'], fn (string $k) => isset($meta['shared'][$k])) !== [],
                'technical' => in_array($u['facet'], ['architecture', 'security_reliability', 'backend_api', 'frontend'], true)
                    || (preg_match('/^(?:Built|Implemented|Developed|Integrated|Designed|Automated|Created|Configured)\b/', $lead) === 1 && $u['facet'] !== 'outcome'),
            ];
        }, $units);

        usort($units, fn (array $a, array $b) => $a['rank'] <=> $b['rank']);

        return $units;
    }

    /**
     * Which role-level results describe which role-level work. A project's results join its bullet because they share the
     * project; a result that belongs to a role has no project, so it is matched to the work it describes by what they say:
     * a result joins the role-level, non-result fact that shares at least two significant words with it ("new-merchant
     * onboarding effort" with "...merchant payment-gateway integration and internal onboarding"), the closest first. A result
     * that matches nothing stays a bullet of its own.
     *
     * @param  array<int, array<string, mixed>>  $candidates
     * @param  array<string, array<string, mixed>>  $offered
     * @return array<string, array<int, string>> lead fact key => result fact keys (at most two)
     */
    private function pairRoleLevelResults(array $candidates, array $offered): array
    {
        $roleLevel = array_values(array_filter($candidates, fn (array $a) => $a['project_id'] === null));
        $words = fn (string $t) => array_values(array_unique(array_map(fn (string $w) => substr($w, 0, 6), array_filter(preg_split('/[^a-z0-9]+/', strtolower($t)) ?: [], fn (string $w) => strlen($w) >= 6))));
        $leads = array_values(array_filter($roleLevel, fn (array $a) => $a['kind'] !== 'outcome' && ! ($a['general'] ?? false)));
        $pairs = [];

        // A result states what changed ("Reduced ...", "Saved ..."); a description of the work that the profile happens to call an
        // outcome ("Led SEO for 34 programs ...") is not one.
        $isResult = fn (string $statement) => preg_match('/^\s*(?:Reduced|Saved|Cut|Increased|Improved|Grew|Decreased|Lowered|Accelerated|Eliminated|Boosted)\b/', $statement) === 1;

        foreach (array_filter($roleLevel, fn (array $a) => $a['kind'] === 'outcome' && $isResult((string) ($offered[$a['fact_key']]['statement'] ?? ''))) as $result) {
            $best = null;
            $bestShared = 1;

            foreach ($leads as $lead) {
                $shared = count(array_intersect($words((string) ($offered[$result['fact_key']]['statement'] ?? '')), $words((string) ($offered[$lead['fact_key']]['statement'] ?? ''))));

                if ($shared > $bestShared || ($shared === $bestShared && $best !== null && $shared >= 2 && $lead['score'] > $best['score'])) {
                    $best = $lead;
                    $bestShared = $shared;
                }
            }

            if ($best !== null && count($pairs[$best['fact_key']] ?? []) < 2) {
                $pairs[$best['fact_key']][] = $result['fact_key'];
            }
        }

        return $pairs;
    }

    /**
     * The facts a cited list can actually use here. A fact the profile cites may not be the one offered when it is one
     * place of a shared claim (the claim is offered once, under its strongest place): the offered place stands for it,
     * because it is the same claim, so approved summary content never loses its evidence to claim collapsing.
     *
     * @param  array<int, string>  $keys
     * @param  array<string, array<string, mixed>>  $offered
     * @return array<int, string>
     */
    private function offeredKeys(array $keys, array $offered): array
    {
        $missing = array_values(array_filter(array_unique($keys), fn (string $k) => ! isset($offered[$k])));
        $groups = $missing === [] ? [] : CareerFact::query()->whereIn('key', $missing)->whereNotNull('claim_group')->pluck('claim_group', 'key')->all();
        $stand = $groups === [] ? [] : CareerFact::query()->whereIn('key', array_keys($offered))->whereIn('claim_group', array_values($groups))->pluck('key', 'claim_group')->all();
        $out = [];

        foreach (array_unique($keys) as $k) {
            $resolved = isset($offered[$k]) ? $k : ($stand[$groups[$k] ?? ''] ?? null);

            if ($resolved !== null) {
                $out[] = $resolved;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * What the approved profile says about facts: those its differentiators and summary cite weigh a little more,
     * and facts that are places of a shared claim are flagged.
     *
     * @param  array<string, mixed>  $effective
     * @param  array<string, array<string, mixed>>  $offered
     * @return array{boost: array<string, float>, shared: array<string, bool>}
     */
    private function meta(array $effective, array $offered): array
    {
        $boost = [];

        foreach ($this->offeredKeys($effective['presentation']['summary_fact_keys'], $offered) as $k) {
            $boost[$k] = max($boost[$k] ?? 1.0, 1.1);
        }

        foreach (array_filter($effective['presentation']['differentiators'], fn (array $d) => $d['included']) as $d) {
            foreach ($this->offeredKeys($d['fact_keys'], $offered) as $k) {
                $boost[$k] = max($boost[$k] ?? 1.0, 1.15);
            }
        }

        $shared = [];

        foreach ($offered as $key => $f) {
            if (! empty($f['shared_claim']['also_attached_to'])) {
                $shared[$key] = true;
            }
        }

        return ['boost' => $boost, 'shared' => $shared];
    }

    /**
     * What the approved summary states that the generated summary must keep: the technologies it names that its
     * cited evidence supports, and the projects it names that a cited fact mentions. Never a demand for something
     * the evidence does not state.
     *
     * @param  array<string, mixed>  $effective
     * @param  array<int, string>  $evidenceStatements  the statements of the facts the summary may draw on
     * @return array{capabilities: array<string, string>, names: array<int, string>}
     */
    public function retention(array $effective, array $evidenceStatements): array
    {
        $approved = (string) $effective['presentation']['summary'];
        $evidence = implode(' ', $evidenceStatements);
        $supported = $this->coverage->capabilitiesIn($evidence);
        $capabilities = [];

        foreach ($this->coverage->capabilitiesIn($approved) as $key) {
            if (config("resume_profiles.capabilities.{$key}.category") === 'technology' && in_array($key, $supported, true)) {
                $capabilities[$key] = (string) config("resume_profiles.capabilities.{$key}.label", $key);
            }
        }

        $names = [];

        foreach ($effective['projects'] as $p) {
            if (! $p['personal'] && stripos($approved, (string) $p['name']) !== false && stripos($evidence, (string) $p['name']) !== false) {
                $names[] = (string) $p['name'];
            }
        }

        return ['capabilities' => $capabilities, 'names' => $names];
    }

    /**
     * The Technical Skills section, from the curated profile rather than citation counts: a skill scores for being a
     * direct capability the user kept (weighted by how often the market asks for it), for being part of the stack of
     * the user's included employment projects (priority projects count fully), and a little for being cited by a
     * chosen bullet. A skill that only restricted evidence carries is never listed. Practice and capability tags are
     * limited so the section stays a short, relevant stack and not an inventory.
     *
     * @param  array<string, mixed>  $effective
     * @param  array<string, array<string, mixed>>  $offered
     * @param  array<int, array<string, mixed>>  $experience
     * @param  array<int, string>  $summaryKeys
     * @return array<int, array{skill_id: int, order: int}>
     */
    private function skills(array $effective, array $offered, array $experience, array $summaryKeys, ?string $layout = null): array
    {
        $cfg = (array) config('resume_profiles.reference');
        $kept = [];

        foreach ($effective['capabilities']['direct'] as $c) {
            if ($c['included']) {
                $kept[$c['key']] = 1.0 + (float) ($c['market_share'] ?? 0);
            }
        }

        $priority = array_slice(array_values(array_filter($effective['projects'], fn (array $p) => ! $p['personal'] && $p['included'])), 0, (int) $cfg['priority_projects']);
        $priorityIds = array_column($priority, 'project_id');
        $includedIds = array_column(array_filter($effective['projects'], fn (array $p) => ! $p['personal'] && $p['included']), 'project_id');
        $cited = array_merge(...array_map(fn (array $r) => array_merge(...array_column($r['bullet_groups'], 'career_fact_keys') ?: [[]]), $experience ?: [['bullet_groups' => []]]));
        $summaryCaps = $this->coverage->capabilitiesIn(implode(' ', array_map(fn (string $k) => (string) ($offered[$k]['statement'] ?? ''), $summaryKeys)).' '.(string) $effective['presentation']['summary']);
        $scores = [];
        $meta = [];

        foreach ($offered as $key => $f) {
            $project = $f['attribution']['project_id'] ?? null;

            foreach ($f['skills'] ?? [] as $skill) {
                // Only skills the reference resume can present. They are general technologies (never a confidential system),
                // so a skill that only a restricted fact carries may still be listed.
                if (SkillPresentation::for((string) $skill['name'], $layout) === null) {
                    continue;
                }

                $id = (int) $skill['id'];
                $meta[$id] = $skill;
                $scores[$id]['projects'][$project ?? 0] = true;
                // Direct professional experience: a fact of an employer's role. A skill only a personal project or a
                // whole-career statement carries is an isolated tag, and ranks below skills used on the job.
                $scores[$id]['professional'] = ($scores[$id]['professional'] ?? 0) + (($f['attribution']['role_id'] ?? null) !== null ? 1 : 0);
                $scores[$id]['cited'] = ($scores[$id]['cited'] ?? 0) + (in_array($key, $cited, true) ? 1 : 0);
            }
        }

        $rows = [];

        foreach ($scores as $id => $s) {
            $skill = $meta[$id];
            $caps = $this->coverage->capabilitiesIn((string) $skill['name']);
            $capScore = max([0.0, ...array_map(fn (string $c) => $kept[$c] ?? 0.0, $caps)]);
            $stack = 0.0;

            foreach (array_keys($s['projects']) as $projectId) {
                if (in_array($projectId, $priorityIds, true)) {
                    $stack += 1.0;
                } elseif (in_array($projectId, $includedIds, true)) {
                    $stack += 0.5;
                }
            }

            $score = 0.8 * $capScore + min(1.5, $stack) + min(0.75, 0.25 * $s['cited']) + (array_intersect($caps, $summaryCaps) !== [] ? 0.5 : 0.0)
                + (in_array($skill['name'], (array) config('resume.skill_presentation.core'), true) ? 1.5 : 0.0);
            // For a job, a skill with no professional use is discounted (never for the reference resume, whose order is the user's).
            $score *= $layout !== null && $s['professional'] === 0 ? (float) config('resume.composition.skills.isolated_tag') : 1.0;
            $technology = in_array($skill['category'], ['build_technology', 'platform_integration'], true);

            if ($score > 0) {
                $rows[] = ['id' => $id, 'name' => (string) $skill['name'], 'score' => $score, 'technology' => $technology];
            }
        }

        usort($rows, fn (array $a, array $b) => [$b['score'], $a['name']] <=> [$a['score'], $b['name']]);
        $perCapability = [];
        $perGroup = [];
        $shown = [];
        $out = [];
        $limit = (int) ($cfg['skills'] ?? 14);
        $perGroupMax = SkillPresentation::maxPerGroup($layout);
        $take = function (array $r) use (&$perCapability, &$perGroup, &$shown, &$out, $perGroupMax, $limit, $layout): bool {
            [$group, $label] = SkillPresentation::for($r['name'], $layout) ?? ['', ''];
            $family = $this->coverage->capabilitiesIn($r['name'])[0] ?? 'skill:'.$r['id'];

            // A category holds a handful; cloud platforms at most two (a third says less than two); two skills that present
            // under one label (OpenAI and Anthropic as "LLM integration") are one line item.
            if (count($out) >= $limit || ($perGroup[$group] ?? 0) >= $perGroupMax || isset($shown[$label]) || ($family === 'cloud_platforms' && ($perCapability[$family] ?? 0) >= 2)) {
                return false;
            }

            $shown[$label] = true;
            $perCapability[$family] = ($perCapability[$family] ?? 0) + 1;
            $perGroup[$group] = ($perGroup[$group] ?? 0) + 1;
            $out[] = ['skill_id' => $r['id'], 'order' => count($out) + 1];

            return true;
        };

        // Breadth first: every category the evidence supports gets its strongest two, then the rest fill by score. Without
        // this a deep stack (many database and cloud tags) crowds out a core language that has a single supporting fact.
        foreach (SkillPresentation::groups($layout) as $group) {
            $taken = 0;

            foreach ($rows as $r) {
                if ($taken < 2 && (SkillPresentation::for($r['name'], $layout)[0] ?? '') === $group && $take($r)) {
                    $taken++;
                }
            }
        }

        foreach ($rows as $r) {
            $take($r);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $effective
     * @param  array<string, array<string, mixed>>  $offered
     * @param  array<int, array<int, array<string, mixed>>>  $candidates
     * @return array<int, string>
     */
    private function summaryFacts(array $effective, array $offered, array $candidates): array
    {
        $cited = $this->offeredKeys($effective['presentation']['summary_fact_keys'], $offered);
        $keys = $cited;

        foreach (array_filter($effective['presentation']['differentiators'], fn (array $d) => $d['included']) as $d) {
            $keys = [...$keys, ...$this->offeredKeys($d['fact_keys'], $offered)];
        }

        $keys = array_values(array_unique($keys));

        if ($keys === []) {
            $keys = array_slice(array_column(array_merge(...array_values($candidates ?: [[]])), 'fact_key'), 0, 3);
        }

        // Every fact the summary itself cites stays: the summary's wording was validated against exactly those.
        return array_slice($keys, 0, max((int) config('resume_profiles.reference.summary_facts'), count($cited)));
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
