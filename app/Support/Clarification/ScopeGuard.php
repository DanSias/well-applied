<?php

namespace App\Support\Clarification;

use App\Enums\ResponsibilityScope;

/**
 * Keeps a proposed statement inside what the user said they did. A verb
 * that claims building or ownership is allowed only when the user selected
 * "Implemented it myself" or used that very word in their own answer, so
 * "configured webhooks" can never silently become "built webhook handlers".
 */
final class ScopeGuard
{
    /**
     * @param  array<int, string>  $scopes
     * @return array<int, string> human-readable problems; empty when the wording is within scope
     */
    public function violations(string $statement, array $scopes, string $answerText): array
    {
        if (in_array(ResponsibilityScope::Implemented->value, $scopes, true)) {
            return [];
        }

        $problems = [];
        $claims = array_merge(
            (array) config('clarification.scope_guard.implementation_verbs'),
            (array) config('clarification.scope_guard.ownership_verbs'),
            (array) config('clarification.scope_guard.implementation_phrases'),
        );

        foreach (array_unique($claims) as $claim) {
            $pattern = '/(?<![\w])'.preg_quote($claim, '/').'(?![\w])/iu';

            if (preg_match($pattern, $statement) === 1 && preg_match($pattern, $answerText) !== 1) {
                $problems[] = "The wording says \"{$claim}\", which is more than the scope you selected supports. "
                    .'Reword it, or select "'.ResponsibilityScope::Implemented->label().'" if that is true.';
            }
        }

        return $problems;
    }
}
