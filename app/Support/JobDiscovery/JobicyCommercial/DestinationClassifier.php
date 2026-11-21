<?php

namespace App\Support\JobDiscovery\JobicyCommercial;

use App\Support\ApplicationInspection\InspectionUrl;

/**
 * Is a URL the Commercial API returned a GENUINE application destination? Pure. The host lists and the redirector signals are ported from the worker's
 * destination-resolution component (workers/browser-inspector/src/resolution/hosts.js) so both judge a URL the same way. It looks at the URL's text
 * only: nothing is fetched, so "genuine" means "an employer or ATS URL", not "reachable" or "the application form".
 *
 * Classes: genuine_ats, genuine_employer | jobicy_listing, aggregator, tracker_redirect, insecure_http, invalid.
 */
final class DestinationClassifier
{
    public const GENUINE = ['genuine_ats', 'genuine_employer'];

    private const ATS_HOSTS = [
        'greenhouse.io' => 'greenhouse', 'lever.co' => 'lever', 'ashbyhq.com' => 'ashby', 'myworkdayjobs.com' => 'workday', 'myworkdaysite.com' => 'workday',
        'workable.com' => 'workable', 'smartrecruiters.com' => 'smartrecruiters', 'eightfold.ai' => 'eightfold', 'icims.com' => 'icims', 'jobvite.com' => 'jobvite',
        'bamboohr.com' => 'bamboohr', 'recruitee.com' => 'recruitee', 'breezy.hr' => 'breezy', 'teamtailor.com' => 'teamtailor',
    ];

    private const AGGREGATOR_HOSTS = [
        'jobicy.com', 'adzuna.com', 'adzuna.co.uk', 'himalayas.app', 'indeed.com', 'linkedin.com', 'ziprecruiter.com', 'glassdoor.com', 'simplyhired.com',
        'jooble.org', 'talent.com', 'remoteok.com', 'weworkremotely.com', 'builtin.com', 'wellfound.com', 'startup.jobs', 'dailyremote.com', 'usajobs.gov',
    ];

    private const DEST_PARAMS = '/^(url|u|uri|redirect|redirect_url|redirect_uri|redir|target|dest|destination|to|next|link|goto|return|returnurl|continue)$/i';

    private const TRACK_LABEL = '/^(click|clk|track|trk|tracking|redirect|redir)$/i';

    private const TRACK_PATH = '/^(redirect|redir|click|clk|track|trk|clickthrough|jump|away)$/i';

    /**
     * @return array{class: string, platform: string|null, host: string|null, signals: list<string>}
     */
    public static function classify(mixed $candidate, ?string $listingUrl = null): array
    {
        $invalid = ['class' => 'invalid', 'platform' => null, 'host' => null, 'signals' => []];

        $url = InspectionUrl::acceptable($candidate);
        if ($url === null) {
            return $invalid;
        }
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $base = ['platform' => null, 'host' => $host, 'signals' => []];

        if (($parts['scheme'] ?? '') !== 'https') {
            return ['class' => 'insecure_http'] + $base;
        }
        if ($listingUrl !== null && InspectionUrl::comparable($url) === InspectionUrl::comparable($listingUrl)) {
            return ['class' => 'jobicy_listing'] + $base;
        }
        if (self::hostMatches($host, 'jobicy.com')) {
            return ['class' => 'jobicy_listing'] + $base;
        }

        $platform = self::platform($host);
        if ($platform === null) {
            $signals = self::redirectorSignals($url, $host);
            if ($signals !== [] || (self::hostMatches($host, 'adzuna.com') && str_starts_with($parts['path'] ?? '', '/land/ad/'))) {
                return ['class' => 'tracker_redirect', 'signals' => $signals] + $base;
            }
        }
        foreach (self::AGGREGATOR_HOSTS as $aggregator) {
            if (self::hostMatches($host, $aggregator)) {
                return ['class' => 'aggregator'] + $base;
            }
        }

        return $platform !== null
            ? ['class' => 'genuine_ats', 'platform' => $platform] + $base
            : ['class' => 'genuine_employer'] + $base;
    }

    private static function hostMatches(string $host, string $domain): bool
    {
        return $host === $domain || str_ends_with($host, '.'.$domain);
    }

    private static function platform(string $host): ?string
    {
        foreach (self::ATS_HOSTS as $domain => $platform) {
            if (self::hostMatches($host, $domain)) {
                return $platform;
            }
        }

        return null;
    }

    /** @return list<string> */
    private static function redirectorSignals(string $url, string $host): array
    {
        $signals = [];
        parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);
        foreach ($query as $key => $value) {
            if (is_string($value) && preg_match(self::DEST_PARAMS, (string) $key) === 1 && preg_match('~^https?://~i', $value) === 1) {
                $signals[] = 'destination_in_query';
                break;
            }
        }
        if (preg_match(self::TRACK_LABEL, explode('.', $host)[0]) === 1) {
            $signals[] = 'tracking_host_label';
        }
        $first = array_values(array_filter(explode('/', parse_url($url, PHP_URL_PATH) ?? ''), fn ($s) => $s !== ''))[0] ?? '';
        if (preg_match(self::TRACK_PATH, $first) === 1) {
            $signals[] = 'tracking_path';
        }

        return $signals;
    }
}
