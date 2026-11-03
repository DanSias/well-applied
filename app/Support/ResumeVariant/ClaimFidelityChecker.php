<?php

namespace App\Support\ResumeVariant;

/**
 * Checks the MATERIAL claims of one generated bullet or summary against the combined evidence it is allowed to
 * draw on (the facts cited at that location, read together), the way a careful reviewer would. Ordinary words
 * such as "through", "by" or "enabling" are never a problem in themselves; a problem is a specific claim the
 * evidence does not make:
 *
 *  - scope_expansion    a breadth the evidence never states ("across managed client engagements").
 *  - unsupported_cause  a result attributed to something the evidence does not connect to it ("reduced onboarding
 *                       effort ... through improved documentation").
 *  - ownership_upgrade  an authority verb stronger than any the evidence uses ("designed", "led", "drove").
 *  - figure_precision   a figure whose qualifier or sign differs from the evidence ("approximately 20+" restated as
 *                       "20+", or "over 3" for "3"), and a figure no cited fact contains.
 *
 * Every finding carries the exact span and, where one exists, a deterministic repair that only REMOVES the
 * unsupported claim or RESTORES the evidence's own wording; it never adds a claim. Deterministic; no model call.
 */
final class ClaimFidelityChecker
{
    /** @var array<int, string> kinds that RecoverWording repairs or omits */
    public const KINDS = ['scope_expansion', 'unsupported_cause', 'ownership_upgrade', 'figure_precision'];

    private const STOP = ['which', 'their', 'there', 'these', 'those', 'other', 'while', 'where', 'would', 'about', 'after', 'being', 'every', 'using', 'across', 'through', 'within', 'between', 'during', 'those', 'could', 'should'];

    /** Breadth words: claiming one that the evidence lacks widens the scope of the work. */
    private const BREADTH = '(?:all|every|multiple|several|numerous|various|many|managed|major|diverse|dozens|hundreds|client|clients|customer|customers|enterprise|global|company-wide|organization-wide|portfolio)';

    private const RESULT = '/\b(?:reduc\w*|cut(?:ting)?|sav(?:ed|ing|es)|increas\w*|improv\w*|grew|growth|boost\w*|accelerat\w*|lower\w*|eliminat\w*|deliver\w*|drove|driving|achiev\w*)\b/i';

    /** Authority verb groups: the text may use one only if the evidence uses a verb of the same group. */
    private const AUTHORITY = [
        'design' => ['text' => '/\b(?:design(?:ed|ing)?|architect(?:ed|ing)?|engineer(?:ed|ing)|devis(?:ed|ing))\b/i', 'evidence' => '/\b(?:design\w*|architect\w*|engineer\w*|devis\w*|defin\w*|decid\w*|chose|choos\w*|shap\w*|decision\w*)\b/i'],
        'lead' => ['text' => '/\b(?:led|leading|spearhead\w*|pioneer\w*|direct(?:ed|ing)|drove|driving|champion\w*|oversaw|managed|managing)\b/i', 'evidence' => '/\b(?:led|leading|leadership|spearhead\w*|pioneer\w*|direct(?:ed|ing)|drove|driv\w*|champion\w*|oversaw|manag\w*)\b/i'],
        'own' => ['text' => '/\b(?:owned|owning|owns|founded)\b/i', 'evidence' => '/\b(?:own\w*|found(?:ed|er)|independent\w*|sole)\b/i'],
    ];

    private const FIGURE = '/(?:(?<q>approximately|about|roughly|nearly|around|over|under|more than|up to)\s+)?(?<f>\$?\d[\d,]*(?:\.\d+)?(?:\s*[–-]\s*\$?\d[\d,]*(?:\.\d+)?)?\+?%?)/iu';

    /**
     * @param  array<int, string>  $citedKeys
     * @param  array<string, string>  $factTextByKey  fact key => evidence-bearing text
     * @return array<int, array<string, mixed>> each with kind, severity, detail, message and, where one exists, repair
     */
    public function findings(string $text, array $citedKeys, array $factTextByKey): array
    {
        $evidence = trim(implode(' ', array_map(fn (string $k) => $factTextByKey[$k] ?? '', $citedKeys)));

        if ($evidence === '' || trim($text) === '') {
            return [];
        }

        return [...$this->scope($text, $evidence), ...$this->cause($text, $evidence), ...$this->authority($text, $evidence), ...$this->figures($text, $evidence)];
    }

