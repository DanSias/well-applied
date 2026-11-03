<?php

namespace App\Support\ResumeVariant;

/**
 * Deterministic check that generated text citing `Restricted` evidence
 * stays a generalized claim. For one location (summary, bullet, or
 * project highlight) with its cited CareerFact keys, it reports:
 *
 *  - any configured confidential term (config/resume.php
 *    `restricted_terms`), e.g. a merchant or client name;
 *  - any hostname-like token;
 *  - any numeric figure that appears in a cited Restricted fact but in
 *    no cited non-Restricted fact (a restricted-only number may never
 *    be published).
 *
 * Generic industry terms (config `generic_terms`, e.g. "merchant") are
 * not confidential on their own: they produce a `review` finding, not a
 * block. Locations that cite no Restricted fact are never checked, so
 * the same word is allowed where only Public evidence supports it.
 * Private facts never reach generation at all (ResumeEligibility).
 */
final class RestrictedEvidenceGuard
{
    private const HOSTNAME_PATTERN = '/\b[a-z0-9][a-z0-9-]*(?:\.[a-z0-9-]+)*\.(?:com|net|io|org|dev|ai|app)\b/i';

    private const FIGURE_PATTERN = '/\$?\d[\d,]*(?:\.\d+)?/';

    /**
     * Human-readable BLOCK violations only (confidential terms,
     * hostnames, restricted-only figures); empty when the text is
     * acceptable. Generic industry terms are review findings, see
     * findings().
     *
     * @param  array<int, string>  $citedFactKeys
     * @param  array<string, string>  $factTextByKey  career_fact_key => evidence-bearing text (statement, scope note, guardrail) for every eligible fact.
     * @param  array<int, string>  $restrictedFactKeys  Keys of eligible facts whose visibility is Restricted.
     * @param  array<int, string>  $forbiddenTerms
     * @return array<int, string>
     */
    public function violations(
        string $text,
        array $citedFactKeys,
        array $factTextByKey,
        array $restrictedFactKeys,
        array $forbiddenTerms,
    ): array {
        return array_values(array_map(
            fn (array $finding) => $finding['message'],
            array_filter(
                $this->findings($text, $citedFactKeys, $factTextByKey, $restrictedFactKeys, $forbiddenTerms),
                fn (array $finding) => $finding['severity'] === 'block',
            ),
        ));
    }

    /**
     * Structured findings for one location. `severity` is `block` for a
     * confidential term, hostname or restricted-only figure, and
     * `review` for a generic industry term used where Restricted
     * evidence is cited. `detail` is the term, host or figure.
     *
     * @param  array<int, string>  $citedFactKeys
     * @param  array<string, string>  $factTextByKey
     * @param  array<int, string>  $restrictedFactKeys
     * @param  array<int, string>  $forbiddenTerms
     * @param  array<int, string>|null  $genericTerms  null reads config('resume.generic_terms').
     * @return array<int, array{kind: string, severity: string, detail: string, message: string}>
     */
    public function findings(
        string $text,
        array $citedFactKeys,
        array $factTextByKey,
        array $restrictedFactKeys,
        array $forbiddenTerms,
        ?array $genericTerms = null,
    ): array {
        $citedRestricted = array_values(array_intersect($citedFactKeys, $restrictedFactKeys));

        if ($citedRestricted === []) {
            return [];
        }

        $genericTerms ??= (array) config('resume.generic_terms', []);
        $findings = [];

        $publicText = implode(' ', array_map(
            fn (string $key) => $factTextByKey[$key] ?? '',
            array_diff($citedFactKeys, $restrictedFactKeys),
        ));

        // A term (or hostname) that a cited non-Restricted fact itself
        // states is public by that fact's own visibility, so it is not
        // Restricted-only detail.
        foreach ($forbiddenTerms as $term) {
            if ($this->mentions($text, $term) && ! $this->mentions($publicText, $term)) {
                $findings[] = ['kind' => 'confidential_term', 'severity' => 'block', 'detail' => $term, 'message' => "mentions the confidential term [{$term}]"];
            }
        }

        foreach ($genericTerms as $term) {
            if (in_array(mb_strtolower($term), array_map('mb_strtolower', $forbiddenTerms), true)) {
                continue;
            }

            if ($this->mentions($text, $term) && ! $this->mentions($publicText, $term)) {
                $findings[] = ['kind' => 'generic_term', 'severity' => 'review', 'detail' => $term, 'message' => "uses the industry term [{$term}] on a claim supported by Restricted evidence"];
            }
        }

        if (preg_match(self::HOSTNAME_PATTERN, $text, $host) === 1) {
            $findings[] = ['kind' => 'hostname', 'severity' => 'block', 'detail' => $host[0], 'message' => "mentions a hostname [{$host[0]}]"];
        }

        $publicFigures = $this->figures($publicText);

        $restrictedFigures = [];
        foreach ($citedRestricted as $key) {
            $restrictedFigures = array_merge($restrictedFigures, $this->figures($factTextByKey[$key] ?? ''));
        }

        $textFigures = $this->figures($text);

        foreach (array_unique($restrictedFigures) as $figure) {
            if (in_array($figure, $textFigures, true) && ! in_array($figure, $publicFigures, true)) {
                $findings[] = ['kind' => 'restricted_figure', 'severity' => 'block', 'detail' => $figure, 'message' => "states the figure [{$figure}], which appears only in Restricted evidence"];
            }
        }

        return $findings;
    }

    private function mentions(string $text, string $term): bool
    {
        return preg_match('/(?<![\p{L}\p{N}])'.preg_quote($term, '/').'(?![\p{L}\p{N}])/iu', $text) === 1;
    }

    /**
     * The figure's text as written in $text, for deterministic removal.
     *
     * @return array<int, string>
     */
    public function figureTokens(string $text, string $figure): array
    {
        preg_match_all(self::FIGURE_PATTERN, $text, $matches);

        return array_values(array_filter(
            $matches[0],
            fn (string $token) => rtrim(str_replace([',', '$'], '', $token), '.') === $figure,
        ));
    }

    /**
     * @return array<int, string> Normalized numerals ("32,000" and "32000" compare equal).
     */
    private function figures(string $text): array
    {
        preg_match_all(self::FIGURE_PATTERN, $text, $matches);

        return array_values(array_unique(array_filter(array_map(
            fn (string $m) => rtrim(str_replace([',', '$'], '', $m), '.'),
            $matches[0],
        ), fn (string $m) => strlen($m) >= 2)));
    }
}
