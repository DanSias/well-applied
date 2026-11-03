<?php

namespace App\Support\ResumeProfile;

/**
 * The evidence that a posting belongs to a role family, kept so a person can judge the
 * decision: which title terms matched, and, for a generic title, which front-end and
 * back-end terms the description asks for.
 */
final class RoleSignals
{
    /**
     * @param  array<string, mixed>  $relevance  the profile's `relevance` config
     * @return array{full_stack: bool, basis: string, title_terms: array<int, string>, frontend: array<int, string>, backend: array<int, string>, chars: int, completeness: string}
     */
    public static function compute(SampleCandidate $c, array $relevance): array
    {
        $title = mb_strtolower($c->title);
        $titleTerms = array_values(array_filter((array) $relevance['title_terms'], fn (string $t) => str_contains($title, $t)));
        $text = mb_strtolower($c->text);
        $found = fn (array $terms) => array_values(array_filter($terms, fn (string $t) => preg_match('/(?<![\p{L}\p{N}])'.preg_quote($t, '/').'(?![\p{L}\p{N}])/u', $text) === 1));
        $frontend = $found((array) $relevance['frontend_terms']);
        $backend = $found((array) $relevance['backend_terms']);

        return [
            'full_stack' => $titleTerms !== [] || ($frontend !== [] && $backend !== []),
            'basis' => $titleTerms !== [] ? 'title' : 'description',
            'title_terms' => $titleTerms,
            'frontend' => $frontend,
            'backend' => $backend,
            'chars' => mb_strlen($c->text),
            'completeness' => $c->completeness->value,
        ];
    }

    /**
     * @param  array<string, mixed>  $signals
     */
    public static function explain(array $signals): string
    {
        if (($signals['basis'] ?? null) === 'title') {
            return 'The title names a full-stack or product-engineering role ('.implode(', ', $signals['title_terms'] ?? []).').';
        }

        return 'A general software-engineer title; the description asks for front-end ('.implode(', ', array_slice($signals['frontend'] ?? [], 0, 3)).') and back-end ('.implode(', ', array_slice($signals['backend'] ?? [], 0, 3)).') work.';
    }
}
