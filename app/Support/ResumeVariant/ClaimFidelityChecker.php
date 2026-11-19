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
 *  - unsupported_qualifier  an adjective that asserts something about the work the evidence does not ("a custom frontend"
 *                       for "the frontend experience"). Only the intensifiers in config('resume.claim_qualifiers') are
 *                       checked, and one the evidence uses (or an equivalent) is always allowed.
 *  - lifecycle_upgrade  a stage of the delivery lifecycle the evidence stops short of ("through testing to production" for
 *                       "testing and delivery"). Delivery and deployment are not stages beyond each other; production,
 *                       launch, maintenance and operation are, and are matched as whole words, never by a shared stem
 *                       ("production" is not supported by "product design").
 *
 *  - team_claim        supervising or leading people ("led a team of developers", "direct reports") when the evidence states only
 *                       managing projects or coordinating clients. Managing a project is not managing people; a claim of the
 *                       second kind needs evidence of the second kind.
 *
 * Every finding carries the exact span and, where one exists, a deterministic repair that only REMOVES the
 * unsupported claim or RESTORES the evidence's own wording; it never adds a claim. Deterministic; no model call.
 */
final class ClaimFidelityChecker
{
    /** @var array<int, string> kinds that RecoverWording repairs or omits */
    public const KINDS = ['scope_expansion', 'unsupported_cause', 'ownership_upgrade', 'figure_precision', 'unsupported_qualifier', 'lifecycle_upgrade', 'team_claim'];

    private const STOP = ['which', 'their', 'there', 'these', 'those', 'other', 'while', 'where', 'would', 'about', 'after', 'being', 'every', 'using', 'across', 'through', 'within', 'between', 'during', 'those', 'could', 'should'];

    /** Breadth words: claiming one that the evidence lacks widens the scope of the work. */
    private const BREADTH = '(?:all|every|multiple|several|numerous|various|many|managed|major|diverse|dozens|hundreds|client|clients|customer|customers|enterprise|global|company-wide|organization-wide|portfolio)';

    private const RESULT = '/\b(?:reduc\w*|cut(?:ting)?|sav(?:ed|ing|es)|increas\w*|improv\w*|grew|growth|boost\w*|accelerat\w*|lower\w*|eliminat\w*|deliver\w*|drove|driving|achiev\w*)\b/i';

    /** Authority verb groups: the text may use one only if the evidence uses a verb of the same group. */
    public const AUTHORITY = [
        'design' => ['text' => '/\b(?:design(?:ed|ing)?|architect(?:ed|ing)?|engineer(?:ed|ing)|devis(?:ed|ing))\b/i', 'evidence' => '/\b(?:design\w*|architect\w*|engineer\w*|devis\w*|defin\w*|decid\w*|chose|choos\w*|shap\w*|decision\w*)\b/i'],
        'lead' => ['text' => '/\b(?:led|leading|spearhead\w*|pioneer\w*|direct(?:ed|ing)|drove|driving|champion\w*|oversaw|managed|managing)\b/i', 'evidence' => '/\b(?:led|leading|leadership|spearhead\w*|pioneer\w*|direct(?:ed|ing)|drove|driv\w*|champion\w*|oversaw|manag\w*)\b/i'],
        'own' => ['text' => '/\b(?:owned|owning|owns|founded)\b/i', 'evidence' => '/\b(?:own\w*|found(?:ed|er)|independent\w*|sole)\b/i'],
    ];

    /**
     * Leading, supervising or managing people: a lead verb with a group of people as its object ("led a distributed team",
     * "managed engineers"), a team of, direct reports, supervision, or a team lead. "Managed client projects", "coordinated with
     * client teams" and "the team's migration" are not people management.
     */
    private const PEOPLE = '/\b(?:(?:led|leading|lead|managed|managing|manage|oversaw|overseeing|headed|supervis\w+|(?:directed|directing)(?=[^.;]*?\b(?:team|teams|developers|engineers|staff|employees|contractors|designers|analysts|reports)\b(?!\s+to\b)))\s+(?:(?:a|an|the|our|my|cross-functional|distributed|small|large|engineering|development|technical|remote|\d+)\s+){0,3}(?:team|teams|developers|engineers|staff|employees|contractors|designers|analysts|reports)\b(?![\'\x{2019}]s)|team of\b|direct reports?|supervis(?:ed|ing|ion|or)\b|people management|team lead(?:er|ership)?\b)/iu';

    private const FIGURE = '/(?:(?<q>approximately|about|roughly|nearly|around|over|under|more than|up to)\s+)?(?<f>\$?\d[\d,]*(?:\.\d+)?(?:(?:\s*[–-]\s*|\s+to\s+)\$?\d[\d,]*(?:\.\d+)?)?\+?%?)/iu';

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

