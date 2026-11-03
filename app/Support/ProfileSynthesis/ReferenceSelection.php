<?php

namespace App\Support\ProfileSynthesis;

use App\Models\Role;

/**
 * Turns an approved profile into a Selection response for a reference resume, deterministically. It is
 * exactly the shape the model's Selection returns, so the same ResumeSelectionResponseValidator judges it
 * (eligibility, one-employer attribution, whole-career facts never in Experience, role completeness,
 * budgets). Every decision is the user's own (the include, exclude, pin and order of the profile) plus a
 * fixed budget: bullets are allotted to roles by how much of the profile's strongest evidence each holds,
 * a project's outcomes are grouped with its lead implementation, and no project dominates.
 * The trace records what was selected and what was left out, and why.
 */
final class ReferenceSelection
{
    public const VERSION = 'reference-selection-v1';

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
        $projectOrder = array_flip(array_column($effective['projects'], 'project_id'));
        $experience = [];
        $traceRoles = [];

        foreach ($roleIds as $roleId) {
            $units = $this->units($candidates[$roleId] ?? [], (int) $cfg['outcomes_per_lead']);
            $fallback = false;

            if ($units === []) {
                // Role completeness: every role that has eligible evidence must appear. Use its strongest fact.
                $best = array_values(array_filter($offered, fn (array $f) => ($f['attribution']['role_id'] ?? null) === $roleId))[0];
                $units = [['facts' => [$best['key']], 'rank' => PHP_INT_MAX, 'project_id' => $best['attribution']['project_id'] ?? null]];
                $fallback = true;
            }

            [$chosen, $left] = $this->pick($units, $allocation[$roleId] ?? 1, (int) $cfg['per_project_max'], $projectOrder);

            foreach ($left as [$unit, $why]) {
                foreach ($unit['facts'] as $key) {
                    $omitted[] = ['fact_key' => $key, 'reason' => $why];
                }
            }

            $experience[] = [
                'role_id' => $roleId, 'title_choice' => $this->titleChoice((string) $titles[$roleId]),
                'bullet_groups' => array_map(fn (array $unit, int $n) => ['order' => $n + 1, 'career_fact_keys' => $unit['facts'], 'job_analysis_finding_ids' => []], $chosen, array_keys($chosen)),
            ];
            $traceRoles[] = ['role_id' => $roleId, 'bullets' => count($chosen), 'fallback' => $fallback, 'groups' => array_column($chosen, 'facts')];
        }

        $summary = $this->summaryFacts($effective, $offered, $candidates);

        return [
            'content' => [
                'summary_evidence' => $summary, 'skills' => [], 'experience' => $experience,
                'selected_projects' => $this->personalProjects($effective, $offered, (int) $cfg['personal_projects_max']),
                'target_term_usages' => [],
            ],
            'trace' => ['roles' => $traceRoles, 'summary_facts' => $summary, 'omitted' => $omitted],
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
     * Fills one role's bullets. Strong projects come first: each included project, in the user's order, gets its
     * best bullet before any role-level statement, so a role's flagship work is never crowded out by generic
     * claims. Then one measured outcome is guaranteed if the role has one, and the rest go by score.
     *
     * @param  array<int, array<string, mixed>>  $units
     * @param  array<int, int>  $projectOrder  project id => the user's position
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array{0: array<string, mixed>, 1: string}>}
     */
    private function pick(array $units, int $slots, int $perProjectMax, array $projectOrder): array
    {
        $chosen = [];
        $perProject = [];
        $take = function (array $unit) use (&$chosen, &$perProject, $slots, $perProjectMax): bool {
            $project = $unit['project_id'] ?? 0;

            if (count($chosen) >= $slots || ($project !== 0 && ($perProject[$project] ?? 0) >= $perProjectMax) || in_array($unit, $chosen, true)) {
                return false;
            }

            $chosen[] = $unit;
            $perProject[$project] = ($perProject[$project] ?? 0) + 1;

            return true;
        };

        $byProject = [];

        foreach ($units as $unit) {
            if ($unit['project_id'] !== null) {
                $byProject[$unit['project_id']][] = $unit;
            }
        }

        uksort($byProject, fn (int $a, int $b) => ($projectOrder[$a] ?? PHP_INT_MAX) <=> ($projectOrder[$b] ?? PHP_INT_MAX));

        foreach ($byProject as $group) {
            if (count($chosen) >= (int) ceil($slots * 0.6)) {
                break;
            }

            usort($group, fn (array $a, array $b) => $b['score'] <=> $a['score']);
            $take($group[0]);
        }

        $rest = $units;
        usort($rest, fn (array $a, array $b) => $b['score'] <=> $a['score']);

        if (array_filter($chosen, fn (array $u) => in_array($u['kind'], ['outcome', 'outcome_led'], true)) === []) {
            foreach ($rest as $unit) {
                if (in_array($unit['kind'], ['outcome', 'outcome_led'], true) && $take($unit)) {
                    break;
                }
            }
        }

        foreach ($rest as $unit) {
            $take($unit);
        }

        usort($chosen, fn (array $a, array $b) => $a['rank'] <=> $b['rank']);
        $left = [];

        foreach ($units as $unit) {
            if (! in_array($unit, $chosen, true)) {
                $left[] = [$unit, "Over this role's bullet budget, or its project already has enough bullets."];
            }
        }

        return [$chosen, $left];
    }

