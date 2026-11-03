<?php

namespace App\Support\ResumeVariant;

/**
 * Claim-level scope validation for generated resume text.
 *
 * Goal: confident, interview-defensible wording, not literal transcription.
 * Scope labels are evidence, not verb permissions, and the CITED evidence is
 * judged together. Only claims that could not be defended in an interview are
 * flagged. For each "<action> ... <topic>" claim:
 *
 *  - hands-on actions (integrating, configuring, troubleshooting, advising,
 *    supporting) on a topic that the cited evidence covers: pass. Related facts
 *    together can support them; this is ordinary synthesis;
 *  - ownership (led, owned, managed) or implementation (built, architected)
 *    that the topic's evidence does not establish: `review` when it is
 *    genuinely uncertain, `block` when the user's own declared scope excludes
 *    building it and nothing else backs it;
 *  - a topic that no cited fact mentions: a named technology (marked `named`
 *    in config) is `block`; a strong claim with nothing supporting it is
 *    `block`, with some support elsewhere `review`; a hands-on claim is left
 *    to the other checks.
 *
 * Deterministic; no model call. Vocabulary lives in config/clarification.php.
 */
final class ClaimScopeChecker
{
    /**
     * @param  array<string, string>  $factTextByKey  fact key => statement (evidence-bearing text)
     * @param  array<string, array<int, string>>  $declaredScopesByKey  fact key => the user's selected ResponsibilityScope values
     * @param  array<int, string>  $citedKeys
     * @return array<int, array{kind: string, severity: string, detail: string, message: string}>
     */
    public function findings(string $text, array $citedKeys, array $factTextByKey, array $declaredScopesByKey): array
    {
        $topics = $this->topics();
        $verbs = $this->verbPatterns();
        $findings = [];
        $seen = [];

        foreach (preg_split('/(?<=[.;:])\s+/u', $text) ?: [] as $clause) {
            $verbHits = $this->hits($clause, $verbs);

            foreach ($topics as $topicKey => $topic) {
                foreach ($this->hits($clause, ['t' => $topic['patterns']]) as $mention) {
                    $governing = null;

                    foreach ($verbHits as $hit) {
                        if ($hit['offset'] < $mention['offset']) {
                            $governing = $hit;
                        }
                    }

                    // A named technology is checked even without an action verb ("Worked with Rails").
                    if ($governing === null && ! $topic['named']) {
                        continue;
                    }

                    $governing ??= ['class' => 'support', 'offset' => $mention['offset'], 'length' => 0];
                    $excerpt = trim(mb_strcut($clause, $governing['offset'], $mention['offset'] + $mention['length'] - $governing['offset']));
                    $dedupe = $governing['offset'].'|'.$topicKey;

                    if (isset($seen[$dedupe])) {
                        continue;
                    }

                    $seen[$dedupe] = true;
                    $finding = $this->judge($governing['class'], (string) $topicKey, $topic['label'], $topic['patterns'], $excerpt, $citedKeys, $factTextByKey, $declaredScopesByKey);

                    if ($finding !== null) {
                        $findings[] = $finding;
                    }
                }
            }
        }

        return $findings;
    }

    /**
     * @param  array<int, string>  $topicPatterns
     * @param  array<int, string>  $citedKeys
     * @param  array<string, string>  $factTextByKey
     * @param  array<string, array<int, string>>  $declaredScopesByKey
     * @return array{kind: string, severity: string, detail: string, message: string}|null
     */
    private function judge(string $class, string $topicKey, string $topicLabel, array $topicPatterns, string $excerpt, array $citedKeys, array $factTextByKey, array $declaredScopesByKey): ?array
    {
        $onTopic = [];
        $elsewhere = [];

        foreach ($citedKeys as $key) {
            $statement = $factTextByKey[$key] ?? '';
            $mentions = array_filter($topicPatterns, fn (string $p) => preg_match($p, $statement) === 1) !== [];
            $mentions ? $onTopic[] = $key : $elsewhere[] = $key;
        }

        $scopesOf = fn (string $key): array => $this->factClasses($key, $factTextByKey[$key] ?? '', $declaredScopesByKey);

        foreach ($onTopic as $key) {
            if ($this->supports($scopesOf($key), $class)) {
                return null;
            }
        }

        $strong = in_array($class, (array) config('clarification.claim_scope.strong'), true);
        $label = (string) config("clarification.claim_scope.labels.{$class}", $class);
        $named = (bool) config("clarification.topics.{$topicKey}.named", false);
        $elsewhereSupport = array_values(array_filter($elsewhere, fn (string $k) => $this->supports($scopesOf($k), $class)));

        // The claim names something no cited fact mentions.
        if ($onTopic === []) {
            if ($named) {
                return $this->finding('block', $excerpt, "Names {$topicLabel} (\"{$excerpt}\"), but no cited evidence mentions it.");
            }

            if (! $strong) {
                return null;
            }

            return $elsewhereSupport === []
                ? $this->finding('block', $excerpt, "Claims \"{$excerpt}\" ({$label}), but no cited evidence mentions {$topicLabel} and nothing cited supports {$label} anything.")
                : $this->finding('review', $excerpt, "Claims \"{$excerpt}\", but no cited evidence mentions {$topicLabel}; the {$label} comes from a different fact. Confirm you did this for {$topicLabel}.");
        }

        // Evidence on the topic exists and the claim is hands-on rather than
        // ownership: ordinary synthesis, scope labels are evidence not permissions.
        if (! $strong) {
            return null;
        }

        $established = 'the evidence for '.$topicLabel.' only establishes: '.implode('; ', array_map(
            fn (string $k) => $this->describe($k, $factTextByKey[$k] ?? '', $scopesOf($k)), $onTopic,
        ));
        $scopeKnown = array_filter($onTopic, fn (string $k) => isset($declaredScopesByKey[$k]) || $scopesOf($k) !== []) !== [];

        // Building it is a concrete claim: clearly contradicted when the user's own scope excludes it and nothing else backs it.
        if ($class === 'implementation' && $scopeKnown && $elsewhereSupport === []) {
            return $this->finding('block', $excerpt, "Claims \"{$excerpt}\" ({$label}), but {$established}. Implementation is not established.");
        }

        // Ownership, or implementation backed only by an unrelated fact: genuine uncertainty.
        return $this->finding('review', $excerpt, "Claims \"{$excerpt}\" ({$label}), but {$established}. Confirm you owned or built this before sending.");
    }

