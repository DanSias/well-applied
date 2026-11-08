<?php

namespace App\Support\ReadinessReview;

use App\Exceptions\ReadinessReviewException;
use App\Support\ResumeProfile\CapabilityCoverage;
use App\Support\ResumeVariant\ClaimScopeChecker;

/**
 * Holds the model's coverage assessment to the resume as submitted. The structure is strict (a missing requirements list
 * is a failed review); a judgment the evidence does not carry is corrected, never trusted, and each correction is recorded
 * so it can be shown:
 *
 *  - strong or partial without a cited passage, or citing a passage that does not exist, becomes missing;
 *  - a cited passage that neither shares the requirement's vocabulary nor states a capability the requirement names
 *    does not support it: strong becomes partial, partial becomes missing (a similar-sounding passage is not evidence);
 *  - "built" must be borne out by the passages: if none says the candidate designed, built or owned something, the depth
 *    is lowered to supported (or adjacent when no action is stated);
 *  - a requirement that asks the candidate to build or own something is not strongly covered by supporting work alone;
 *  - recommendations that would have the candidate claim something they have confirmed they have no experience with are
 *    dropped.
 */
final class ValidateAssessment
{
    /** A job states what it wants done in the imperative or the gerund; both ask the candidate to build or own it. */
    private const ASKS_TO_BUILD = '/\b(?:own|owning|owned|build|building|design|designing|implement|implementing|develop|developing|architect|architecting|lead|leading)\b/i';

    private const STOP = ['with', 'that', 'this', 'from', 'have', 'will', 'your', 'their', 'work', 'working', 'experience', 'strong', 'ability', 'team', 'teams', 'across', 'using', 'build', 'building', 'years', 'role', 'including', 'such', 'other', 'into', 'plus', 'must', 'also', 'skills', 'required'];

    public function __construct(private readonly CapabilityCoverage $coverage, private readonly ClaimScopeChecker $scope) {}