    /**
     * A project's outcomes ride with its lead implementation as one bullet; everything else stands alone.
     *
     * @param  array<int, array<string, mixed>>  $candidates  one role's accomplishments in the profile's order
     * @return array<int, array{facts: array<int, string>, rank: int, project_id: int|null, score: float, kind: string}>
     */
    private function units(array $candidates, int $outcomesPerLead): array
    {
        // A statement that only says a technology was used is a skill, not a bullet: it fills a slot last.
        $candidates = array_map(fn (array $a) => $a['kind'] === 'platform_use' ? [...$a, 'score' => $a['score'] * 0.5] : $a, $candidates);
        $units = [];
        $byProject = [];

        foreach ($candidates as $a) {
            if ($a['project_id'] === null) {
                $units[] = ['facts' => [$a['fact_key']], 'rank' => $a['order_index'], 'project_id' => null, 'score' => $a['score'], 'kind' => $a['kind']];

                continue;
            }

            $byProject[$a['project_id']][] = $a;
        }

        foreach ($byProject as $projectId => $items) {
            $outcomes = array_values(array_filter($items, fn (array $a) => $a['kind'] === 'outcome'));
            $others = array_values(array_filter($items, fn (array $a) => $a['kind'] !== 'outcome'));
            $lead = $others === [] ? array_splice($outcomes, 0, 1) : [array_shift($others)];
            $attached = array_splice($outcomes, 0, $outcomesPerLead);
            $unit = [...$lead, ...$attached];
            $units[] = ['facts' => array_column($unit, 'fact_key'), 'rank' => (int) min([PHP_INT_MAX, ...array_column($unit, 'order_index')]), 'project_id' => (int) $projectId, 'score' => max([0.0, ...array_column($unit, 'score')]) + 0.05 * count($attached), 'kind' => $attached === [] ? (string) ($lead[0]['kind'] ?? 'implementation') : 'outcome_led'];

            foreach ([...$others, ...$outcomes] as $a) {
                $units[] = ['facts' => [$a['fact_key']], 'rank' => $a['order_index'], 'project_id' => $projectId, 'score' => $a['score'], 'kind' => $a['kind']];
            }
        }

        usort($units, fn (array $a, array $b) => $a['rank'] <=> $b['rank']);

        return array_map(fn (array $u) => [
            'facts' => array_map('strval', $u['facts']), 'rank' => (int) $u['rank'], 'project_id' => $u['project_id'] === null ? null : (int) $u['project_id'],
            'score' => (float) $u['score'], 'kind' => (string) $u['kind'],
        ], $units);
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
