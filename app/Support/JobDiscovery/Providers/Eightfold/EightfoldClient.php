<?php

namespace App\Support\JobDiscovery\Providers\Eightfold;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * Paced, bounded HTTP access to ONE tenant's public Eightfold JSON API.
 * Retries only what is plausibly transient (429, 408, 5xx, connection
 * errors), honours Retry-After (and refuses to wait past a ceiling), and
 * never retries 403 / tenant-disabled / bad-domain / non-JSON responses.
 */
final class EightfoldClient
{
    private const USER_AGENT = 'career-toolkit-discovery/1.0 (personal job search; low-volume)';

    private const RETRYABLE = [408, 500, 502, 503, 504];

    private int $requests = 0;

    private int $retries = 0;

    private int $rateLimited = 0;

    public function __construct(private readonly EightfoldTenant $tenant) {}

    /**
     * @return array{positions: array<int, array<string, mixed>>, count: int|null}
     *
     * @throws EightfoldRequestException
     */
    public function search(string $query, int $start): array
    {
        $data = $this->get('/api/pcsx/search', [
            'domain' => $this->tenant->domain,
            'query' => $query,
            'start' => $start,
            'sort_by' => 'relevance',
        ]);

        $positions = $data['positions'] ?? null;
        if (! is_array($positions) || ! array_is_list($positions)) {
            throw new EightfoldRequestException(EightfoldRequestException::INVALID_RESPONSE, 'Search response had no positions list.');
        }

        return [
            'positions' => array_values(array_filter($positions, 'is_array')),
            'count' => is_int($data['count'] ?? null) ? $data['count'] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws EightfoldRequestException
     */
    public function details(string|int $positionId): array
    {
        $data = $this->get('/api/pcsx/position_details', [
            'position_id' => (string) $positionId,
            'domain' => $this->tenant->domain,
            'hl' => 'en',
        ]);

        if (! isset($data['id']) || ! array_key_exists('jobDescription', $data)) {
            throw new EightfoldRequestException(EightfoldRequestException::INVALID_RESPONSE, 'Position details response was missing id or jobDescription.');
        }

        return $data;
    }

    /**
     * @return array{requests: int, retries: int, rate_limited: int}
     */
    public function stats(): array
    {
        return ['requests' => $this->requests, 'retries' => $this->retries, 'rate_limited' => $this->rateLimited];
    }

    /**
     * @param  array<string, scalar>  $params
     * @return array<string, mixed> The response's `data` object.
     */
    private function get(string $path, array $params): array
    {
        $budget = $this->tenant->budget;
        $url = "https://{$this->tenant->host}{$path}";

        for ($attempt = 1; ; $attempt++) {
            if ($this->requests > 0) {
                Sleep::for($budget['pause_ms'])->milliseconds();
            }
            $this->requests++;

            $retryAfter = null;
            try {
                $response = Http::withHeaders(['User-Agent' => self::USER_AGENT, 'Accept' => 'application/json'])
                    ->timeout($budget['timeout_seconds'])
                    ->get($url, $params);
            } catch (ConnectionException) {
                $failure = new EightfoldRequestException(EightfoldRequestException::CONNECTION, 'Connection to the tenant failed.');
                $response = null;
            }

            if ($response !== null) {
                if ($response->successful()) {
                    return $this->decode($response);
                }

                $status = $response->status();
                $retryAfter = $this->retryAfterSeconds($response);
                $failure = $this->classify($response, $status);

                if ($status === 429) {
                    $this->rateLimited++;
                }

                if (! ($status === 429 || in_array($status, self::RETRYABLE, true))) {
                    throw $failure;
                }
            }

            if ($attempt >= $budget['max_attempts']) {
                throw new EightfoldRequestException(
                    $failure->category,
                    "{$failure->getMessage()} Gave up after {$attempt} attempt".($attempt === 1 ? '' : 's').'.',
                    $failure->status,
                );
            }

            if ($retryAfter !== null && $retryAfter > $budget['max_retry_after_seconds']) {
                throw new EightfoldRequestException(
                    $failure->category,
                    "{$failure->getMessage()} Retry-After of {$retryAfter}s exceeds the {$budget['max_retry_after_seconds']}s ceiling; not waiting.",
                    $failure->status,
                );
            }

            $this->retries++;
            Sleep::for($retryAfter ?? $budget['backoff_seconds'] * (2 ** ($attempt - 1)))->seconds();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        $contentType = strtolower($response->header('Content-Type'));
        if (! str_contains($contentType, 'json')) {
            // A wrong or unknown `domain` returns Eightfold's HTML app shell with a 200.
            throw new EightfoldRequestException(
                EightfoldRequestException::INVALID_RESPONSE,
                'Expected JSON but received content type "'.($contentType === '' ? 'none' : substr($contentType, 0, 60)).'" (is the domain correct?).',
                $response->status(),
            );
        }

        $json = $response->json();
        $data = is_array($json) ? ($json['data'] ?? null) : null;

        if (! is_array($data)) {
            throw new EightfoldRequestException(EightfoldRequestException::INVALID_RESPONSE, 'JSON response had no data object.', $response->status());
        }

        return $data;
    }

    private function classify(Response $response, int $status): EightfoldRequestException
    {
        $message = "HTTP {$status}";

        return match (true) {
            $status === 429 => new EightfoldRequestException(EightfoldRequestException::RATE_LIMITED, "{$message} (rate limited).", $status),
            $status === 401, $status === 403 => str_contains(strtolower($response->body()), 'not enabled')
                ? new EightfoldRequestException(EightfoldRequestException::TENANT_DISABLED, "{$message}: the public API is not enabled for this tenant.", $status)
                : new EightfoldRequestException(EightfoldRequestException::FORBIDDEN, "{$message}: access denied.", $status),
            $status === 422 => new EightfoldRequestException(EightfoldRequestException::DOMAIN_REJECTED, "{$message}: the tenant rejected the request parameters (check the domain).", $status),
            $status === 404 => new EightfoldRequestException(EightfoldRequestException::NOT_FOUND, "{$message}: not found.", $status),
            $status >= 500 || $status === 408 => new EightfoldRequestException(EightfoldRequestException::SERVER_ERROR, "{$message} (server error).", $status),
            default => new EightfoldRequestException(EightfoldRequestException::CLIENT_ERROR, "{$message}.", $status),
        };
    }

    private function retryAfterSeconds(Response $response): ?int
    {
        $value = trim($response->header('Retry-After'));
        if ($value === '') {
            return null;
        }

        if (ctype_digit($value)) {
            return (int) $value;
        }

        $at = strtotime($value);

        return $at === false ? null : max(0, $at - time());
    }
}
