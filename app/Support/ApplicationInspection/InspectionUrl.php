<?php

namespace App\Support\ApplicationInspection;

/**
 * The one URL policy for everything inspection stores or hands to the browser worker: a URL a person typed, one a
 * provider supplied, and one the worker reports back. A URL that came from an API is not trusted for that reason.
 *
 * This is a structural check only (scheme, credentials, obviously internal hosts). It is NOT SSRF protection: a public
 * name can still resolve to a private address, which only the worker can see at connection time (see the worker's
 * navigation guard and its README). Both layers apply the same rules to the parts they can see.
 *
 * - acceptable(): the URL to keep and use, or null. Keeps functionally necessary query parameters (a Greenhouse
 *   `gh_jid` decides which job a form is for), strips well-known tracking parameters and the fragment.
 * - redact(): what may be shown or logged: origin and path only. A query string can carry tokens.
 */
final class InspectionUrl
{
    private const MAX_LENGTH = 2048;

    private const TRACKING_PARAMETERS = ['gclid', 'fbclid', 'msclkid', 'dclid', 'mc_cid', 'mc_eid', '_hsenc', '_hsmi', 'igshid', 'yclid'];

    public static function acceptable(mixed $url): ?string
    {
        if (! is_string($url)) {
            return null;
        }

        $url = trim($url);
        if ($url === '' || strlen($url) > self::MAX_LENGTH || preg_match('/\s|[\x00-\x1f]/', $url) === 1) {
            return null;
        }

        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        if (! in_array(strtolower($parts['scheme']), ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        if (self::internalHost($parts['host'])) {
            return null;
        }

        return self::withoutTracking($url, $parts);
    }

    /** Origin and path only: never a query string, fragment or credentials. Null for anything unparseable. */
    public static function redact(mixed $url): ?string
    {
        if (! is_string($url)) {
            return null;
        }

        $parts = parse_url(trim($url));
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        return strtolower($parts['scheme']).'://'.strtolower($parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '').($parts['path'] ?? '');
    }

    /** Free text (an error message) with every URL in it reduced to origin and path. */
    public static function redactText(string $text): string
    {
        return preg_replace_callback('~https?://[^\s"\'<>)\]]+~i', fn (array $m) => self::redact($m[0]) ?? '[url]', $text) ?? $text;
    }

    /** The same destination, whatever tracking or ordering differs: for comparing two URLs. */
    public static function comparable(string $url): string
    {
        $clean = self::acceptable($url) ?? trim($url);
        $parts = parse_url($clean);
        if ($parts === false || ! isset($parts['host'])) {
            return $clean;
        }

        parse_str($parts['query'] ?? '', $query);
        ksort($query);

        return strtolower($parts['scheme'] ?? 'https').'://'.strtolower($parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '').rtrim($parts['path'] ?? '', '/').($query === [] ? '' : '?'.http_build_query($query));
    }

    private static function internalHost(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));

        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return true;
        }

        if (str_contains($host, ':') || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            // Any IP literal: private/reserved ranges are refused outright, and a public literal is not a
            // legitimate application URL either (an ATS is always a hostname).
            return true;
        }

        // Single-label names (an intranet host) and all-numeric or hex forms such as http://2130706433/ or 0x7f.1.
        return ! str_contains($host, '.') || preg_match('/^[0-9.]+$|^0x[0-9a-f.]+$/i', $host) === 1;
    }

    /**
     * @param  array<string, mixed>  $parts
     */
    private static function withoutTracking(string $url, array $parts): string
    {
        $url = preg_replace('/#.*$/', '', $url) ?? $url;

        if (! isset($parts['query']) || $parts['query'] === '') {
            return $url;
        }

        $kept = array_filter(
            explode('&', $parts['query']),
            function (string $pair): bool {
                $name = strtolower(urldecode(explode('=', $pair, 2)[0]));

                return $name !== '' && ! str_starts_with($name, 'utm_') && ! in_array($name, self::TRACKING_PARAMETERS, true);
            },
        );

        $base = explode('?', $url, 2)[0];

        return $kept === [] ? $base : $base.'?'.implode('&', $kept);
    }
}
