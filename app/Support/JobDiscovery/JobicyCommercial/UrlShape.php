<?php

namespace App\Support\JobDiscovery\JobicyCommercial;

/**
 * What may appear in a report in place of a URL: host and path SHAPE. No scheme, port, credentials, query string, fragment, and no slug (a title or a
 * company name) or id: structural words are kept, numbers become N, ids become <id>, everything else <slug>.
 */
final class UrlShape
{
    private const STRUCTURAL = ['jobs', 'job', 'apply', 'application', 'careers', 'career', 'details', 'detail', 'land', 'ad', 'companies', 'company', 'positions', 'position', 'view', 'embed', 'job_app', 'en-us', 'redirect', 'click', 'track'];

    public static function of(?string $url): ?string
    {
        $parts = is_string($url) ? parse_url(trim($url)) : false;
        if ($parts === false || ! isset($parts['host'])) {
            return null;
        }

        $segments = array_values(array_filter(explode('/', $parts['path'] ?? ''), fn ($s) => $s !== ''));
        $shaped = array_map(function (string $segment): string {
            $s = strtolower($segment);

            return match (true) {
                in_array($s, self::STRUCTURAL, true) => $s,
                ctype_digit($s) => 'N',
                preg_match('/^[0-9a-f-]{8,}$/', $s) === 1 => '<id>',
                default => '<slug>',
            };
        }, $segments);

        return strtolower($parts['host']).'/'.implode('/', $shaped);
    }
}
