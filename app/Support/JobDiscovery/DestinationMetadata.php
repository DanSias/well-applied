<?php

namespace App\Support\JobDiscovery;

use App\Enums\JobDiscoverySource;
use App\Models\JobPosting;
use App\Support\ApplicationInspection\InspectionUrl;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Throwable;

/**
 * The API-first destination and attribution contract (v1), stored in `job_postings.discovery_metadata.discovery`. No column, no migration.
 *
 *   destinations[]  CANDIDATE destinations a provider's API supplied, separately from the listing URL. A candidate is an INPUT:
 *                   it may seed an inspection's start URL, and it is never the Application URL, never "verified" and never
 *                   user-confirmed. The Application URL (applications.application_url, origin user|resolved) is set only by a person or by an
 *                   inspection that reached a form. A provider cannot claim otherwise: an entry whose verification is anything but
 *                   "unverified" is rejected, not downgraded.
 *   attribution     the credit the provider's terms require and whether its Apply link is mandated. Built from the registry
 *                   (ProviderAttribution), never from provider data, so a provider response cannot weaken it.
 *
 * `job_postings.source_url` is untouched by any of this: it stays exactly what the provider supplied (for Himalayas, its applicationLink,
 * byte for byte).
 *
 *   destination = {
 *     role: "candidate_destination", url, source: <discovery source>,
 *     provenance: {origin: "provider_api", field: <API field>, tier: "public"|"commercial"},
 *     evidence:   {kind: "api_field", index?: int},
 *     verification: {status: "unverified"},
 *     usage: {restrictions: [<code>...], may_replace_apply_link: false},
 *     retrieved_at: ISO 8601
 *   }
 *   attribution = {
 *     provider, name, home_url, canonical_url (the provider's own link, as supplied), required: true,
 *     restrictions: [<code>...], apply_link: {url, mandated: bool, policy: <code>}
 *   }
 */
final class DestinationMetadata
{
    public const ROLE = 'candidate_destination';

    public const MAX_DESTINATIONS = 5;

    private const TIERS = ['public', 'commercial'];

    /** A new provider adds itself here when its API supplies a destination. The legacy USAJOBS `applyUris` list is read for it too. */
    private const MAY_SUPPLY_DESTINATIONS = [JobDiscoverySource::Usajobs, JobDiscoverySource::Jobicy, JobDiscoverySource::Eightfold];

    /**
     * Builds one candidate entry for a provider to put in its sourceMetadata['destinations']. It is validated again on ingestion.
     *
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>
     */
    public static function destination(JobDiscoverySource $source, string $url, string $field, string $tier = 'public', array $evidence = [], ?CarbonInterface $retrievedAt = null): array
    {
        return [
            'role' => self::ROLE,
            'url' => $url,
            'source' => $source->value,
            'provenance' => ['origin' => 'provider_api', 'field' => $field, 'tier' => $tier],
            'evidence' => $evidence === [] ? ['kind' => 'api_field'] : $evidence,
            'verification' => ['status' => 'unverified'],
            'usage' => ['restrictions' => self::restrictionsFor($source), 'may_replace_apply_link' => false],
            'retrieved_at' => ($retrievedAt ?? now())->toIso8601String(),
        ];
    }

    /**
     * The `discovery` metadata to persist for a candidate: its own keys, with `destinations` validated and `attribution` rebuilt from the
     * registry. Provider-supplied `attribution` is ignored; malformed or over-claiming destinations are dropped.
     *
     * @return array<string, mixed>
     */
    public static function sanitize(DiscoveredJobCandidate $candidate): array
    {
        $discovery = $candidate->sourceMetadata;
        $supplied = $discovery['destinations'] ?? null;
        unset($discovery['destinations'], $discovery['attribution']);

        $destinations = self::normalizeList($supplied, $candidate->source, $candidate->applicationUrl);
        if ($destinations !== []) {
            $discovery['destinations'] = $destinations;
        }

        $attribution = self::attribution($candidate->source, $candidate->applicationUrl);
        if ($attribution !== null) {
            $discovery['attribution'] = $attribution;
        }

        return $discovery;
    }

    /**
     * @return array<string, mixed>|null null for a source with no attribution rules
     */
    public static function attribution(JobDiscoverySource $source, ?string $providerLink): ?array
    {
        $rules = ProviderAttribution::rules($source);
        if ($rules === null) {
            return null;
        }

        // The provider's own link exactly as supplied (it must be preserved unchanged), kept only if it is a usable URL at all.
        $link = $providerLink !== null && InspectionUrl::acceptable($providerLink) !== null ? $providerLink : null;

        return [
            'provider' => $rules['provider'],
            'name' => $rules['name'],
            'home_url' => $rules['home_url'],
            'canonical_url' => $link,
            'required' => true,
            'restrictions' => $rules['restrictions'],
            'apply_link' => ['url' => $link, 'mandated' => $rules['apply_link_mandated'], 'policy' => $rules['apply_link_policy']],
        ];
    }

