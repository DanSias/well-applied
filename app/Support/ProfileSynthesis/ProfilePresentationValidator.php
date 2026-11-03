<?php

namespace App\Support\ProfileSynthesis;

use App\Models\CareerProfile;
use App\Support\Clarification\ActiveLimitations;
use App\Support\Clarification\DeclaredScopes;
use App\Support\ResumeDocument\ResumeText;
use App\Support\ResumeProfile\CapabilityCoverage;
use App\Support\ResumeVariant\ClaimScopeChecker;
use App\Support\ResumeVariant\RestrictedEvidenceGuard;

/**
 * Judges the profile's presentation language (headline, summary, differentiators, project notes)
 * against the evidence inventory. Deterministic. Every sentence must cite eligible facts, say only
 * what they support, keep direct experience apart from transferable capability, keep personal
 * evidence off employers, respect confidentiality and confirmed limitations, and invent no figure.
 * Returns the problems that block approval and the review notes that do not.
 */
final class ProfilePresentationValidator
{
    private const YEARS = '/\b\d+\+?\s*(?:years?|yrs?)\b/i';

    private const FIRST_PERSON = "/(?<![\\w'])(?:I|I've|I'm|my|me)(?![\\w])/";

    /** Verbs that claim more ownership or authority than building or working on something does. */
    private const STRONG_VERBS = [
        'architected' => '/\barchitect(?:ed|ing)\b/i', 'led' => '/\b(?:led|leading|spearhead\w*|pioneer\w*)\b/i',
        'drove' => '/\b(?:drove|driving|directed|managed)\b/i', 'mentored' => '/\bmentor\w*\b/i',
    ];

    public function __construct(
        private readonly CapabilityCoverage $coverage,
        private readonly RestrictedEvidenceGuard $restricted,
        private readonly ClaimScopeChecker $scopes,
    ) {}

