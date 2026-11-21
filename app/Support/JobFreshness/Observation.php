<?php

namespace App\Support\JobFreshness;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * One provider-supported observation about one job, at one moment. It records WHAT was observed and why that counts as evidence; it never carries a URL,
 * a response body, a header or a credential. `uncertain` is the result of everything that is not a clear answer (404, unknown, a timeout, a rate limit): it
 * is never closure.
 *
 *   result:  active | expired | closed | uncertain
 *   source:  expiry_date (a provider-stated date, no request) | job_endpoint (Himalayas single-job check) | status_endpoint (Jobicy batch status)
 */
final readonly class Observation
{
    public const ACTIVE = 'active';

    public const EXPIRED = 'expired';

    public const CLOSED = 'closed';

    public const UNCERTAIN = 'uncertain';

    private const RESULTS = [self::ACTIVE, self::EXPIRED, self::CLOSED, self::UNCERTAIN];

    private const SOURCES = ['expiry_date', 'job_endpoint', 'status_endpoint'];

    private const PROVIDERS = ['himalayas', 'jobicy'];

    public function __construct(
        public string $provider,
        public string $source,
        public string $result,
        public string $basis,
        public CarbonImmutable $observedAt,
        public ?CarbonImmutable $expiresAt = null,
        public ?int $httpStatus = null,
        public ?int $retryAfterSeconds = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'provider' => $this->provider, 'source' => $this->source, 'result' => $this->result, 'basis' => $this->basis,
            'observed_at' => $this->observedAt->toIso8601String(),
            'expires_at' => $this->expiresAt?->toIso8601String(),
            'http_status' => $this->httpStatus, 'retry_after_seconds' => $this->retryAfterSeconds,
        ], fn ($v) => $v !== null);
    }

    /** Validates stored JSON: anything malformed is null, never a guess. */
    public static function fromArray(mixed $data): ?self
    {
        if (! is_array($data)
            || ! in_array($data['provider'] ?? null, self::PROVIDERS, true)
            || ! in_array($data['source'] ?? null, self::SOURCES, true)
            || ! in_array($data['result'] ?? null, self::RESULTS, true)
            || ! is_string($data['basis'] ?? null) || preg_match('/^[a-z0-9_]{1,40}$/', $data['basis']) !== 1) {
            return null;
        }

        try {
            $observedAt = CarbonImmutable::parse((string) ($data['observed_at'] ?? ''));
            $expiresAt = isset($data['expires_at']) ? CarbonImmutable::parse((string) $data['expires_at']) : null;
        } catch (Throwable) {
            return null;
        }

        return new self(
            $data['provider'], $data['source'], $data['result'], $data['basis'], $observedAt, $expiresAt,
            is_int($data['http_status'] ?? null) ? $data['http_status'] : null,
            is_int($data['retry_after_seconds'] ?? null) ? $data['retry_after_seconds'] : null,
        );
    }
}