    /**
     * What is stored on a job, validated again (the stored JSON is data, never trusted): the candidate destinations, and the attribution if recorded.
     * Legacy USAJOBS `applyUris` are presented as candidates too, so consumers have one reader.
     *
     * @return array{destinations: list<array<string, mixed>>, attribution: array<string, mixed>|null}
     */
    public static function read(JobPosting $job): array
    {
        $discovery = is_array($job->discovery_metadata['discovery'] ?? null) ? $job->discovery_metadata['discovery'] : [];
        $listing = $job->source_url;

        $destinations = self::normalizeList($discovery['destinations'] ?? null, $job->discovery_source, $listing);

        if ($destinations === [] && $job->discovery_source === JobDiscoverySource::Usajobs && is_array($discovery['applyUris'] ?? null)) {
            $legacy = [];
            foreach (array_values($discovery['applyUris']) as $i => $uri) {
                if (is_string($uri)) {
                    $legacy[] = self::destination($job->discovery_source, $uri, 'ApplyURI', 'public', ['kind' => 'api_field', 'index' => $i, 'legacy' => true], $job->discovered_at ? CarbonImmutable::instance($job->discovered_at) : null);
                }
            }
            $destinations = self::normalizeList($legacy, $job->discovery_source, $listing);
        }

        $attribution = self::attribution($job->discovery_source, $discovery['attribution']['canonical_url'] ?? $listing);

        return ['destinations' => $destinations, 'attribution' => $attribution];
    }

    /** Whether the provider's own Apply link must be used unchanged for this job (Himalayas). Candidates may never replace it. */
    public static function applyLinkMandated(JobPosting $job): bool
    {
        return ProviderAttribution::rules($job->discovery_source)['apply_link_mandated'] ?? false;
    }

    /**
     * A safe view for the UI and the inspection drawer: URLs reduced to origin and path, no evidence internals.
     *
     * @return list<array{url: string, source: string, tier: string, field: string, verification: string}>
     */
    public static function view(JobPosting $job): array
    {
        return array_map(fn (array $d) => [
            'url' => (string) InspectionUrl::redact($d['url']),
            'source' => $d['source'],
            'tier' => $d['provenance']['tier'],
            'field' => $d['provenance']['field'],
            'verification' => $d['verification']['status'],
        ], self::read($job)['destinations']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function normalizeList(mixed $entries, JobDiscoverySource $source, ?string $listingUrl): array
    {
        if (! is_array($entries) || ! in_array($source, self::MAY_SUPPLY_DESTINATIONS, true)) {
            return [];
        }

        $out = [];
        $seen = [];
        foreach (array_slice(array_values($entries), 0, self::MAX_DESTINATIONS * 2) as $entry) {
            $clean = self::normalizeEntry($entry, $source);
            if ($clean === null) {
                continue;
            }
            $key = InspectionUrl::comparable($clean['url']);
            // A candidate must be a DIFFERENT place from the provider's own listing, and each place appears once.
            if (($listingUrl !== null && $key === InspectionUrl::comparable($listingUrl)) || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $clean;
            if (count($out) === self::MAX_DESTINATIONS) {
                break;
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function normalizeEntry(mixed $entry, JobDiscoverySource $source): ?array
    {
        if (! is_array($entry)) {
            return null;
        }

        $url = InspectionUrl::acceptable($entry['url'] ?? null);
        $tier = $entry['provenance']['tier'] ?? 'public';
        $field = $entry['provenance']['field'] ?? null;

        if ($url === null
            || ($entry['role'] ?? null) !== self::ROLE
            // The entry must be for THIS job's provider: stored JSON cannot lend one provider's candidate to another.
            || ($entry['source'] ?? null) !== $source->value
            || ($entry['provenance']['origin'] ?? null) !== 'provider_api'
            || ! in_array($tier, self::TIERS, true)
            || ! is_string($field) || $field === '' || strlen($field) > 40
            // A provider cannot confirm a destination. Anything other than "unverified" is rejected outright.
            || ($entry['verification']['status'] ?? 'unverified') !== 'unverified') {
            return null;
        }

        $evidence = ['kind' => 'api_field'];
        if (is_int($entry['evidence']['index'] ?? null) && $entry['evidence']['index'] >= 0 && $entry['evidence']['index'] < 100) {
            $evidence['index'] = $entry['evidence']['index'];
        }
        if (($entry['evidence']['legacy'] ?? null) === true) {
            $evidence['legacy'] = true;
        }

        try {
            $retrievedAt = CarbonImmutable::parse((string) ($entry['retrieved_at'] ?? ''))->toIso8601String();
        } catch (Throwable) {
            $retrievedAt = now()->toIso8601String();
        }

        return [
            'role' => self::ROLE,
            'url' => $url,
            'source' => $source->value,
            'provenance' => ['origin' => 'provider_api', 'field' => $field, 'tier' => $tier],
            'evidence' => $evidence,
            'verification' => ['status' => 'unverified'],
            // The restrictions are the registry's, not the entry's; a candidate can never be marked as allowed to replace an Apply link.
            'usage' => ['restrictions' => self::restrictionsFor($source), 'may_replace_apply_link' => false],
            'retrieved_at' => $retrievedAt,
        ];
    }

    /** @return list<string> */
    private static function restrictionsFor(JobDiscoverySource $source): array
    {
        return ProviderAttribution::rules($source)['restrictions'] ?? [];
    }
}