    /**
     * Applies one finding's repair. Null when there is none, or the repair would leave a fragment.
     *
     * @param  array<string, mixed>  $finding
     */
    public function repair(string $text, array $finding): ?string
    {
        $repair = $finding['repair'] ?? null;

        if ($repair === null) {
            return null;
        }

        $next = match ($repair['op']) {
            'remove' => str_replace($repair['span'], '', $text),
            'replace' => str_replace($repair['from'], $repair['to'], $text),
            default => $text,
        };

        $next = trim((string) preg_replace('/\s{2,}/u', ' ', (string) preg_replace('/\s+([,.;])/u', '$1', $next)));
        $next = rtrim($next, ' ,;');

        if ($next === $text || str_word_count($next) < 4) {
            return null;
        }

        if (! preg_match('/[.!?]$/u', $next) && preg_match('/[.!?]$/u', $text) === 1) {
            $next .= '.';
        }

        return mb_strtoupper(mb_substr($next, 0, 1)).mb_substr($next, 1);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function scope(string $text, string $evidence): array
    {
        $findings = [];
        $breadth = self::BREADTH;
        $pattern = "/\\s*\\b(?:across|throughout|for)\\s+((?:(?:the|{$breadth})\\s+)*(?:{$breadth})(?:\\s+[\\p{L}-]+){0,2})/iu";

        if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER) < 1) {
            return [];
        }

        foreach ($matches as $m) {
            $span = $m[0];
            $words = $this->significant($m[1]);

            // Supported only if the evidence says it too: every significant word of the phrase is in the evidence.
            $missing = array_values(array_filter($words, fn (string $w) => ! $this->mentions($evidence, $w)));

            if ($missing !== []) {
                $findings[] = [
                    'kind' => 'scope_expansion', 'severity' => 'block', 'detail' => trim($span),
                    'message' => 'widens the scope to "'.trim($m[1]).'", which the cited evidence does not state',
                    'repair' => ['op' => 'remove', 'span' => $span],
                ];
            }
        }

