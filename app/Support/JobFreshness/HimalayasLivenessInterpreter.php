<?php

namespace App\Support\JobFreshness;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Throwable;

/**
 * Himalayas (documented): `expiryDate` on each job, and `GET /jobs/api/companies/:company/jobs/:slug` which answers 200 (open), 410 (closed, with `expiryDate`) or
 * 404 (not found), cached up to ten minutes. PURE: it interprets what it is handed; it makes no request.
 *
 *   a stored or reported expiryDate at or before now         -> expired  (known; no request needed; wins over any "open" answer: the API cache can lag)
 *   410                                                      -> closed   (explicit)
 *   200                                                      -> active
 *   404                                                      -> uncertain (not found is not closed: a slug can change)
 *   429 / 5xx / unexpected status / timeout / connection     -> uncertain (transient: never closure)
 *
 * It never touches the job's `applicationLink` (the canonical Apply target) or its attribution: observations carry no URL.
 */
final class HimalayasLivenessInterpreter
{
    /**
     * @param  array{http_status?: int|null, body?: array<string, mixed>|null, error?: string|null, retry_after?: int|null}|null  $check  a recorded endpoint answer, or null when none was made
     * @return list<Observation>
     */
    public function interpret(mixed $storedExpiry, ?array $check, CarbonInterface $now): array
    {
        $now = CarbonImmutable::instance($now);
        $out = [];

        $stored = self::parseExpiry($storedExpiry);
        if ($stored !== null && $stored->lessThanOrEqualTo($now)) {
            $out[] = new Observation('himalayas', 'expiry_date', Observation::EXPIRED, 'expiry_date_passed', $now, $stored);
        }

        if ($check === null) {
            return $out;
        }

        $status = $check['http_status'] ?? null;
        $retry = is_int($check['retry_after'] ?? null) ? max(0, min($check['retry_after'], 3600)) : null;
        $reported = self::parseExpiry(is_array($check['body'] ?? null) ? ($check['body']['expiryDate'] ?? null) : null);

        if (! is_int($status)) {
            $out[] = new Observation('himalayas', 'job_endpoint', Observation::UNCERTAIN, 'network_error', $now);

            return $out;
        }

        $out[] = match (true) {
            $status === 410 => new Observation('himalayas', 'job_endpoint', Observation::CLOSED, 'http_410', $now, $reported, 410),
            $status === 200 => new Observation('himalayas', 'job_endpoint', Observation::ACTIVE, 'http_200', $now, $reported, 200),
            $status === 404 => new Observation('himalayas', 'job_endpoint', Observation::UNCERTAIN, 'http_404', $now, null, 404),
            $status === 429 => new Observation('himalayas', 'job_endpoint', Observation::UNCERTAIN, 'rate_limited', $now, null, 429, $retry),
            $status >= 500 => new Observation('himalayas', 'job_endpoint', Observation::UNCERTAIN, 'server_error', $now, null, $status, $retry),
            default => new Observation('himalayas', 'job_endpoint', Observation::UNCERTAIN, 'unexpected_status', $now, null, $status),
        };

        // An expiry the endpoint itself reports is as known as a stored one, even on a 200 (a cached answer can lag the date).
        if ($reported !== null && $reported->lessThanOrEqualTo($now) && $stored === null) {
            $out[] = new Observation('himalayas', 'expiry_date', Observation::EXPIRED, 'reported_expiry_passed', $now, $reported);
        }

        return $out;
    }

    /** Unix seconds (as the feed gives them) or an ISO date; anything else is no expiry at all. */
    public static function parseExpiry(mixed $value): ?CarbonImmutable
    {
        try {
            if (is_int($value) || (is_string($value) && ctype_digit($value))) {
                $n = (int) $value;

                return $n > 946684800 && $n < 4102444800 ? CarbonImmutable::createFromTimestampUTC($n) : null;
            }

            return is_string($value) && trim($value) !== '' ? CarbonImmutable::parse($value) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