    /**
     * @return array{kind: string, severity: string, detail: string, message: string}
     */
    private function finding(string $severity, string $excerpt, string $message): array
    {
        return ['kind' => 'claim_scope', 'severity' => $severity, 'detail' => $excerpt, 'message' => $message];
    }

    /**
     * The action classes one fact establishes: what the user declared when
     * they confirmed it, otherwise what its own statement says.
     *
     * @param  array<string, array<int, string>>  $declaredScopesByKey
     * @return array<int, string>
     */
    private function factClasses(string $key, string $statement, array $declaredScopesByKey): array
    {
        if (isset($declaredScopesByKey[$key])) {
            $map = (array) config('clarification.claim_scope.scope_classes');

            return array_values(array_unique(array_filter(array_map(fn (string $scope) => $map[$scope] ?? null, $declaredScopesByKey[$key]))));
        }

        return array_values(array_unique(array_map(fn (array $hit) => $hit['class'], $this->hits($statement, $this->verbPatterns()))));
    }

    /**
     * @param  array<int, string>  $classes
     */
    private function supports(array $classes, string $class): bool
    {
        $implies = (array) config('clarification.claim_scope.implies');

        foreach ($classes as $have) {
            if ($have === $class || in_array($class, (array) ($implies[$have] ?? []), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>  $classes
     */
    private function describe(string $key, string $statement, array $classes): string
    {
        $labels = array_map(fn (string $c) => (string) config("clarification.claim_scope.labels.{$c}", $c), $classes);

        return '"'.$statement.'" ('.($labels === [] ? 'no specific action stated' : implode(', ', $labels)).')';
    }

    /**
     * @return array<string, array{label: string, patterns: array<int, string>, named: bool}>
     */
    private function topics(): array
    {
        $topics = [];

        foreach ((array) config('clarification.topics', []) as $key => $topic) {
            $topics[(string) $key] = ['label' => (string) $topic['label'], 'patterns' => array_values((array) $topic['patterns']), 'named' => (bool) ($topic['named'] ?? false)];
        }

        return $topics;
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function verbPatterns(): array
    {
        $out = [];

        foreach ((array) config('clarification.claim_scope.verbs') as $class => $forms) {
            $out[(string) $class] = ['/(?<![\w-])(?:'.implode('|', array_map(fn (string $f) => str_replace(' ', '\s+', $f), (array) $forms)).')(?![\w-])/iu'];
        }

        return $out;
    }

    /**
     * Every match of any pattern (keyed by class) with its byte offset, in text order.
     *
     * @param  array<string, array<int, string>>  $patternsByClass
     * @return array<int, array{class: string, offset: int, length: int}>
     */
    private function hits(string $text, array $patternsByClass): array
    {
        $hits = [];

        foreach ($patternsByClass as $class => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match_all($pattern, $text, $m, PREG_OFFSET_CAPTURE) > 0) {
                    foreach ($m[0] as [$match, $offset]) {
                        $hits[] = ['class' => (string) $class, 'offset' => (int) $offset, 'length' => strlen((string) $match)];
                    }
                }
            }
        }

        usort($hits, fn (array $a, array $b) => $a['offset'] <=> $b['offset']);

        return $hits;
    }
}
