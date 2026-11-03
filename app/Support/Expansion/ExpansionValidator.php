<?php

namespace App\Support\Expansion;

use App\Support\Clarification\ScopeGuard;

/**
 * Checks what the model returned against the answer, claim by claim. A claim that fails is dropped
 * with its reason; nothing the model says is trusted on its own:
 *  - attribution targets must be ones it was given;
 *  - the source quote must appear verbatim in the answer;
 *  - scopes must be a subset of what the user selected;
 *  - numbers and metric language must come from the answer;
 *  - a technology the statement names must appear in the answer or in a listed fact for that place;
 *  - implementation or ownership verbs must be supported (ScopeGuard, as at approval);
 *  - a claim that repeats another claim for the same places is dropped.
 */
final class ExpansionValidator
{
    public function __construct(private readonly ScopeGuard $scopeGuard) {}

    /**
     * @param  array<string, mixed>  $content
     * @param  array<string, mixed>  $ctx  ExpansionContext::build()
     * @return array{claims: array<int, array<string, mixed>>, followups: array<int, array<string, mixed>>, rejected: array<int, array<string, mixed>>}
     */
    public function validate(array $content, array $ctx): array
    {
        $answer = (string) $ctx['answer'];
        $capabilities = (array) config('resume_profiles.capabilities');
        $accepted = [];
        $rejected = [];

        foreach ((array) ($content['claims'] ?? []) as $i => $claim) {
            $claim = (array) $claim;
            $problem = $this->problem($claim, $ctx, $answer, $capabilities, $accepted);

            if ($problem !== null) {
                $rejected[] = ['statement' => (string) ($claim['statement'] ?? ''), 'reason' => $problem];

                continue;
            }

            $grounded = $this->ground(array_values(array_unique(array_filter((array) $claim['attributions'], fn ($t) => isset($ctx['targets'][$t])))), $ctx);

            if ($grounded['kept'] === []) {
                $rejected[] = ['statement' => (string) ($claim['statement'] ?? ''), 'reason' => 'no attribution is grounded in the question or the answer'];

                continue;
            }

            $claim['attributions'] = array_keys($grounded['kept']);
            $claim['left_out'] = $grounded['left_out'];

            if (in_array('inferred', $grounded['kept'], true)) {
                $claim['confidence'] = $claim['confidence'] === 'high' ? 'medium' : $claim['confidence'];
                $claim['rationale'] = trim($claim['rationale'].' Inferred from the answer\'s wording for: '.implode(', ', array_map(fn (string $k) => $ctx['targets'][$k]['label'], array_keys(array_filter($grounded['kept'], fn ($b) => $b === 'inferred')))).'. Check it.');
            }

            $claim['scopes'] = array_values(array_intersect((array) $claim['scopes'], (array) $ctx['scopes']));
            $claim['statement'] = trim((string) $claim['statement']);
            $accepted[] = $claim;
        }

        return ['claims' => $accepted, 'followups' => $this->followups($content, $ctx, $capabilities), 'rejected' => $rejected];
    }

    /**
     * @param  array<string, mixed>  $claim
     * @param  array<string, mixed>  $ctx
     * @param  array<string, mixed>  $capabilities
     * @param  array<int, array<string, mixed>>  $accepted
     */
    private function problem(array $claim, array $ctx, string $answer, array $capabilities, array $accepted): ?string
    {
        $statement = trim((string) ($claim['statement'] ?? ''));

        if (! isset($capabilities[$claim['capability'] ?? ''])) {
            return 'unknown capability';
        }

        $targets = array_values(array_unique(array_filter((array) ($claim['attributions'] ?? []), fn ($t) => isset($ctx['targets'][$t]))));

        if ($targets === []) {
            return 'no valid attribution';
        }

        if (mb_strlen($statement) < 20 || mb_strlen($statement) > 260) {
            return 'statement length';
        }

        if (! $this->contains($answer, (string) ($claim['source_quote'] ?? ''))) {
            return 'quote not found in the answer';
        }

        $scopes = array_values(array_intersect((array) ($claim['scopes'] ?? []), (array) $ctx['scopes']));

        if ($scopes === []) {
            return 'scope not selected by the user';
        }

        preg_match_all('/\d+(?:[.,]\d+)?/', $statement, $numbers);
        preg_match_all('/\d+(?:[.,]\d+)?/', $answer, $allowed);

        if (array_diff($numbers[0], $allowed[0]) !== []) {
            return 'number not in the answer';
        }

        // Outcomes and metrics must come from the user: an outcome verb or phrase the answer never used is an invention.
        if (preg_match_all('/\b(?:reduced?|increased?|improved?|saved?|boosted?|cut|grew|doubled|tripled|eliminated|accelerated|streamlined|resulting in|result(?:ed)? in|leading to|led to)\b/i', $statement, $outcomes) > 0) {
            foreach ($outcomes[0] as $word) {
                if (stripos($answer, rtrim(mb_strtolower($word), 'sd')) === false) {
                    return 'outcome or metric not in the answer';
                }
            }
        }

        // Scale and breadth qualifiers are claims too: they must come from the user.
        if (preg_match_all('/\b(?:multiple|several|numerous|dozens|hundreds|enterprise|large[- ]scale|mission[- ]critical|high[- ]traffic|production|client engagements?|global|worldwide)\b/i', $statement, $qualifiers) > 0) {
            foreach ($qualifiers[0] as $q) {
                if (stripos($answer, $q) === false) {
                    return "qualifier \"{$q}\" is not in the answer";
                }
            }
        }

        $known = mb_strtolower($answer.' '.implode(' ', array_map(fn (string $t) => implode(' ', (array) ($ctx['existing'][$t] ?? [])), $targets)));

        foreach ($capabilities as $capability) {
            if ($capability['category'] !== 'technology') {
                continue;
            }

            foreach ($capability['patterns'] as $pattern) {
                if (preg_match($pattern, $statement, $m) === 1 && ! str_contains($known, mb_strtolower($m[0]))) {
                    return "technology \"{$m[0]}\" is not in the answer";
                }
            }
        }

        if ($this->scopeGuard->violations($statement, $scopes, $answer) !== []) {
            return 'claims more than the selected scope supports';
        }

        foreach ($accepted as $other) {
            if ($other['capability'] === $claim['capability'] && array_intersect($targets, $other['attributions']) !== [] && $this->similarity($statement, (string) $other['statement']) >= 0.7) {
                return 'repeats another claim';
            }
        }

        return null;
    }