    /**
     * @param  array<string, mixed>  $presentation  headline, summary, summary_fact_keys, differentiators, project_notes
     * @param  array<string, mixed>  $inventory
     * @param  bool  $enforceCounts  false when judging the user's edited, possibly pruned presentation
     * @return array{content: array<string, mixed>, errors: array<int, string>, notes: array<int, string>}
     */
    public function validate(array $presentation, array $inventory, CareerProfile $career, bool $enforceCounts = true): array
    {
        $presentation = $this->clean($presentation);
        $errors = [];
        $notes = [];
        $facts = $inventory['facts'];
        $direct = array_column($inventory['capabilities']['direct'], 'key');
        $projectIds = array_column($inventory['projects'], 'project_id');
        $factText = array_map(fn (array $f) => trim($f['statement'].' '.($f['metric']['unit'] ?? '').' '.($f['metric']['value'] ?? '')), $facts);
        $restrictedKeys = array_keys(array_filter($facts, fn (array $f) => $f['visibility'] === 'restricted'));
        $declared = DeclaredScopes::forFacts($career, array_map('strval', array_keys($facts)));
        $limitationTerms = ActiveLimitations::terms($career);
        $employers = array_values(array_unique(array_filter(array_column($facts, 'employer'))));

        $check = function (string $where, string $text, array $keys) use (&$errors, &$notes, $facts, $direct, $factText, $restrictedKeys, $declared, $limitationTerms, $employers): void {
            $text = trim($text);

            if ($text === '') {
                $errors[] = "{$where}: is empty.";

                return;
            }

            if (preg_match(self::FIRST_PERSON, $text) === 1) {
                $errors[] = "{$where}: uses the first person.";
            }

            if (preg_match(self::YEARS, $text) === 1) {
                $errors[] = "{$where}: states a number of years, which no fact supports.";
            }

            if ($keys === []) {
                $errors[] = "{$where}: cites no facts.";

                return;
            }

            foreach ($keys as $key) {
                if (! isset($facts[$key])) {
                    $errors[] = "{$where}: cites [{$key}], which is not an eligible fact in the inventory.";
                }
            }

            $keys = array_values(array_filter($keys, fn (string $k) => isset($facts[$k])));

            if ($keys === []) {
                return;
            }

            $citedText = implode(' ', array_map(fn (string $k) => $factText[$k], $keys));
            $citedCaps = [];

            foreach ($keys as $k) {
                $citedCaps = [...$citedCaps, ...$this->coverage->capabilitiesIn($facts[$k]['statement'].' '.implode(', ', $facts[$k]['skills']))];
            }

            // Direct experience only: a capability the cited facts do not state, or one the career only relates to, is not claimed.
            foreach ($this->coverage->capabilitiesIn($text) as $capability) {
                $label = (string) config("resume_profiles.capabilities.{$capability}.label", $capability);

                if (! in_array($capability, $direct, true)) {
                    $errors[] = "{$where}: mentions {$label}, which is not direct experience in the record.";
                } elseif (! in_array($capability, $citedCaps, true)) {
                    $sources = array_slice(array_keys(array_filter($facts, fn (array $f) => in_array($capability, $f['capabilities'], true))), 0, 4);
                    $errors[] = "{$where}: mentions {$label}, which the cited facts do not state.".($sources === [] ? '' : ' Either cite one of ['.implode(', ', $sources).'] or remove the wording.');
                }
            }

            // A stronger ownership verb than the evidence uses ("architected" for "built") is an upgrade the facts do not make.
            foreach (self::STRONG_VERBS as $label => $pattern) {
                if (preg_match($pattern, $text) === 1 && preg_match($pattern, $citedText) !== 1) {
                    $errors[] = "{$where}: says \"{$label}\", which the cited facts do not; use the fact's own verb.";
                }
            }

            // Every figure must come from a cited fact.
            preg_match_all('/\d[\d,]*(?:\.\d+)?/', $text, $figures);

            foreach (array_unique($figures[0]) as $figure) {
                $normalized = str_replace(',', '', $figure);

                if (preg_match('/(?<![\d.])'.preg_quote($normalized, '/').'(?![\d])/', str_replace(',', '', $citedText)) !== 1) {
                    $errors[] = "{$where}: states the figure [{$figure}], which none of the cited facts contain.";
                }
            }

            // Employment attribution: an employer may be named only where a cited fact belongs to it.
            foreach ($employers as $employer) {
                if (stripos($text, $employer) === false && stripos($text, strtok($employer, ' ') ?: $employer) === false) {
                    continue;
                }

                $supported = array_filter($keys, fn (string $k) => $facts[$k]['employer'] === $employer || stripos($facts[$k]['statement'], strtok($employer, ' ') ?: $employer) !== false);

                if ($supported === []) {
                    $errors[] = "{$where}: names {$employer}, but no cited fact belongs to it.";
                }
            }

            foreach ($restrictedKeys !== [] ? $this->restricted->violations($text, $keys, $factText, $restrictedKeys, (array) config('resume.restricted_terms', [])) : [] as $violation) {
                $errors[] = "{$where}: {$violation}.";
            }

            foreach ($this->scopes->findings($text, $keys, array_intersect_key(array_map(fn (array $f) => $f['statement'], $facts), array_flip($keys)), $declared) as $finding) {
                if ($finding['severity'] === 'block') {
                    $errors[] = "{$where}: {$finding['message']}.";
                } else {
                    $notes[] = "{$where}: {$finding['message']}.";
                }
            }

            if ($limitationTerms !== [] && ActiveLimitations::matches($text, $limitationTerms)) {
                $errors[] = "{$where}: mentions something you confirmed you have no direct experience with.";
            }
        };

        $headline = trim((string) ($presentation['headline'] ?? ''));

        if ($headline === '' || str_word_count($headline) > 14 || mb_strlen($headline) > 100) {
            $errors[] = 'headline: must be a short title line of at most 12 words.';
        } elseif (preg_match(self::FIRST_PERSON, $headline) === 1 || preg_match('/\d/', $headline) === 1) {
            $errors[] = 'headline: must not use the first person or contain a number.';
        }

        $summary = (string) ($presentation['summary'] ?? '');

        if (mb_strlen($summary) < 200 || mb_strlen($summary) > 800) {
            $errors[] = 'summary: must be three or four sentences (200 to 800 characters).';
        }

        $check('summary', $summary, (array) ($presentation['summary_fact_keys'] ?? []));
        $differentiators = (array) ($presentation['differentiators'] ?? []);

        if ($enforceCounts && (count($differentiators) < 3 || count($differentiators) > 5)) {
            $errors[] = 'differentiators: provide three to five.';
        }

        foreach ($differentiators as $i => $d) {
            if (mb_strlen((string) ($d['statement'] ?? '')) > 320) {
                $errors[] = 'differentiator '.($i + 1).': is longer than one sentence.';
            }

            $check('differentiator '.($i + 1), (string) ($d['statement'] ?? ''), (array) ($d['fact_keys'] ?? []));
        }

        $seen = [];

        foreach ((array) ($presentation['project_notes'] ?? []) as $n) {
            $id = $n['project_id'] ?? null;

            if (! in_array($id, $projectIds, true) || isset($seen[$id])) {
                $errors[] = "project note: project [{$id}] is not a known project, or is repeated.";

                continue;
            }

            $seen[$id] = true;
            $check("project note {$id}", (string) ($n['note'] ?? ''), (array) ($n['fact_keys'] ?? []));
        }

        return ['content' => $presentation, 'errors' => array_values(array_unique($errors)), 'notes' => array_values(array_unique($notes))];
    }

    /**
     * @param  array<string, mixed>  $presentation
     * @return array<string, mixed>
     */
    private function clean(array $presentation): array
    {
        array_walk_recursive($presentation, function (mixed &$value, string|int $key) {
            if (is_string($value) && ! str_contains((string) $key, 'fact_key')) {
                $value = ResumeText::clean($value);
            }
        });

        return $presentation;
    }
}
