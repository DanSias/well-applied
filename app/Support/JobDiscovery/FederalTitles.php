<?php

namespace App\Support\JobDiscovery;

/**
 * The federal-only title patterns (config/job_discovery.php `usajobs.title_patterns`), applied by the discovery filter
 * to USAJOBS candidates and nobody else, so commercial title filtering cannot regress. Federal titles often name the
 * occupation and put the specialty in parentheses ("IT Specialist (APPSW)"); a bare "IT Specialist" is far too broad.
 */
final class FederalTitles
{
    public static function matches(string $title): bool
    {
        return self::firstMatch('title_patterns', $title) !== null;
    }

    /**
     * The supervisory-role term a federal title names (config `usajobs.excluded_title_patterns`), or null.
     */
    public static function excludedTerm(string $title): ?string
    {
        return self::firstMatch('excluded_title_patterns', $title);
    }

    private static function firstMatch(string $key, string $title): ?string
    {
        $patterns = config("job_discovery.usajobs.{$key}");

        foreach (is_array($patterns) ? $patterns : [] as $pattern) {
            if (is_string($pattern) && $pattern !== '' && @preg_match('~'.str_replace('~', '\~', $pattern).'~iu', $title, $found) === 1) {
                return mb_strtolower($found[0]);
            }
        }

        return null;
    }
}
