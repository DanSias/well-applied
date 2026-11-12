<?php

namespace App\Support\ResumeRevision;

use App\Models\JobPosting;
use App\Models\ResumeVariant;
use App\Support\ProfileSynthesis\StoryDimensions;
use App\Support\ProfileSynthesis\StoryRole;

/**
 * Which approved accomplishments, if any, an underfilled resume should gain. It chooses from the same ranked, eligible,
 * not-yet-on-the-resume facts the editor's suggestions use (ReplacementFacts), and only ones a reader would want:
 *
 *   relevant      the job relevance, after specificity adjustments, reaches `min_relevance`; a general ownership or leadership
 *                 statement, a bare platform mention and (for an engineering job) a marketing-only result never qualify;
 *   shareable     Public evidence only;
 *   new           it does not restate a bullet already on the resume, and its project has room (`max_per_project`);
 *   distinct      among those, the ones that add a dimension of engineering the resume does not yet show (design, integration,
 *                 delivery, outcome ...), a project or employer it under-represents, or end an employer's stranded split rank higher.
 *
 * A project's facts join into one accomplishment (what was built, where it was deployed, a measured result), as in the first
 * draft. The number chosen is bounded by `max_additions` and by how many bullets the measured gap is worth, never by how
 * much room there looks to be. Deterministic; no model is called here.
 */
final class SelectLayoutAdditions
{
    public function __construct(private readonly ReplacementFacts $replacements) {}

    /**
     * @param  array<int, array{employer: string, title: string, bullets: int, by_page: array<int, int>}>|null  $placement
     * @return array{additions: array<int, array<string, mixed>>, considered: int, skipped: array<int, array{key: string, reason: string}>}
     */
    public function select(JobPosting $posting, ResumeVariant $variant, int $needed, ?array $placement = null): array
    {
        $cfg = (array) config('resume.finishing');
        $variant->loadMissing(['experienceRoles.bullets']);

        $texts = [];
        $perProject = [];
        $shown = [];

        foreach ($variant->experienceRoles as $role) {
            foreach ($role->bullets as $bullet) {
                $texts[] = $bullet->text;
                $shown = [...$shown, ...StoryDimensions::of($bullet->text)];

                if ($bullet->project_id !== null) {
                    $perProject[$bullet->project_id] = ($perProject[$bullet->project_id] ?? 0) + 1;
                }
            }
        }

        $shown = array_unique($shown);
        $strandedRoles = $this->strandedRoles($variant, $placement);
        $units = [];
        $skipped = [];
        $considered = 0;

        foreach ($variant->experienceRoles as $role) {
            $eligible = [];

            foreach ($this->replacements->candidates($posting, $variant, $role->role_id) as $row) {
                $considered++;
                $reason = match (true) {
                    $row['visibility'] !== 'public' => 'not Public evidence',
                    ! in_array($row['type'], ['bullet', 'metric'], true) => 'not an accomplishment',
                    $row['score'] < $cfg['min_relevance'] => 'not relevant enough to the job',
                    array_intersect($row['adjustments'], ['generic_leadership', 'generic_ownership', 'platform_tag', 'marketing_only']) !== [] => 'a general or incidental statement',
                    $this->restates($row['statement'], $texts, (float) $cfg['overlap_limit']) => 'already on the resume',
                    $row['project_id'] !== null && ($perProject[$row['project_id']] ?? 0) >= $cfg['max_per_project'] => 'its project is already well represented',
                    default => null,
                };

                if ($reason !== null) {
                    $skipped[] = ['key' => $row['key'], 'reason' => $reason];

                    continue;
                }

                $eligible[] = $row;
            }

            foreach ($this->stories($eligible) as $story) {
                $statements = array_column($story, 'statement');
                $dimensions = StoryDimensions::of(implode(' ', $statements));
                $new = array_values(array_diff($dimensions, $shown));
                $project = $story[0]['project_id'];
                $unrepresented = $project !== null && ($perProject[$project] ?? 0) === 0;
                $stranded = isset($strandedRoles[$role->role_id]);
                $best = max(0.0, ...array_column($story, 'score'));
                $score = $best * (1 + 0.1 * count($new)) * ($unrepresented ? 1.1 : 1.0) * ($stranded ? 1.05 : 1.0);
                $units[] = [
                    'role_id' => $role->role_id, 'role_title' => $role->display_title, 'fact_keys' => array_column($story, 'key'), 'statements' => $statements,
                    'project' => $story[0]['project'], 'project_id' => $project, 'relevance' => round($best, 2), 'score' => round($score, 3),
                    'adds' => $new, 'reasons' => array_values(array_filter([
                        'answers the job (relevance '.number_format($best, 2).')',
                        $new !== [] ? 'shows '.implode(' and ', $new).', which the resume does not yet show' : null,
                        $unrepresented ? 'adds a project the resume does not yet represent' : null,
                        $stranded ? 'grows the side of an employer split that has a single bullet stranded' : null,
                    ])),
                ];
            }
        }

        usort($units, fn (array $a, array $b) => $b['score'] <=> $a['score']);
        $limit = min((int) $cfg['max_additions'], max(1, $needed));
        $chosen = [];
        $perRole = [];
        $projects = [];

        foreach ($units as $unit) {
            if (count($chosen) >= $limit) {
                break;
            }

            if (($perRole[$unit['role_id']] ?? 0) >= $cfg['max_per_role'] || ($unit['project_id'] !== null && isset($projects[$unit['project_id']]))
                || $this->restates(implode(' ', $unit['statements']), array_merge(...array_map(fn (array $c) => $c['statements'], $chosen ?: [['statements' => []]])), (float) $cfg['overlap_limit'])) {
                continue;
            }

            $chosen[] = $unit;
            $perRole[$unit['role_id']] = ($perRole[$unit['role_id']] ?? 0) + 1;

            if ($unit['project_id'] !== null) {
                $projects[$unit['project_id']] = true;
            }
        }

        return ['additions' => $chosen, 'considered' => $considered, 'skipped' => $skipped];
    }