    /**
     * @param  array<string, mixed>  $output
     * @param  array<string, array{id: string, label: string, text: string}>  $passages
     * @param  array<int, int>  $findingIds
     * @param  array<int, string>  $limitationTerms
     * @return array{assessment: array<string, mixed>, adjustments: array<int, string>}
     *
     * @throws ReadinessReviewException
     */
    public function validate(array $output, array $passages, array $findingIds, array $limitationTerms = []): array
    {
        $requirements = $output['requirements'] ?? null;

        if (! is_array($requirements) || $requirements === []) {
            throw new ReadinessReviewException('The review did not list any job requirements.');
        }

        $adjustments = [];
        $seen = [];
        $clean = [];

        foreach ($requirements as $r) {
            $id = (string) ($r['id'] ?? '');

            if ($id === '' || isset($seen[$id]) || trim((string) ($r['text'] ?? '')) === '') {
                continue;
            }

            $seen[$id] = true;
            $coverage = in_array($r['coverage'] ?? null, ['strong', 'partial', 'missing'], true) ? $r['coverage'] : 'missing';
            $depth = in_array($r['depth'] ?? null, ['built', 'supported', 'adjacent', 'none'], true) ? $r['depth'] : 'none';
            $cited = array_values(array_unique(array_filter((array) ($r['passage_ids'] ?? []), fn ($p) => is_string($p) && isset($passages[$p]))));
            $findings = array_values(array_filter(array_map('intval', (array) ($r['finding_ids'] ?? [])), fn (int $f) => in_array($f, $findingIds, true)));
            $text = trim((string) $r['text']);

            if ($coverage === 'missing') {
                $cited = [];
                $depth = 'none';
            } elseif ($cited === []) {
                $adjustments[] = "{$id}: judged {$coverage} but cited no resume passage, so it is treated as missing.";
                $coverage = 'missing';
                $depth = 'none';
            } elseif (! $this->supported($text, $cited, $passages)) {
                $adjustments[] = "{$id}: the cited passage does not state what the requirement asks (no shared vocabulary or capability), so coverage was lowered.";
                $coverage = $coverage === 'strong' ? 'partial' : 'missing';

                if ($coverage === 'missing') {
                    $cited = [];
                    $depth = 'none';
                }
            }

            if ($depth === 'built' && $cited !== []) {
                $classes = array_merge([], ...array_map(fn (string $p) => $this->scope->actionClasses($passages[$p]['text']), $cited));

                if (array_intersect($classes, ['implementation', 'ownership']) === []) {
                    $depth = $classes === [] ? 'adjacent' : 'supported';
                    $adjustments[] = "{$id}: no cited passage says the candidate built or owned it, so the depth is {$depth}, not built.";
                }
            }

            // A technology the candidate has confirmed no direct experience with is not covered by a comparable one.
            $named = array_values(array_filter($limitationTerms, fn (string $term) => $term !== '' && preg_match('/(?<![\w])'.preg_quote($term, '/').'(?![\w])/iu', $text) === 1));

            if ($named !== [] && $coverage !== 'missing') {
                $coverage = 'partial';
                $depth = in_array($depth, ['built', 'supported'], true) ? 'adjacent' : $depth;
                $adjustments[] = "{$id}: names ".implode(', ', $named).', which you have confirmed you have no direct experience with. Comparable experience is related, not this, so it is partial at most.';
            }

            $asksToBuild = array_intersect($this->scope->actionClasses($text), ['implementation', 'ownership']) !== [] || preg_match(self::ASKS_TO_BUILD, $text) === 1;

            if ($coverage === 'strong' && $asksToBuild && $depth !== 'built') {
                $coverage = 'partial';
                $adjustments[] = "{$id}: the job asks the candidate to build or own this, and the resume shows {$depth} work only, so it is partial.";
            }

            $clean[] = [
                'id' => $id, 'text' => $text, 'priority' => in_array($r['priority'] ?? null, ['critical', 'important', 'nice'], true) ? $r['priority'] : 'important',
                'finding_ids' => $findings, 'coverage' => $coverage, 'depth' => $depth, 'passage_ids' => $cited, 'note' => trim((string) ($r['note'] ?? '')),
            ];
        }

        if ($clean === []) {
            throw new ReadinessReviewException('The review did not list any usable job requirements.');
        }

        $recommendations = [];

        foreach ((array) ($output['recommendations'] ?? []) as $rec) {
            $text = trim(($rec['title'] ?? '').' '.($rec['advice'] ?? ''));
            $banned = array_filter($limitationTerms, fn (string $term) => $term !== '' && preg_match('/(?<![\w])'.preg_quote($term, '/').'(?![\w])/iu', $text) === 1);

            if ($banned !== []) {
                $adjustments[] = 'A recommendation was dropped because it mentions '.implode(', ', $banned).', which you have confirmed you have no direct experience with.';

                continue;
            }

            $target = (string) ($rec['target'] ?? 'none');
            $recommendations[] = [
                'title' => trim((string) ($rec['title'] ?? '')), 'advice' => trim((string) ($rec['advice'] ?? '')),
                'target' => $target === 'none' || isset($passages[$target]) ? $target : 'none',
                'requirement_ids' => array_values(array_filter((array) ($rec['requirement_ids'] ?? []), fn ($i) => isset($seen[$i]))),
            ];
        }

        $argument = (array) ($output['hiring_argument'] ?? []);
        $emphasis = (array) ($output['emphasis'] ?? []);

        return [
            'assessment' => [
                'requirements' => $clean,
                'hiring_argument' => [
                    'rating' => in_array($argument['rating'] ?? null, ['persuasive', 'adequate', 'weak'], true) ? $argument['rating'] : 'adequate',
                    'summary' => trim((string) ($argument['summary'] ?? '')),
                ],
                'emphasis' => ['over' => array_values(array_filter((array) ($emphasis['over'] ?? []), 'is_string')), 'under' => array_values(array_filter((array) ($emphasis['under'] ?? []), 'is_string'))],
                'recommendations' => $recommendations,
            ],
            'adjustments' => $adjustments,
        ];
    }

    /**
     * @param  array<int, string>  $cited
     * @param  array<string, array{id: string, label: string, text: string}>  $passages
     */
    private function supported(string $requirement, array $cited, array $passages): bool
    {
        $words = fn (string $t): array => array_values(array_unique(array_filter(preg_split('/[^a-z0-9]+/', mb_strtolower($t)) ?: [], fn (string $w) => strlen($w) >= 4 && ! in_array($w, self::STOP, true))));
        $need = $words($requirement);
        $needCaps = $this->coverage->capabilitiesIn($requirement);

        foreach ($cited as $id) {
            $text = $passages[$id]['text'];

            if (array_intersect($need, $words($text)) !== [] || ($needCaps !== [] && array_intersect($needCaps, $this->coverage->capabilitiesIn($text)) !== [])) {
                return true;
            }
        }

        return false;
    }
}
