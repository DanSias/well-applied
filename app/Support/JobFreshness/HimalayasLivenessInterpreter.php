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

    private const SECONDS_MIN = 946684800;      // 2000-01-01

    private const SECONDS_MAX = 4102444800;     // 2100-01-01

    /**
     * The ONE reading of a provider `expiryDate`, as integer epoch SECONDS, or null (= unknown, never expired). Himalayas documents Unix seconds. Accepted, and nothing else:
     *   - an integer, or a string of digits, in the seconds range 2000-01-01 .. 2100-01-01 (taken as seconds);
     *   - an integer or digit string in the same range expressed in MILLISECONDS (13 digits; it cannot be read as seconds: that would be the year 33000+), divided by 1000;
     *   - an integral float in either range (JSON `1.795e9`);
     *   - an ISO-8601 date-time WITH an explicit offset (`Z` or `+hh:mm`), within the seconds range.
     * Everything else is unknown: zero, negatives, a number between the two ranges (ambiguous: neither seconds nor milliseconds), a date without a time or an offset (its
     * moment depends on a time zone nobody stated), words, arrays, booleans. "Unknown" never expires a job.
     */
    public static function normalizeExpiry(mixed $value): ?int
    {
        if (is_float($value)) {
            if (! is_finite($value) || floor($value) !== $value || abs($value) > 9.0e15) {
                return null;
            }
            $value = (int) $value;
        }
        if (is_string($value) && preg_match('/^\d{1,15}$/', $value) === 1) {
            $value = (int) $value;
        }
        if (is_int($value)) {
            return match (true) {
                $value >= self::SECONDS_MIN && $value <= self::SECONDS_MAX => $value,
                $value >= self::SECONDS_MIN * 1000 && $value <= self::SECONDS_MAX * 1000 => intdiv($value, 1000),
                default => null,
            };
        }
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2}(\.\d{1,9})?)?(Z|[+-]\d{2}:?\d{2})$/', trim($value)) === 1) {
            try {
                $t = CarbonImmutable::parse(trim($value))->getTimestamp();
            } catch (Throwable) {
                return null;
            }

            return $t >= self::SECONDS_MIN && $t <= self::SECONDS_MAX ? $t : null;
        }

        return null;
    }

    /** @see normalizeExpiry */
    public static function parseExpiry(mixed $value): ?CarbonImmutable
    {
        $seconds = self::normalizeExpiry($value);

        return $seconds === null ? null : CarbonImmutable::createFromTimestampUTC($seconds);
    }

    /**
     * What a STORED `discovery.expiryDate` may mean to the freshness surfaces: only an integer in the seconds range, because that is what ingestion now stores and what the
     * Inbox and Home SQL filters can compare. Any other stored form (a legacy string, milliseconds) is unknown to BOTH the filters and the badge, so they never disagree.
     */
    public static function storedSeconds(mixed $stored): ?int
    {
        return is_int($stored) && $stored >= self::SECONDS_MIN && $stored <= self::SECONDS_MAX ? $stored : null;
    }
}
