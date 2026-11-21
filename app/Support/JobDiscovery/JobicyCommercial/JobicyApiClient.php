<?php

namespace App\Support\JobDiscovery\JobicyCommercial;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * An ISOLATED client for Jobicy's jobs endpoint, free tier and Commercial tier. It is not the discovery provider and shares nothing with it
 * (App\Support\JobDiscovery\Providers\JobicyDiscoveryProvider is untouched and still calls its own endpoint).
 *
 * Documented request structure (https://jobicy.com/jobs-rss-feed, read 2026-11-22; NOT yet exercised against the live service):
 *   GET https://jobicy.com/api/jobs?count=1..100&geo=...&cursor=...   (the same URL serves both tiers)
 *   Commercial tier: header `Authorization: Bearer <key>` on every request; the free tier sends NO credential at all.
 *   Response: {success, statusCode, jobs[], jobCount, nextCursor, hasMore, lastUpdate, appliedFilters, apiVersion, ...}; Commercial responses add
 *   `total_request_cost` (USD for the page) and per-job `job_request_cost`.
 *   Errors documented: 400 (`success:false`, `error`), 429 (`Retry-After`), 503 (`Retry-After`). NOT documented: the status for a missing or invalid key and for an
 *   insufficient wallet. 401/403 are treated as an authorization failure and 402 as a balance failure by ASSUMPTION; any other 4xx stops as a client error.
 *
 * Guarantees: no automatic retry and no sleeping (a 429/503 is surfaced with its Retry-After, clamped, and the caller stops); the key comes only from the
 * constructor (configuration), is sent only as the Authorization header of a commercial request, and can not appear in a message, a log line or a report.
 */
final class JobicyApiClient
{
    public const TIMEOUT_SECONDS = 30;

    public const MAX_RETRY_AFTER_SECONDS = 3600;

    public function __construct(private readonly string $baseUrl, #[\SensitiveParameter] private readonly ?string $apiKey) {}

    public static function fromConfig(): self
    {
        $key = config('services.jobicy_commercial.api_key');

        return new self((string) config('services.jobicy_commercial.base_url'), is_string($key) && trim($key) !== '' ? trim($key) : null);
    }

    public function hasKey(): bool
    {
        return $this->apiKey !== null;
    }

    /**
     * Fetches ONE page. `$tier` is 'public' (never authenticated) or 'commercial' (needs the key).
     *
     * @param  array<string, scalar>  $filters
     * @return array<string, mixed> the decoded response body
     *
     * @throws JobicyApiException
     */
    public function page(string $tier, int $count, array $filters = [], ?string $cursor = null): array
    {
        if (! in_array($tier, ['public', 'commercial'], true) || $count < 1 || $count > 100) {
            throw new JobicyApiException(JobicyApiException::BAD_REQUEST);
        }
        if ($tier === 'commercial' && $this->apiKey === null) {
            throw new JobicyApiException(JobicyApiException::NOT_CONFIGURED);
        }

        $request = Http::timeout(self::TIMEOUT_SECONDS)->acceptJson();
        if ($tier === 'commercial') {
            $request = $request->withToken($this->apiKey);
        }

        try {
            $response = $request->get($this->baseUrl, array_filter(['count' => $count, 'cursor' => $cursor] + $filters, fn ($v) => $v !== null));
        } catch (ConnectionException) {
            throw new JobicyApiException(JobicyApiException::CONNECTION_FAILED);
        }

        $status = $response->status();
        $retryAfter = self::retryAfter($response->header('Retry-After'));

        if ($status === 429) {
            throw new JobicyApiException(JobicyApiException::RATE_LIMITED, $status, $retryAfter);
        }
        if ($status === 503) {
            throw new JobicyApiException(JobicyApiException::SERVICE_UNAVAILABLE, $status, $retryAfter);
        }
        if (in_array($status, [401, 403], true)) {
            throw new JobicyApiException(JobicyApiException::AUTH_FAILED, $status);
        }
        if ($status === 402) {
            throw new JobicyApiException(JobicyApiException::INSUFFICIENT_BALANCE, $status);
        }
        if ($status === 400) {
            throw new JobicyApiException(JobicyApiException::BAD_REQUEST, $status);
        }
        if ($status >= 500) {
            throw new JobicyApiException(JobicyApiException::SERVER_ERROR, $status);
        }
        if ($status >= 400 || $status < 200) {
            throw new JobicyApiException(JobicyApiException::CLIENT_ERROR, $status);
        }

        try {
            $body = $response->json();
        } catch (Throwable) {
            $body = null;
        }

        if (! is_array($body)) {
            throw new JobicyApiException(JobicyApiException::MALFORMED_RESPONSE, $status);
        }
        // An error can also arrive inside a 200 envelope (`success: false`).
        if (($body['success'] ?? true) === false) {
            $inner = is_int($body['statusCode'] ?? null) ? $body['statusCode'] : null;

            throw new JobicyApiException(match (true) {
                in_array($inner, [401, 403], true) => JobicyApiException::AUTH_FAILED,
                $inner === 402 => JobicyApiException::INSUFFICIENT_BALANCE,
                $inner === 429 => JobicyApiException::RATE_LIMITED,
                default => JobicyApiException::BAD_REQUEST,
            }, $inner ?? $status, $inner === 429 ? $retryAfter : null);
        }
        if (! is_array($body['jobs'] ?? null)) {
            throw new JobicyApiException(JobicyApiException::MALFORMED_RESPONSE, $status);
        }

        return $body;
    }

    /** Seconds from a numeric header or an HTTP date, clamped to 0..3600; null when absent or unreadable. */
    public static function retryAfter(?string $header): ?int
    {
        $header = $header === null ? '' : trim($header);
        if ($header === '') {
            return null;
        }
        if (ctype_digit($header)) {
            return min((int) $header, self::MAX_RETRY_AFTER_SECONDS);
        }
        try {
            $seconds = (int) now()->diffInSeconds(Carbon::parse($header), false);
        } catch (Throwable) {
            return null;
        }

        return max(0, min($seconds, self::MAX_RETRY_AFTER_SECONDS));
    }
}