        return [...$this->scope($text, $evidence), ...$this->cause($text, $evidence), ...$this->authority($text, $evidence, $this->evidenceByFact($citedKeys, $factTextByKey)), ...$this->figures($text, $evidence), ...$this->qualifiers($text, $evidence), ...$this->lifecycle($text, $evidence), ...$this->people($text, $evidence)];
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
            'qualifier' => $this->withoutQualifier($text, $repair['word']),
            'production' => $this->withoutProduction($text) ?? $text,
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

            // "by 85%" is a measure, not a cause. A cause can still follow it: "by 85% by adding caching".
            if (preg_match('/^\s*[\$\d]/', $object) === 1) {
                if (preg_match('/^\s*[\$\d][\d,.]*%?\+?\s+(?:through|via|thanks to|resulting from|driven by|enabled by|due to|by)\s+(.+)$/iu', $object, $nested) !== 1) {
                    continue;
                }

                $object = $nested[1];
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
     * @param  array<int, string>  $facts  each cited fact's own text, in cited order
     * @return array<int, array<string, mixed>>
     */
    private function authority(string $text, string $evidence, array $facts): array
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
            } elseif (preg_match('/^'.preg_quote($verb, '/').'\b/iu', $text) === 1 && ($lead = $this->replacementVerb($text, $verb, $facts)) !== null) {
                // A bare unsupported lead verb takes the verb of the fact that describes this very work: "Drove" becomes "Delivered".
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
        $ev = $this->figureMatches($evidence);
        $byCore = [];

        foreach ($ev as $e) {
            $byCore[$this->core($e['f'])][] = ['q' => strtolower($e['q']), 'f' => $e['f']];
        }

        $tx = $this->figureMatches($text);

        if ($tx === []) {
            return [];
        }

        foreach ($tx as $t) {
            $core = $this->core($t['f']);

            // A bare small number ("a 3-step process", "2 pages") is not a measured claim.
            if (! preg_match('/[%$+,.–-]/u', $t['f']) && (int) $core < 10) {
                continue;
            }

            // A range the evidence states as its two ends ("10" and "15") is the same range.
            if (! isset($byCore[$core]) && str_contains($core, '–') && $this->rangeEndsStated($core, $byCore)) {
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

            // "10–15", "10-15" and "10 to 15" are one figure; only the numbers and the qualifier are compared.
            if ($want['q'] === $q && $this->range($want['f']) === $this->range($t['f'])) {
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

    /**
     * @return array<int, array<string, mixed>>
     */
    private function qualifiers(string $text, string $evidence): array
    {
        $findings = [];

        foreach ((array) config('resume.claim_qualifiers') as $group) {
            $used = $this->firstWord($text, $group);

            if ($used === null || $this->firstWord($evidence, $group) !== null) {
                continue;
            }

            $findings[] = [
                'kind' => 'unsupported_qualifier', 'severity' => 'block', 'detail' => $used,
                'message' => "calls the work \"{$used}\", which the cited evidence does not say",
                'repair' => ['op' => 'qualifier', 'word' => $used],
            ];
        }

        return $findings;
    }

    /**
     * A claim of leading or supervising people that no cited fact makes. There is no repair: removing the phrase would leave a
     * different claim, so the location is omitted (RecoverWording) rather than rewritten.
     *
     * @return array<int, array<string, mixed>>
     */
    private function people(string $text, string $evidence): array
    {
        if (preg_match(self::PEOPLE, $text, $hit) !== 1 || preg_match(self::PEOPLE, $evidence) === 1) {
            return [];
        }

        return [[
            'kind' => 'team_claim', 'severity' => 'block', 'detail' => trim($hit[0]),
            'message' => 'claims leading or supervising people ("'.trim($hit[0]).'"), which the cited evidence does not state; it states managing projects and working with clients',
        ]];
    }

    /**
     * The delivery lifecycle runs requirements, design, implementation, testing, delivery (or deployment, which are one step
     * here), then production, launch, maintenance and operation. Text that reaches a later stage than the evidence is an
     * upgrade: "through testing to production" for "testing and delivery". Production is removed (the repair takes only that
     * phrase); the others are flagged for a person, since removing them could leave a fragment.
     *
     * @return array<int, array<string, mixed>>
     */
    private function lifecycle(string $text, string $evidence): array
    {
        $findings = [];

        // Whole words only: "production" is not stated by "product design".
        if (preg_match(self::PRODUCTION, $text, $span) === 1 && preg_match('/\bproduction\b|\bprod\b/i', $evidence) !== 1) {
            $phrase = trim($span[0]);
            $repairable = $this->withoutProduction($text) !== null;
            $findings[] = [
                'kind' => 'lifecycle_upgrade', 'severity' => 'block', 'detail' => $phrase,
                'message' => 'takes the work "'.$phrase.'", a later stage of delivery than the cited evidence states',
                ...($repairable ? ['repair' => ['op' => 'production']] : []),
            ];
        }

        foreach (['launch' => ['/\b(?:launch(?:ed|ing)?|go-live|went live)\b/i', '/\b(?:launch\w*|go-live|went live)\b/i'], 'maintenance' => ['/\b(?:maintain(?:ed|ing)|maintenance|operat(?:ed|ing)|on-call|post-launch)\b/i', '/\b(?:maintain\w*|maintenance|operat\w*|on-call|post-launch)\b/i']] as $stage => [$textPattern, $evidencePattern]) {
            if (preg_match($textPattern, $text, $hit) === 1 && preg_match($evidencePattern, $evidence) !== 1) {
                $findings[] = [
                    'kind' => 'lifecycle_upgrade', 'severity' => 'review', 'detail' => $hit[0],
                    'message' => "says the work was \"{$hit[0]}\" ({$stage}), a later stage of delivery than the cited evidence states",
                ];
            }
        }

        return $findings;
    }

    /**
     * The first word of a group of equivalent words that the text uses, as whole words with hyphen and space interchangeable.
     *
     * @param  array<int, string>  $group
     */
    private function firstWord(string $text, array $group): ?string
    {
        foreach ($group as $word) {
            $pattern = '/(?<![\p{L}\p{N}])'.strtr(preg_quote($word, '/'), ['\\-' => '[\s-]', ' ' => '[\s-]']).'(?![\p{L}\p{N}])/iu';

            if (preg_match($pattern, $text, $m) === 1) {
                return mb_strtolower($m[0]);
            }
        }

        return null;
    }

    /**
     * The text without a qualifier, keeping the sentence whole: the word, its comma and its space go, and "a" or "an" before
     * it agrees with the word that follows.
     */
    private function withoutQualifier(string $text, string $word): string
    {
        $pattern = strtr(preg_quote($word, '/'), ['\\-' => '[\s-]', ' ' => '[\s-]']);
        $text = (string) preg_replace_callback('/\b(a|an)\s+'.$pattern.'\b,?\s*([\p{L}])/iu', function (array $m): string {
            $vowel = preg_match('/^(?:[aeio]|u(?!s|ni))/iu', $m[2]) === 1;

            return ($m[1] === ucfirst($m[1]) ? ($vowel ? 'An' : 'A') : ($vowel ? 'an' : 'a')).' '.$m[2];
        }, $text);

        return (string) preg_replace('/\s*(?<![\p{L}\p{N}])'.$pattern.'(?![\p{L}\p{N}]),?(?=\s)/iu', '', $text);
    }

    private function core(string $figure): string
    {
        return (string) preg_replace('/[^\d.–]/u', '', str_replace(',', '', $this->range($figure)));
    }

    /**
     * A figure with its range written one way: "10 to 15", "10-15" and "10–15" all become "10–15".
     */
    private function range(string $figure): string
    {
        return (string) preg_replace('/\s*(?:[–-]|\bto\b)\s*/iu', '–', $figure);
    }

    /**
     * Every figure in a text. A range written with "to" is one figure only when it rises ("10 to 15 hours"); "from 10 to 3
     * hours" states two figures, as it always did.
     *
     * @return array<int, array<string, string>>
     */
    private function figureMatches(string $text): array
    {
        preg_match_all(self::FIGURE, $text, $found, PREG_SET_ORDER);
        $out = [];

        foreach ($found as $m) {
            if (preg_match('/^(?<a>[^\s]+)\s+to\s+(?<b>.+)$/iu', $m['f'], $parts) === 1) {
                $a = (float) preg_replace('/[^\d.]/', '', $parts['a']);
                $b = (float) preg_replace('/[^\d.]/', '', $parts['b']);

                if ($b <= $a) {
                    $out[] = ['q' => $m['q'], 'f' => $parts['a']];
                    $out[] = ['q' => '', 'f' => $parts['b']];

                    continue;
                }
            }

            $out[] = ['q' => $m['q'] ?? '', 'f' => $m['f']];
        }

        return $out;
    }

    /**
     * @param  array<string, array<int, array{q: string, f: string}>>  $byCore
     */
    private function rangeEndsStated(string $core, array $byCore): bool
    {
        [$low, $high] = array_pad(explode('–', $core, 2), 2, '');

        return $low !== '' && $high !== '' && isset($byCore[$low], $byCore[$high]);
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

    private const PRODUCTION = '/\s*\b(?<prep>(?:in|to|into|through|for|at|on)\s+)?production(?<suffix>-grade|-ready|-level|-scale)?\b/i';

    /** After "production", these mean the word ends its phrase ("to production and monitored"), so it is a stage, not a modifier. */
    private const PHRASE_END = '/^(?:and|or|but|then|while|where|when|after|before|until|so|that|which|to|for|in|on|at|by|from|with|without|using|via|across|of|as|per|into|through|daily|weekly|monthly|today|often|regularly|continuously|\w+ly)\b/i';

    /**
     * Removes the unsupported word "production" and nothing else the sentence needs:
     *   "into production software"   -> "into software"      (a modifier: the preposition stays)
     *   "production deployment"      -> "deployment"
     *   "in production environments" -> "in environments"
     *   "deployed to production."    -> "deployed."          (a stage: the whole "to production" goes)
     * Null when any occurrence is a bare noun with nothing to remove it from ("supported production"), where taking the word
     * out would leave a broken sentence; the caller then flags the text for a person instead of rewriting it.
     */
    private function withoutProduction(string $text): ?string
    {
        $unsafe = false;

        $next = (string) preg_replace_callback(self::PRODUCTION, function (array $m) use ($text, &$unsafe): string {
            $prep = $m['prep'] ?? '';
            $tail = ltrim(mb_substr($text, mb_strpos($text, $m[0]) + mb_strlen($m[0])));
            $stage = $tail === '' || preg_match('/^[,.;:)!?]/u', $tail) === 1 || preg_match(self::PHRASE_END, $tail) === 1;

            if (($m['suffix'] ?? '') !== '' || ! $stage) {
                // A modifier ("production software", "production-ready code"): the word goes, the preposition stays.
                return $prep === '' ? '' : ' '.rtrim($prep);
            }

            if ($prep === '') {
                $unsafe = true; // "supported production": the word is the object, not a modifier.

                return $m[0];
            }

            return ''; // "to production": the stage phrase goes whole.
        }, $text);

        if ($unsafe) {
            return null;
        }

        // A modifier removed from the front leaves a leading space; "in , " style gaps are not produced by the cases above.
        return trim((string) preg_replace('/\s{2,}/u', ' ', $next));
    }

    /**
     * The verb to put in place of an unsupported lead verb, chosen from the facts that describe the sentence's own subject,
     * never from which fact came first. The words right after the verb ("Verbatim, a documentation Q&A system") name the
     * subject; only the facts that mention it can say what was done to it. When they agree on one verb, or one of them is a
     * build verb (the most specific action, ahead of reuse), that verb is used. Null when no fact describes the subject, or
     * the facts that do disagree on a verb with no clear order between them.
     *
     * @param  array<int, string>  $facts
     */
    private function replacementVerb(string $text, string $verb, array $facts): ?string
    {
        $after = preg_replace('/^\s*'.preg_quote($verb, '/').'\b\s*/iu', '', $text) ?? '';
        $subject = preg_split('/[,;:]|\s(?:to|that|which|for|using|with|by|in)\s/iu', $after)[0] ?? '';
        $terms = $this->significant($subject);

        if ($terms === []) {
            return null;
        }

        $verbs = [];

        foreach ($facts as $fact) {
            $lead = $this->leadVerb($fact);

            if ($lead === null) {
                continue;
            }

            foreach ($terms as $term) {
                if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($term, '/').'/iu', $fact) === 1) {
                    $verbs[$lead] = true;

                    break;
                }
            }
        }

        $verbs = array_keys($verbs);

        if (count($verbs) === 1) {
            return $verbs[0];
        }

        // Several facts speak of the subject: the most specific defensible action wins, and reuse only when nothing else does.
        foreach (['Built', 'Developed', 'Implemented', 'Integrated', 'Deployed'] as $preferred) {
            if (in_array($preferred, $verbs, true)) {
                return $preferred;
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $citedKeys
     * @param  array<string, string>  $factTextByKey
     * @return array<int, string>
     */
    private function evidenceByFact(array $citedKeys, array $factTextByKey): array
    {
        return array_values(array_filter(array_map(fn (string $k) => trim($factTextByKey[$k] ?? ''), $citedKeys), fn (string $t) => $t !== ''));
    }

    private function leadVerb(string $evidence): ?string
    {
        // The evidence's own opening verb: regular past tense, or one of the few irregular ones resumes use.
        return preg_match('/^\s*((?:[A-Z][a-z]+ed|Built|Led|Ran|Wrote|Made|Cut|Grew|Chose|Set))\b/', $evidence, $m) === 1 ? $m[1] : null;
    }
}