        return $findings;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function cause(string $text, string $evidence): array
    {
        $findings = [];

        // The result vocabulary must come BEFORE the connector: the connector then says what produced the result.
        if (preg_match_all('/\s*\b(through|via|thanks to|resulting from|driven by|enabled by|due to|by)\s+([^,.;]+)/iu', $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) < 1) {
            return [];
        }

        foreach ($matches as $m) {
            $offset = $m[0][1];
            $object = $m[2][0];

            if (preg_match(self::RESULT, substr($text, 0, $offset)) !== 1 && preg_match('/\d/', substr($text, 0, $offset)) !== 1) {
                continue;
            }

            // "by 85%" is a measure, not a cause.
            if (preg_match('/^\s*[\$\d]/', $object) === 1) {
                continue;
            }

            $words = $this->significant($object);

            if ($words === []) {
                continue;
            }

            $absent = array_values(array_filter($words, fn (string $w) => ! $this->mentions($evidence, $w)));

            // A cause is unsupported when most of what it names is absent from the combined evidence.
            if (count($absent) >= 2 || (count($absent) === 1 && count($words) <= 2)) {
                $findings[] = [
                    'kind' => 'unsupported_cause', 'severity' => 'block', 'detail' => trim($m[0][0]),
                    'message' => 'attributes the result to "'.trim($object).'", which the cited evidence does not connect to it',
                    'repair' => ['op' => 'remove', 'span' => $m[0][0]],
                ];
            }
        }

        return $findings;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function authority(string $text, string $evidence): array
    {
        $findings = [];

        // Only the verbs that state the contribution count: the first word, and the one after "and" ("Designed and
        // implemented"). A word like "managed" inside "managed client engagements" describes something else.
        $opening = preg_match('/^\s*([\p{L}-]+)(?:\s+and\s+([\p{L}-]+))?/u', $text, $words) === 1 ? implode(' ', array_slice($words, 1)) : '';

        foreach (self::AUTHORITY as $group => $patterns) {
            if (preg_match($patterns['text'], $opening, $hit) !== 1 || preg_match($patterns['evidence'], $evidence) === 1) {
                continue;
            }

            $verb = $hit[0];
            $repair = null;

            // "Designed and implemented X" becomes "Implemented X": drop the unsupported verb and keep the supported one.
            if (preg_match('/^'.preg_quote($verb, '/').'\s+and\s+/iu', $text) === 1) {
                $repair = ['op' => 'remove', 'span' => $verb.' and '];
            } elseif (preg_match('/^'.preg_quote($verb, '/').'\b/iu', $text) === 1 && ($lead = $this->leadVerb($evidence)) !== null) {
                // A bare unsupported lead verb takes the evidence's own: "Drove" becomes "Delivered".
                $repair = ['op' => 'replace', 'from' => $verb, 'to' => $lead];
            }

            $findings[] = [
                'kind' => 'ownership_upgrade', 'severity' => 'block', 'detail' => $verb,
                'message' => "claims \"{$verb}\", a stronger role than the cited evidence states",
                ...($repair === null ? [] : ['repair' => $repair]),
            ];
        }

        return $findings;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function figures(string $text, string $evidence): array
    {
        $findings = [];
        preg_match_all(self::FIGURE, $evidence, $ev, PREG_SET_ORDER);
        $byCore = [];

        foreach ($ev as $e) {
            $byCore[$this->core($e['f'])][] = ['q' => strtolower($e['q']), 'f' => $e['f']];
        }

        if (preg_match_all(self::FIGURE, $text, $tx, PREG_SET_ORDER) < 1) {
            return [];
        }

        foreach ($tx as $t) {
            $core = $this->core($t['f']);

            // A bare small number ("a 3-step process", "2 pages") is not a measured claim.
            if (! preg_match('/[%$+,.–-]/u', $t['f']) && (int) $core < 10) {
                continue;
            }

            if (! isset($byCore[$core])) {
                $findings[] = ['kind' => 'figure_precision', 'severity' => 'block', 'detail' => $t['f'], 'message' => "states the figure [{$t['f']}], which none of the cited facts contain"];

                continue;
            }

            $variants = $byCore[$core];
            $q = strtolower($t['q']);

            // A figure also repeated bare (metric metadata) is stated as its qualified form; evidence that qualifies it
            // in conflicting ways is ambiguous, so it is left alone.
            $qualified = array_values(array_filter($variants, fn (array $v) => $v['q'] !== '' || str_ends_with($v['f'], '+')));
            $variants = $qualified === [] ? $variants : $qualified;

            if (count(array_unique(array_map(fn (array $v) => $v['q'].'|'.$v['f'], $variants))) > 1) {
                continue;
            }

            $want = $variants[0];

            if ($want['q'] === $q && $want['f'] === $t['f']) {
                continue;
            }

            $have = trim(($t['q']).' '.$t['f']);
            $should = trim($want['q'].' '.$want['f']);
            $findings[] = [
                'kind' => 'figure_precision', 'severity' => 'block', 'detail' => $have,
                'message' => "states \"{$have}\", but the evidence says \"{$should}\"",
                'repair' => ['op' => 'replace', 'from' => $have, 'to' => $should],
            ];
        }

        return $findings;
    }

    private function core(string $figure): string
    {
        return (string) preg_replace('/[^\d.–-]/u', '', str_replace(',', '', $figure));
    }

    /**
     * @return array<int, string>
     */
    private function significant(string $phrase): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($phrase)) ?: [];

        return array_values(array_filter($words, fn (string $w) => mb_strlen($w) >= 5 && ! in_array($w, self::STOP, true)));
    }

    /**
     * Whether the evidence contains the word (matched on its first five letters, so "engagements" finds "engagement").
     */
    private function mentions(string $evidence, string $word): bool
    {
        $stem = mb_substr($word, 0, min(mb_strlen($word), 6));

        return preg_match('/\b'.preg_quote($stem, '/').'/iu', $evidence) === 1;
    }

    private function leadVerb(string $evidence): ?string
    {
        // The evidence's own opening verb: regular past tense, or one of the few irregular ones resumes use.
        return preg_match('/^\s*((?:[A-Z][a-z]+ed|Built|Led|Ran|Wrote|Made|Cut|Grew|Chose|Set))\b/', $evidence, $m) === 1 ? $m[1] : null;
    }
}