    /**
     * A project's facts as accomplishments: the build, with where it was deployed and one measured result; facts with no
     * project each stand alone.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function stories(array $rows): array
    {
        $byProject = [];
        $stories = [];

        foreach ($rows as $row) {
            if ($row['project_id'] === null) {
                $stories[] = [$row];
            } else {
                $byProject[$row['project_id']][] = $row;
            }
        }

        foreach ($byProject as $items) {
            usort($items, fn (array $a, array $b) => [$b['build'], $b['score']] <=> [$a['build'], $a['score']]);
            $lead = $items[0];
            $story = [$lead];

            foreach (array_slice($items, 1) as $item) {
                $isDeploy = StoryRole::isDeployment($item['statement']);
                $isOutcome = StoryRole::isOutcome($item['statement']);
                $has = fn (callable $test) => array_filter($story, fn (array $s) => $test($s['statement'])) !== [];

                if (count($story) < 3 && (($isDeploy && ! $has([StoryRole::class, 'isDeployment'])) || ($isOutcome && ! $has([StoryRole::class, 'isOutcome'])))) {
                    $story[] = $item;
                }
            }

            $stories[] = $story;
        }

        return $stories;
    }

    /**
     * Roles with a bullet stranded alone on one side of a page break (LayoutQuality's `role_split_awkward`), by role id.
     *
     * @param  array<int, array{employer: string, title: string, bullets: int, by_page: array<int, int>}>|null  $placement
     * @return array<int, true>
     */
    private function strandedRoles(ResumeVariant $variant, ?array $placement): array
    {
        $out = [];

        foreach ($placement ?? [] as $p) {
            $pages = array_filter($p['by_page']);

            if (count($pages) < 2 || min($pages) !== 1 || $p['bullets'] < 3) {
                continue;
            }

            foreach ($variant->experienceRoles as $role) {
                if ($role->employer_name === $p['employer'] && $role->display_title === $p['title']) {
                    $out[$role->role_id] = true;
                }
            }
        }

        return $out;
    }

    /**
     * @param  array<int, string>  $texts
     */
    private function restates(string $statement, array $texts, float $limit): bool
    {
        $words = fn (string $t) => array_unique(array_filter(preg_split('/[^a-z0-9]+/', strtolower($t)) ?: [], fn (string $w) => strlen($w) >= 4));
        $a = $words($statement);

        foreach ($texts as $text) {
            $b = $words($text);
            $union = count(array_unique([...$a, ...$b]));

            if ($union > 0 && count(array_intersect($a, $b)) / $union >= $limit) {
                return true;
            }
        }

        return false;
    }
}
