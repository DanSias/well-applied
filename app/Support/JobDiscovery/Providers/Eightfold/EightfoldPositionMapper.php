<?php

namespace App\Support\JobDiscovery\Providers\Eightfold;

use App\Enums\DescriptionCompleteness;
use App\Enums\JobDiscoverySource;
use App\Enums\JobRemoteStatus;
use App\Support\JobDiscovery\DiscoveredJobCandidate;
use App\Support\JobDiscovery\SearchProfile;
use App\Support\JobDiscovery\TitleFamilies;
use Carbon\CarbonImmutable;

/**
 * Interprets Eightfold's per-position data conservatively.
 *
 * `workLocationOption` is NOT a global remote flag: `remote_local` only means
 * "remote relative to the listed locations", and a listing can be an
 * India-only remote role. So arrangement is always read together with the
 * position's listed locations, and anything the data does not say is left
 * unknown rather than guessed.
 */
final class EightfoldPositionMapper
{
    private const US_STATES = [
        'AL', 'AK', 'AZ', 'AR', 'CA', 'CO', 'CT', 'DE', 'DC', 'FL', 'GA', 'HI', 'ID', 'IL', 'IN', 'IA', 'KS', 'KY', 'LA', 'ME', 'MD',
        'MA', 'MI', 'MN', 'MS', 'MO', 'MT', 'NE', 'NV', 'NH', 'NJ', 'NM', 'NY', 'NC', 'ND', 'OH', 'OK', 'OR', 'PA', 'RI', 'SC', 'SD',
        'TN', 'TX', 'UT', 'VT', 'VA', 'WA', 'WV', 'WI', 'WY', 'PR',
    ];

    private const MAX_LOCATIONS_SHOWN = 5;

    private const MAX_CUSTOM_FIELDS = 25;

