<?php

namespace App\Support\ResumeVariant;

/**
 * Claim-level scope validation for generated resume text.
 *
 * Wording may merge several cited facts into one sentence. Each fact can be
 * individually true while the merged sentence says more than any of them:
 * "worked with webhooks" plus "troubleshot integration issues" is not
 * "troubleshot webhooks". This reads every "<action> ... <topic>" claim in a
 * sentence and asks whether a cited fact about THAT topic establishes THAT
 * kind of action (implementation, integration, configuration,
 * troubleshooting, advising, support).
 *
 *  - supported by a fact on the topic: no finding;
 *  - the action only appears in a cited fact that does not mention the
 *    topic, or is not established by anything: `review` (ships, flagged,
 *    with the exact claim and why);
 *  - implementation or ownership that the evidence rules out, either because
 *    the user's declared scopes exclude it or because nothing cited supports
 *    it: `block` (recovery omits the bullet, as for any other block).
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

            if ($verbHits === []) {
                continue;
            }

            foreach ($topics as $topicKey => $topic) {
                foreach ($this->hits($clause, ['t' => $topic['patterns']]) as $mention) {
                    $governing = null;

                    foreach ($verbHits as $hit) {
                        if ($hit['offset'] < $mention['offset']) {
                            $governing = $hit;
                        }
                    }

                    if ($governing === null) {
                        continue;
                    }

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
        $elsewhereSupport = array_values(array_filter($elsewhere, fn (string $k) => $this->supports($scopesOf($k), $class)));
        $topicScopeKnown = array_filter($onTopic, fn (string $k) => isset($declaredScopesByKey[$k]) || $scopesOf($k) !== []) !== [];
        $established = $onTopic === []
            ? 'no cited evidence mentions '.$topicLabel
            : 'the evidence for '.$topicLabel.' only establishes: '.implode('; ', array_map(
                fn (string $k) => $this->describe($k, $factTextByKey[$k] ?? '', $scopesOf($k)), $onTopic,
            ));

        // Nothing on the topic is cited at all: only a strong claim is worth a flag.
        if ($onTopic === []) {
            if (! $strong) {
                return null;
            }

            return $elsewhereSupport === []
                ? $this->finding('block', $excerpt, "Claims \"{$excerpt}\" ({$label}), but {$established}, and nothing cited supports {$label} it.")
                : $this->finding('review', $excerpt, "Claims \"{$excerpt}\", but {$established}. The {$label} comes from a different cited fact. Confirm you did this for {$topicLabel}.");
        }

        // Clearly unsupported ownership: strong claim, the topic's scope is known, nothing supports it.
        if ($strong && $topicScopeKnown && $elsewhereSupport === []) {
            return $this->finding('block', $excerpt, "Claims \"{$excerpt}\" ({$label}), but {$established}. Ownership or implementation is not established.");
        }

        if ($elsewhereSupport !== []) {
            $from = $factTextByKey[$elsewhereSupport[0]] ?? $elsewhereSupport[0];

            return $this->finding('review', $excerpt, "Combines separate facts into one claim: \"{$excerpt}\". ".ucfirst($established).". The {$label} part comes from \"{$from}\", which does not mention {$topicLabel}. Confirm you did this for {$topicLabel}.");
        }

        return $this->finding('review', $excerpt, "Claims \"{$excerpt}\" ({$label}), but {$established}. Confirm before sending.");
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
     * @return array<string, array{label: string, patterns: array<int, string>}>
     */
    private function topics(): array
    {
        $topics = [];

        foreach ((array) config('clarification.topics', []) as $key => $topic) {
            $topics[(string) $key] = ['label' => (string) $topic['label'], 'patterns' => array_values((array) $topic['patterns'])];
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
