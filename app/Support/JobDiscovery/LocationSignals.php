<?php

namespace App\Support\JobDiscovery;

/**
 * The small, bounded set of location patterns shared by the discovery gate and the qualification eligibility check, so
 * the two never disagree about what "US", "Florida" or "outside the US" look like in a location string. Deliberately not a
 * geographic parser: a fixed list of countries and regions, and a Florida test that never reads another state as Florida.
 */
final class LocationSignals
{
    private const NON_US_PLACES = [
        'canada', 'united kingdom', 'uk', 'england', 'ireland', 'germany', 'france', 'spain', 'portugal', 'netherlands',
        'poland', 'europe', 'the eu', 'eu', 'emea', 'apac', 'india', 'australia', 'new zealand', 'brazil', 'mexico',
        'argentina', 'israel', 'singapore', 'japan', 'philippines', 'latam',
    ];

    public static function mentionsFlorida(string $text): bool
    {
        return preg_match('/\bflorida\b|,\s*FL\b|\bFL\s*(?:,|$)/i', $text) === 1;
    }

    public static function mentionsUs(string $text): bool
    {
        // "US" is matched case-sensitively so the pronoun in "join us" is not read as the country.
        return preg_match('/\b(?:united\s+states|usa|north\s+america)\b/i', $text) === 1
            || preg_match('/\bU\.S\.|\bUS\b/', $text) === 1;
    }

    public static function mentionsNonUsPlace(string $text): bool
    {
        foreach (self::NON_US_PLACES as $place) {
            if (preg_match('/\b'.preg_quote($place, '/').'\b/i', $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /** "Anywhere", "Worldwide" and similar: a remote role open to the US as much as anywhere else. */
    public static function mentionsWorldwide(string $text): bool
    {
        return preg_match('/\b(?:anywhere|worldwide|global|globally)\b/i', $text) === 1;
    }

    /** Names only places outside the US: the one location signal that unambiguously contradicts a US-remote search. */
    public static function isNonUsOnly(string $text): bool
    {
        return self::mentionsNonUsPlace($text) && ! self::mentionsUs($text) && ! self::mentionsWorldwide($text);
    }
}