    /**
     * Decide from a search row alone whether a position is worth a
     * description fetch. Returns null to keep it, else the drop reason.
     * Never rejects on missing information.
     *
     * @param  array<string, mixed>  $row
     * @param  string[]  $excludedTitleKeywords  The discovery criteria's title-only role exclusions.
     */
    public function dropReason(array $row, SearchProfile $profile, array $excludedTitleKeywords = []): ?string
    {
        $title = is_string($row['name'] ?? null) ? $row['name'] : '';

        if (! is_scalar($row['id'] ?? null) || $title === '') {
            return 'malformed_row';
        }

        if (! $this->matchesAny($title, $profile->includeTerms) && ! TitleFamilies::matches($title)) {
            return 'title_not_matching';
        }

        if ($this->matchesAny($title, $profile->excludeTerms)) {
            return 'excluded_term';
        }

        if ($this->matchesAny($title, $excludedTitleKeywords)) {
            return 'excluded_title_role';
        }

        $geo = $this->geography($row);

        if ($this->requiresUs($profile) && $geo['locations'] !== [] && $geo['us'] === [] && $geo['unknown'] === []) {
            return 'non_us';
        }

        if ($profile->remotePreference === 'remote_us') {
            $arrangement = $row['workLocationOption'] ?? null;
            // On-site is out; hybrid stays only when Florida is a listed location, matching the global filter's policy.
            if ($arrangement === 'onsite' || ($arrangement === 'hybrid' && ! in_array('FL', $geo['states'], true))) {
                return 'work_arrangement';
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row  The search row.
     * @param  array<string, mixed>  $detail  The (possibly cached) position details.
     */
    public function toCandidate(EightfoldTenant $tenant, array $row, array $detail, CarbonImmutable $now): ?DiscoveredJobCandidate
    {
        $id = $detail['id'] ?? $row['id'] ?? null;
        $title = $detail['name'] ?? $row['name'] ?? null;

        if (! is_scalar($id) || ! is_string($title) || trim($title) === '') {
            return null;
        }

        $merged = array_merge($row, array_filter($detail, fn ($v) => $v !== null));
        $geo = $this->geography($merged);
        $arrangement = is_string($merged['workLocationOption'] ?? null) ? $merged['workLocationOption'] : null;
        $description = is_string($detail['jobDescription'] ?? null) ? $this->htmlToPlainText($detail['jobDescription']) : null;

        [$min, $max, $currency, $interval, $salaryNote] = $this->salary($tenant, $merged, $geo, $description);

        return new DiscoveredJobCandidate(
            source: JobDiscoverySource::Eightfold,
            sourceJobId: $tenant->slug.':'.(string) $id,
            company: $tenant->company,
            title: html_entity_decode(trim($title), ENT_QUOTES | ENT_HTML5),
            description: $description === '' ? null : $description,
            descriptionCompleteness: DescriptionCompleteness::Complete,
            location: $this->locationText($geo),
            remoteStatus: $this->remoteStatus($arrangement, $geo),
            employmentType: null,
            compensationMin: $min,
            compensationMax: $max,
            compensationCurrency: $currency,
            compensationInterval: $interval,
            applicationUrl: $this->publicUrl($detail),
            postedAt: $this->timestamp($merged['postedTs'] ?? null),
            sourceUpdatedAt: null,
            discoveredAt: $now,
            sourceMetadata: array_filter([
                'tenant' => $tenant->slug,
                'host' => $tenant->host,
                'positionId' => (string) $id,
                'displayJobId' => is_scalar($merged['displayJobId'] ?? null) ? (string) $merged['displayJobId'] : null,
                'department' => is_string($merged['department'] ?? null) ? $merged['department'] : null,
                'workLocationOption' => $arrangement,
                'locationFlexibility' => is_string($merged['locationFlexibility'] ?? null) ? $merged['locationFlexibility'] : null,
                'locations' => array_slice($geo['locations'], 0, 20),
                'remoteEligibility' => $this->remoteEligibility($arrangement, $geo),
                'listedStates' => $geo['states'],
                'floridaListed' => in_array('FL', $geo['states'], true),
                'createdAt' => $this->timestamp($merged['creationTs'] ?? null)?->toIso8601String(),
                'salaryNormalization' => $salaryNote,
                'customFields' => $this->customFields($detail),
            ], fn ($v) => $v !== null && $v !== []),
        );
    }

    /**
     * @param  array<string, mixed>  $source
     * @return array{locations: string[], us: string[], non_us: string[], unknown: string[], states: string[]}
     */
    public function geography(array $source): array
    {
        $raw = $source['standardizedLocations'] ?? $source['locations'] ?? [];
        $locations = [];
        foreach (is_array($raw) ? $raw : [] as $entry) {
            if (is_string($entry) && trim($entry) !== '') {
                $locations[trim($entry)] = true;
            }
        }
        $locations = array_keys($locations);

        $geo = ['locations' => $locations, 'us' => [], 'non_us' => [], 'unknown' => [], 'states' => []];

        foreach ($locations as $location) {
            [$kind, $state] = $this->classify($location);
            $geo[$kind === 'us' ? 'us' : ($kind === 'non_us' ? 'non_us' : 'unknown')][] = $location;
            if ($state !== null) {
                $geo['states'][$state] = $state;
            }
        }
        $geo['states'] = array_values($geo['states']);
        sort($geo['states']);

        return $geo;
    }

    /**
     * @return array{0: 'us'|'non_us'|'unknown', 1: string|null}
     */
    private function classify(string $location): array
    {
        // "virtual two, ak" — a remote slot tied to a US state.
        if (preg_match('/^virtual\b[^,]*,\s*([A-Za-z]{2})$/i', $location, $m) === 1) {
            $state = strtoupper($m[1]);

            return in_array($state, self::US_STATES, true) ? ['us', $state] : ['unknown', null];
        }

        // A bare two-letter entry is a country code ("US", "IN").
        if (preg_match('/^[A-Za-z]{2}$/', $location) === 1) {
            return [strtoupper($location) === 'US' ? 'us' : 'non_us', null];
        }

        // "Orlando, FL, US", "FL,US"
        if (preg_match('/(?:^|,)\s*US$/i', $location) === 1) {
            $parts = array_map('trim', explode(',', $location));
            array_pop($parts);
            $state = strtoupper((string) end($parts));

            return ['us', in_array($state, self::US_STATES, true) ? $state : null];
        }

        // "Bengaluru, KA, IN", "Taipei City,TW"
        if (preg_match('/,\s*[A-Za-z]{2,3}$/', $location) === 1) {
            return ['non_us', null];
        }

        return ['unknown', null];
    }

    /**
     * @param  array{locations: string[], us: string[], non_us: string[], unknown: string[], states: string[]}  $geo
     */
    private function remoteStatus(?string $arrangement, array $geo): ?JobRemoteStatus
    {
        return match ($arrangement) {
            'onsite' => JobRemoteStatus::Onsite,
            'hybrid' => JobRemoteStatus::Hybrid,
            // Remote only with positive US evidence; otherwise leave it for
            // the discovery filter to judge from location text (or accept).
            'remote_local' => $geo['us'] !== [] ? JobRemoteStatus::Remote : null,
            default => null,
        };
    }

    /**
     * What the listing says about WHERE a remote role may be worked from.
     * Never "restricted to X" — only what is listed, so a Florida-based
     * candidate is not excluded by omission.
     *
     * @param  array{locations: string[], us: string[], non_us: string[], unknown: string[], states: string[]}  $geo
     */
    private function remoteEligibility(?string $arrangement, array $geo): ?string
    {
        if ($arrangement !== 'remote_local') {
            return null;
        }

        return match (true) {
            $geo['us'] === [] => 'unspecified',
            $geo['states'] === [] => 'us_unspecified_state',
            default => 'listed_states_not_confirmed_as_restriction',
        };
    }

    /**
     * @param  array{locations: string[], us: string[], non_us: string[], unknown: string[], states: string[]}  $geo
     */
    private function locationText(array $geo): ?string
    {
        if ($geo['locations'] === []) {
            return null;
        }

        $shown = array_map(
            fn (string $l) => preg_match('/^virtual\b[^,]*,\s*([A-Za-z]{2})$/i', $l, $m) === 1 ? 'Remote ('.strtoupper($m[1]).')' : $l,
            array_slice($geo['locations'], 0, self::MAX_LOCATIONS_SHOWN),
        );
        $more = count($geo['locations']) - count($shown);

        return implode('; ', array_unique($shown)).($more > 0 ? " (+{$more} more)" : '');
    }

    /**
     * @param  array<string, mixed>  $merged
     * @param  array{locations: string[], us: string[], non_us: string[], unknown: string[], states: string[]}  $geo
     * @return array{0: int|null, 1: int|null, 2: string|null, 3: string|null, 4: array<string, mixed>|null}
     */
    private function salary(EightfoldTenant $tenant, array $merged, array $geo, ?string $description): array
    {
        $none = [null, null, null, null];

        if ($tenant->salary === null) {
            return [...$none, null];
        }

        $rawValues = $merged[$tenant->salary['field']] ?? null;
        $raw = is_array($rawValues) ? array_values(array_filter($rawValues, 'is_string')) : [];

        if ($raw === []) {
            return [...$none, ['status' => 'no_value']];
        }

        $note = fn (string $status) => ['status' => $status, 'raw' => $raw];

        if (count($raw) !== 1) {
            return [...$none, $note('multiple_values')];
        }

        // The field describes the primary location only; a multi-location or
        // multi-geo-zone posting would otherwise get one misleading figure.
        if (count($geo['locations']) !== 1) {
            return [...$none, $note('multiple_locations')];
        }

        if ($description !== null && preg_match_all('/GEOZONE\s*\d/i', $description) > 1) {
            return [...$none, $note('multiple_geographic_ranges')];
        }

        if (preg_match('/^\s*\$\s*([\d,]+(?:\.\d+)?)\s*-\s*\$\s*([\d,]+(?:\.\d+)?)\s*$/', $raw[0], $m) !== 1) {
            return [...$none, $note('unparseable')];
        }

        $min = (int) round((float) str_replace(',', '', $m[1]));
        $max = (int) round((float) str_replace(',', '', $m[2]));

        // The mapped field is an annual figure; anything that cannot be one is not trusted.
        if ($min < 20000 || $max < $min) {
            return [...$none, $note('implausible_for_declared_interval')];
        }

        return [$min, $max, $tenant->salary['currency'], $tenant->salary['interval'], ['status' => 'normalized', 'raw' => $raw]];
    }

    /**
     * Raw tenant-specific attribute fields (clearance, telework, pay, …),
     * bounded and stringified. Preserved, never interpreted.
     *
     * @param  array<string, mixed>  $detail
     * @return array<string, string>
     */
    private function customFields(array $detail): array
    {
        $fields = [];
        foreach ($detail as $key => $value) {
            if (count($fields) >= self::MAX_CUSTOM_FIELDS) {
                break;
            }
            if (! str_starts_with($key, 'efcustom')) {
                continue;
            }
            $list = is_array($value) ? $value : [$value];
            $strings = array_values(array_filter(array_map(
                fn ($v) => is_scalar($v) ? trim((string) $v) : '',
                $list,
            ), fn (string $v) => $v !== ''));
            if ($strings !== []) {
                $fields[$key] = mb_substr(implode(' | ', $strings), 0, 200);
            }
        }

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private function publicUrl(array $detail): ?string
    {
        $url = $detail['publicUrl'] ?? null;

        return is_string($url) && preg_match('~^https://~i', $url) === 1 ? $url : null;
    }

    private function timestamp(mixed $value): ?CarbonImmutable
    {
        return is_int($value) && $value > 0 ? CarbonImmutable::createFromTimestampUTC($value) : null;
    }

    /**
     * @param  string[]  $terms
     */
    private function matchesAny(string $haystack, array $terms): bool
    {
        foreach ($terms as $term) {
            $words = preg_split('/\s+/u', trim($term)) ?: [];
            if ($words === [] || $words === ['']) {
                continue;
            }
            $phrase = implode('\s+', array_map(fn (string $w) => preg_quote($w, '~'), $words));
            if (preg_match('~(?<![\p{L}\p{N}_])'.$phrase.'(?![\p{L}\p{N}_])~iu', $haystack) === 1) {
                return true;
            }
        }

        return false;
    }

    private function requiresUs(SearchProfile $profile): bool
    {
        return $profile->remotePreference === 'remote_us' || $profile->allowedLocations !== [];
    }

    private function htmlToPlainText(string $html): string
    {
        $withBullets = preg_replace('~<li[^>]*>~i', "\n- ", $html) ?? $html;
        $withBreaks = preg_replace('~</(p|div|h[1-6]|li|ul|ol)>~i', "\n", $withBullets) ?? $withBullets;
        $withBreaks = preg_replace('~<br\s*/?>~i', "\n", $withBreaks) ?? $withBreaks;
        $plain = html_entity_decode(strip_tags($withBreaks), ENT_QUOTES | ENT_HTML5);
        $plain = str_replace("\u{A0}", ' ', $plain);
        $plain = preg_replace('/[ \t]+/', ' ', $plain) ?? $plain;
        $plain = preg_replace('/\n[ \t]+/', "\n", $plain) ?? $plain;

        return trim(preg_replace('/\n{3,}/', "\n\n", trim($plain)) ?? $plain);
    }
}
