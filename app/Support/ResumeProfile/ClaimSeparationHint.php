<?php

namespace App\Support\ResumeProfile;

/**
 * A plain heuristic, shown beside an answer, for "this may be several claims". It does not split
 * anything: it only tells the user to consider approving one claim at a time, so each approved
 * fact has one scope, one attribution and one visibility.
 */
final class ClaimSeparationHint
{
    /**
     * @param  array<int, string>  $knownNames  employer and project names from the user's record
     * @param  array<int, string>  $scopes  responsibility scopes ticked for the answer
     * @return array{likely: bool, reasons: array<int, string>}
     */
    public static function check(string $answer, array $knownNames, array $scopes): array
    {
        $reasons = [];
        $sentences = count(array_filter(preg_split('/(?<=[.!?])\s+/u', trim($answer)) ?: [], fn (string $s) => mb_strlen(trim($s)) > 12));
        $named = array_values(array_unique(array_filter($knownNames, fn (string $n) => mb_strlen($n) > 2
            && preg_match('/(?<![\p{L}\p{N}])'.preg_quote($n, '/').'(?![\p{L}\p{N}])/iu', $answer) === 1)));
        $strong = false;

        if ($sentences >= 3) {
            $reasons[] = "The answer has {$sentences} sentences, which may describe more than one accomplishment.";
            $strong = true;
        }

        if (count($named) >= 2) {
            $reasons[] = 'It mentions several of your employers or projects ('.implode(', ', array_slice($named, 0, 3)).'), but a fact can be attributed to only one.';
            $strong = true;
        }

        if (preg_match('/;|\b(?:also|additionally|as well as|in addition|separately)\b/i', $answer) === 1) {
            $reasons[] = 'It joins separate points ("also", "as well as" or a semicolon).';
        }

        if (count($scopes) >= 3) {
            $reasons[] = count($scopes).' kinds of involvement were selected; check they all apply to one claim.';
        }

        return ['likely' => $strong || count($reasons) >= 2, 'reasons' => $reasons];
    }
}