    /**
     * Keeps an attribution only when the question or the answer points at it. A broad phrase ("all
     * projects") is never expanded to every employer or role: only places the question named, places the
     * answer names, a place the answer's wording singles out (a distinctive word found in exactly one
     * place's name), and the role that holds such a project survive. When a role and one of its own named
     * projects both appear, the specific project wins. Everything else is reported as left out, for the
     * user to add if it applies.
     *
     * @param  array<int, string>  $targets
     * @param  array<string, mixed>  $ctx
     * @return array{kept: array<string, string>, left_out: array<int, string>}
     */
    public function ground(array $targets, array $ctx): array
    {
        $answer = mb_strtolower((string) $ctx['answer']);
        $basis = [];

        foreach ((array) $ctx['named_in_question'] as $key) {
            $basis[$key] = 'named';
        }

        foreach ((array) $ctx['names'] as $key => $names) {
            foreach ($names as $name) {
                if ($name !== '' && preg_match('/(?<![\p{L}\p{N}])'.preg_quote(mb_strtolower($name), '/').'(?![\p{L}\p{N}])/u', $answer) === 1) {
                    $basis[$key] ??= 'named';
                }
            }
        }

        // A word of the answer that appears in exactly one place's name singles that place out.
        $stop = ['the', 'and', 'for', 'with', 'project', 'projects', 'tracker', 'tooling', 'setup', 'hub', 'center', 'command', 'activity', 'data', 'performance'];
        $tokens = fn (string $s) => array_values(array_diff(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($s), -1, PREG_SPLIT_NO_EMPTY) ?: [], $stop));
        $answerWords = array_flip($tokens($answer));
        $owners = [];

        foreach ((array) $ctx['names'] as $key => $names) {
            if (str_starts_with($key, 'project:')) {
                foreach ($tokens(implode(' ', $names)) as $token) {
                    $owners[$token][] = $key;
                }
            }
        }

        foreach ($owners as $token => $keys) {
            if (count($keys) === 1 && mb_strlen($token) > 3 && isset($answerWords[$token])) {
                $basis[$keys[0]] ??= 'inferred';
            }
        }

        // A role is grounded when it holds a grounded project, or is itself named.
        foreach (array_keys($basis) as $key) {
            if (isset($ctx['parents'][$key])) {
                $basis[$ctx['parents'][$key]] ??= 'umbrella';
            }
        }

        $kept = [];
        $left = [];

        foreach ($targets as $key) {
            if (isset($basis[$key])) {
                $kept[$key] = $basis[$key];
            } else {
                $left[] = $ctx['targets'][$key]['label'];
            }
        }

        // A role listed together with one of its own grounded projects is redundant: keep the specific place.
        foreach (array_keys($kept) as $key) {
            if (str_starts_with($key, 'role:') && count(array_filter(array_keys($kept), fn ($k) => ($ctx['parents'][$k] ?? null) === $key)) > 0) {
                unset($kept[$key]);
            }
        }

        return ['kept' => $kept, 'left_out' => $left];
    }

    /**
     * @param  array<string, mixed>  $content
     * @param  array<string, mixed>  $ctx
     * @param  array<string, mixed>  $capabilities
     * @return array<int, array<string, mixed>>
     */
    private function followups(array $content, array $ctx, array $capabilities): array
    {
        $out = [];

        foreach (array_slice((array) ($content['followups'] ?? []), 0, 2) as $f) {
            $f = (array) $f;
            $q = trim((string) ($f['question'] ?? ''));

            if (isset($capabilities[$f['capability'] ?? ''])
                && in_array($f['aspect'] ?? '', ExpansionPrompt::ASPECTS, true)
                && str_ends_with($q, '?') && mb_strlen($q) >= 30 && $this->similarity($q, (string) $ctx['question']) < 0.6) {
                $out[] = ['capability' => $f['capability'], 'aspect' => $f['aspect'], 'question' => $q];
            }
        }

        return $out;
    }

    private function contains(string $haystack, string $needle): bool
    {
        $norm = fn (string $s) => mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $s)));

        return $norm($needle) !== '' && str_contains($norm($haystack), $norm($needle));
    }

    public function similarity(string $a, string $b): float
    {
        $tokens = fn (string $s) => array_unique(preg_split('/[^\p{L}\p{N}#+.]+/u', mb_strtolower($s), -1, PREG_SPLIT_NO_EMPTY) ?: []);
        $x = $tokens($a);
        $y = $tokens($b);
        $union = count(array_unique([...$x, ...$y]));

        return $union === 0 ? 0.0 : count(array_intersect($x, $y)) / $union;
    }
}
