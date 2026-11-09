<?php

namespace App\Support\JobDiscovery\Providers\Eightfold;

use App\Support\JobDiscovery\Providers\DiscoveryProviderException;

/**
 * One configured Eightfold employer — see config/job_discovery.php
 * `eightfold.tenants`. Validated on construction so a typo fails loudly
 * instead of becoming a request to an arbitrary host.
 */
final readonly class EightfoldTenant
{
    /**
     * @param  array{pause_ms: int, max_search_requests: int, max_pages_per_query: int, max_detail_requests: int, max_attempts: int, backoff_seconds: int, max_retry_after_seconds: int, timeout_seconds: int, detail_cache_hours: int}  $budget
     * @param  array{field: string, currency: string, interval: string}|null  $salary  Only where the field's semantics are known.
     */
    public function __construct(
        public string $slug,
        public string $company,
        public string $host,
        public string $domain,
        public bool $enabled,
        public array $budget,
        public ?array $salary = null,
        public ?string $remoteLocationHint = null,
    ) {}

    /**
     * Every configured tenant (enabled or not), in config order.
     *
     * @return EightfoldTenant[]
     *
     * @throws DiscoveryProviderException on malformed configuration.
     */
    public static function configured(): array
    {
        $tenants = config('job_discovery.eightfold.tenants', []);
        $defaults = config('job_discovery.eightfold.budget', []);

        if (! is_array($tenants) || ! is_array($defaults)) {
            throw new DiscoveryProviderException('job_discovery.eightfold.tenants and .budget must be arrays.');
        }

        $result = [];
        foreach ($tenants as $slug => $entry) {
            $result[] = self::fromEntry($slug, $entry, $defaults);
        }

        return $result;
    }

    /**
     * @return EightfoldTenant[]
     */
    public static function enabled(): array
    {
        return array_values(array_filter(self::configured(), fn (self $t) => $t->enabled));
    }

    /**
     * Cheap, non-throwing check used when deciding whether to register the provider.
     */
    public static function anyEnabled(): bool
    {
        $tenants = config('job_discovery.eightfold.tenants', []);

        if (! is_array($tenants)) {
            return false;
        }

        foreach ($tenants as $entry) {
            if (is_array($entry) && ($entry['enabled'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<array-key, mixed>  $defaults
     */
    private static function fromEntry(mixed $slug, mixed $entry, array $defaults): self
    {
        if (! is_string($slug) || preg_match('/^[a-z0-9][a-z0-9_-]*$/', $slug) !== 1) {
            throw new DiscoveryProviderException('Eightfold tenant slugs must be lowercase letters, digits, "-" or "_".');
        }

        if (! is_array($entry)) {
            throw new DiscoveryProviderException("Eightfold tenant \"{$slug}\" must be an array.");
        }

        foreach (['company', 'host', 'domain'] as $field) {
            if (! is_string($entry[$field] ?? null) || trim($entry[$field]) === '') {
                throw new DiscoveryProviderException("Eightfold tenant \"{$slug}\" is missing a non-empty \"{$field}\".");
            }
        }

        $host = strtolower(trim($entry['host']));
        if (preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $host) !== 1 || ! str_contains($host, '.')) {
            throw new DiscoveryProviderException("Eightfold tenant \"{$slug}\" has an invalid host; expected a bare hostname.");
        }

        $overrides = is_array($entry['budget'] ?? null) ? $entry['budget'] : [];
        $budget = [];
        foreach (['pause_ms', 'max_search_requests', 'max_pages_per_query', 'max_detail_requests', 'max_attempts', 'backoff_seconds', 'max_retry_after_seconds', 'timeout_seconds', 'detail_cache_hours'] as $key) {
            $value = $overrides[$key] ?? $defaults[$key] ?? null;
            if (! is_int($value) || $value < 0) {
                throw new DiscoveryProviderException("Eightfold tenant \"{$slug}\" has no valid integer budget \"{$key}\".");
            }
            $budget[$key] = $value;
        }
        $budget['max_attempts'] = max(1, $budget['max_attempts']);

        $salary = null;
        if (isset($entry['salary'])) {
            $s = $entry['salary'];
            if (! is_array($s) || ! is_string($s['field'] ?? null) || ! is_string($s['currency'] ?? null) || ! is_string($s['interval'] ?? null)) {
                throw new DiscoveryProviderException("Eightfold tenant \"{$slug}\" has a malformed salary mapping.");
            }
            $salary = ['field' => $s['field'], 'currency' => $s['currency'], 'interval' => $s['interval']];
        }

        $hint = array_key_exists('remote_location_hint', $entry)
            ? $entry['remote_location_hint']
            : config('job_discovery.eightfold.remote_location_hint');
        if ($hint !== null && (! is_string($hint) || trim($hint) === '')) {
            throw new DiscoveryProviderException("Eightfold tenant \"{$slug}\" has an invalid remote_location_hint.");
        }

        return new self(
            slug: $slug,
            company: trim($entry['company']),
            host: $host,
            domain: strtolower(trim($entry['domain'])),
            enabled: ($entry['enabled'] ?? false) === true,
            budget: $budget,
            salary: $salary,
            remoteLocationHint: $hint === null ? null : trim($hint),
        );
    }
}
