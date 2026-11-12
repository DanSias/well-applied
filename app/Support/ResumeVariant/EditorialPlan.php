<?php

namespace App\Support\ResumeVariant;

use App\Support\ProfileSynthesis\StoryDimensions;
use Illuminate\Support\Collection;

/**
 * What the wording stage is told about the resume as a whole, so each bullet is written as part of a story rather than in
 * isolation: why a bullet matters to the job, what it demonstrates, what the bullets next to it already establish, and which
 * results and claims another bullet has already made. It is a plan, not evidence: it adds no fact, and every claim is still
 * judged against the facts the bullet cites.
 *
 * Deterministic; built from the approved selection, the facts it cites and the job's own findings.
 */
final class EditorialPlan
{
    /**
     * @param  array<int, RoleSelectionDraft>  $roles
     * @param  Collection<string, array<string, mixed>>  $facts  eligible facts by key
     * @param  array<string, mixed>  $relevance  JobRelevance::analyze()
     * @return array{career_narrative: string, ownership_in: string|null, groups: array<string, array<string, mixed>>}
     */
    public function build(array $roles, Collection $facts, array $relevance): array
    {
        $flat = [];

        foreach ($roles as $role) {
            foreach ($role->bulletGroups as $index => $group) {
                $texts = array_map(fn (string $k) => (string) ($facts->get($k)['statement'] ?? ''), $group->careerFactKeys);
                $flat[] = [
                    'at' => "{$role->roleId}:{$index}", 'role' => $role->displayTitle, 'keys' => $group->careerFactKeys,
                    'project' => $this->project($group->careerFactKeys, $facts),
                    'dimensions' => array_values(array_unique(array_merge([], ...array_map(fn (string $t) => StoryDimensions::of($t), $texts)))),
                    'figures' => $this->figures(implode(' ', $texts)),
                    'ownership' => preg_match('/^\s*(?:independently\s+)?owned\b/i', $texts[0] ?? '') === 1,
                ];
            }
        }

        // Ownership is stated once, where it is strongest: the first bullet whose lead fact states it.
        $ownershipIn = collect($flat)->firstWhere('ownership', true)['at'] ?? null;
        $groups = [];

        foreach ($flat as $i => $g) {
            $others = array_values(array_filter($flat, fn (array $o) => $o['at'] !== $g['at']));
            $adjacent = array_values(array_filter([
                $i > 0 ? ['position' => 'before', ...$this->summary($flat[$i - 1])] : null,
                $i < count($flat) - 1 ? ['position' => 'after', ...$this->summary($flat[$i + 1])] : null,
            ]));
            $groups[$g['at']] = [
                'why_it_matters' => $this->purpose($g['keys'], $relevance),
                'demonstrates' => $g['dimensions'],
                'adjacent_bullets_establish' => $adjacent,
                'do_not_repeat' => array_values(array_filter([
                    ...array_map(fn (string $f) => "the result {$f} (stated in another bullet)", array_diff(array_unique(array_merge([], ...array_column($others, 'figures'))), $g['figures'])),
                    $ownershipIn !== null && $ownershipIn !== $g['at'] ? 'a claim of ownership (made in another bullet)' : null,
                ])),
            ];
        }

        return ['career_narrative' => $this->narrative($flat), 'ownership_in' => $ownershipIn, 'groups' => $groups];
    }

    /**
     * @param  array<int, string>  $keys
     * @param  array<string, mixed>  $relevance
     */
    private function purpose(array $keys, array $relevance): string
    {
        $ids = array_values(array_unique(array_merge([], ...array_map(fn (string $k) => $relevance['facts'][$k]['finding_ids'] ?? [], $keys))));
        $labels = array_values(array_filter(array_map(fn (int $id) => isset($relevance['findings'][$id]) ? str_replace('_', ' ', (string) $relevance['findings'][$id]['label']) : null, array_slice($ids, 0, 2))));

        return $labels === [] ? 'shows engineering depth the job values' : 'answers what the job asks for: '.implode('; ', $labels);
    }

    /**
     * @param  array<string, mixed>  $g
     * @return array{project: string|null, demonstrates: array<int, string>}
     */
    private function summary(array $g): array
    {
        return ['project' => $g['project'], 'demonstrates' => $g['dimensions']];
    }

    /**
     * @param  array<int, string>  $keys
     * @param  Collection<string, array<string, mixed>>  $facts
     */
    private function project(array $keys, Collection $facts): ?string
    {
        foreach ($keys as $key) {
            $name = trim(explode(':', (string) ($facts->get($key)['attribution']['project'] ?? ''))[0]);

            if ($name !== '') {
                return $name;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function figures(string $text): array
    {
        preg_match_all('/\$?\d[\d,]*(?:\.\d+)?\+?%?/', $text, $m);

        return array_values(array_unique(array_filter($m[0], fn (string $f) => ! preg_match('/^(?:19|20)\d{2}$/', $f) && (str_contains($f, '%') || strlen(preg_replace('/\D/', '', $f)) >= 2))));
    }

    /**
     * One line on how the career reads: each role with the projects and dimensions its bullets carry.
     *
     * @param  array<int, array<string, mixed>>  $flat
     */
    private function narrative(array $flat): string
    {
        $byRole = [];

        foreach ($flat as $g) {
            $byRole[$g['role']]['projects'][] = $g['project'];
            $byRole[$g['role']]['dimensions'] = array_unique([...($byRole[$g['role']]['dimensions'] ?? []), ...$g['dimensions']]);
        }

        $parts = [];

        foreach ($byRole as $role => $info) {
            $projects = array_values(array_unique(array_filter($info['projects'])));
            $parts[] = $role.($projects === [] ? '' : ' ('.implode(', ', $projects).')').': '.implode(', ', $info['dimensions']);
        }

        return implode(' | ', $parts);
    }
}
