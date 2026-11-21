<?php

namespace App\Support\JobFreshness;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Jobicy (documented): `GET /api/jobs/status?ids=...` (1 to 100 ids, free) answers `active`, `closed` or `unknown` per id; "mark a listing closed only on an explicit
 * `closed` result; treat `unknown` as unconfirmed." PURE: it interprets a recorded answer and makes no request.
 *
 * The documentation does NOT show the response body, so the parser accepts the two plausible shapes (`statuses: {id: status}` and `jobs|results: [{id, status}]`) and treats
 * anything else as uncertain ("unrecognised_shape"). That assumption is exactly what the read-only verification must settle.
 *
 *   closed -> closed (explicit)   active -> active   unknown / absent / anything else -> uncertain
 *   429 / 503 / 5xx / 400 / any other status / timeout -> uncertain (transient or unusable: never closure)
 */
final class JobicyStatusInterpreter
{
    /**
     * @param  array{http_status?: int|null, body?: array<string, mixed>|null, retry_after?: int|null}|null  $check
     */
    public function interpret(string|int $jobId, ?array $check, CarbonInterface $now): Observation
    {
        $now = CarbonImmutable::instance($now);
        $status = $check['http_status'] ?? null;
        $retry = is_int($check['retry_after'] ?? null) ? max(0, min($check['retry_after'], 3600)) : null;

        if (! is_int($status)) {
            return new Observation('jobicy', 'status_endpoint', Observation::UNCERTAIN, 'network_error', $now);
        }
        if ($status === 429 || $status === 503 || $status >= 500) {
            return new Observation('jobicy', 'status_endpoint', Observation::UNCERTAIN, $status === 429 ? 'rate_limited' : 'server_error', $now, null, $status, $retry);
        }
        if ($status !== 200) {
            return new Observation('jobicy', 'status_endpoint', Observation::UNCERTAIN, $status === 400 ? 'bad_request' : 'unexpected_status', $now, null, $status);
        }

        $reported = $this->statusFor((string) $jobId, $check['body'] ?? null);

        return match ($reported) {
            'closed' => new Observation('jobicy', 'status_endpoint', Observation::CLOSED, 'status_closed', $now, null, 200),
            'active' => new Observation('jobicy', 'status_endpoint', Observation::ACTIVE, 'status_active', $now, null, 200),
            'unknown' => new Observation('jobicy', 'status_endpoint', Observation::UNCERTAIN, 'status_unknown', $now, null, 200),
            false => new Observation('jobicy', 'status_endpoint', Observation::UNCERTAIN, 'unrecognised_shape', $now, null, 200),
            default => new Observation('jobicy', 'status_endpoint', Observation::UNCERTAIN, 'not_in_response', $now, null, 200),
        };
    }

    /** @return string|false|null a lower-cased status; false when the body's shape is not understood; null when the id is simply absent */
    private function statusFor(string $id, mixed $body): string|false|null
    {
        if (! is_array($body)) {
            return false;
        }
        $map = null;
        if (is_array($body['statuses'] ?? null)) {
            $map = $body['statuses'];
        } else {
            foreach (['jobs', 'results'] as $key) {
                if (is_array($body[$key] ?? null) && array_is_list($body[$key])) {
                    $map = [];
                    foreach ($body[$key] as $row) {
                        if (is_array($row) && isset($row['id'], $row['status']) && is_scalar($row['id'])) {
                            $map[(string) $row['id']] = $row['status'];
                        }
                    }
                    break;
                }
            }
        }
        if ($map === null) {
            return false;
        }
        $value = $map[$id] ?? null;
        if ($value === null) {
            return null;
        }

        return is_string($value) && in_array(strtolower($value), ['active', 'closed', 'unknown'], true) ? strtolower($value) : 'unknown';
    }
}
