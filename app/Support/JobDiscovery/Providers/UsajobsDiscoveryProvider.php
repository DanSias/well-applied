<?php

namespace App\Support\JobDiscovery\Providers;

use App\Enums\DescriptionCompleteness;
use App\Enums\JobDiscoverySource;
use App\Enums\JobRemoteStatus;
use App\Support\JobDiscovery\DiscoveredJobCandidate;
use App\Support\JobDiscovery\SearchProfile;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * USAJOBS, the federal government's job board (developer.usajobs.gov, GET /api/Search).
 *
 * The search is deliberately narrow and bounded: one occupational series (2210, Information Technology Management, in
 * config), remote announcements only when the profile wants remote_us, the public hiring path, newest first, and at most
 * `per_page` x `max_pages` results (further capped by the context's maxResults). It does not crawl. Not every 2210 opening
 * is a software role, so the title gate (including the federal-only patterns in FederalTitles) still decides.
 *
 * Identity is the announcement number (`PositionID`), falling back to the control number (`MatchedObjectId`) only when
 * an announcement number is missing; the control number is kept in metadata either way.
 *
 * Remote versus telework: a posting is Remote only when the announcement says so (its remote indicator, or the
 * "(remote job)" location USAJOBS prints for those). Telework eligibility is preserved as metadata and never converted
 * into a remote status; a posting that is merely telework eligible keeps an unknown remote status, and the discovery
 * filter (not this provider) decides what to do with it. Hiring-path / "who may apply" data is kept raw: nothing here
 * says the user is eligible.
 *
 * Credentials (an API key and the registered email as User-Agent) are sent as headers and never logged. Missing either
 * fails this provider closed (DiscoveryProviderException) without making a request; the orchestrator only registers the
 * provider when both are configured, see isActive().
 */
final class UsajobsDiscoveryProvider implements DiscoveryProviderContract, ReportsRetrievalDiagnostics
{
    use RecordsRetrievalDiagnostics;

    private const TIMEOUT_SECONDS = 30;

    private const RETRY_TIMES = 3;

    private const RETRY_DELAY_MILLISECONDS = 1000;

    private const RETRYABLE_STATUSES = [408, 429, 500, 502, 503, 504];

    private const MAX_LOCATIONS_KEPT = 20;

    private const DESCRIPTION_LIMIT = 20000;

    public static function isActive(): bool
    {
        return trim((string) config('services.usajobs.api_key')) !== '' && trim((string) config('services.usajobs.user_agent')) !== '';
    }

    /**
     * @return DiscoveredJobCandidate[]
     */
    public function retrieve(?ProviderDiscoveryContext $context = null): array
    {
        $this->diagnostics = [];

        $apiKey = trim((string) config('services.usajobs.api_key'));
        $userAgent = trim((string) config('services.usajobs.user_agent'));
        if ($apiKey === '' || $userAgent === '') {
            throw new DiscoveryProviderException('USAJOBS is not configured (USAJOBS_API_KEY/USAJOBS_USER_AGENT).');
        }

        $perPage = max(1, min(500, (int) config('job_discovery.usajobs.per_page', 50)));
        $cap = $context?->maxResults;
        $branches = $this->branches($context?->profile);
        // `max_pages` is the ceiling on requests for the WHOLE run, shared by every branch (never per branch). A branch
        // may use what is left minus one page reserved for each branch after it, and every branch gets at least one page,
        // so the ceiling is raised to the branch count only when it would otherwise starve a branch.
        $pagesLeft = max((int) config('job_discovery.usajobs.max_pages', 3), count($branches));

        $entries = [];
        $seenIds = [];
        $pagesFetched = 0;
        $duplicates = 0;

        foreach ($branches as $index => $branch) {
            $branchBudget = max(1, $pagesLeft - (count($branches) - $index - 1));

            for ($page = 1; $page <= $branchBudget; $page++) {
                $remaining = $cap === null ? null : $cap - count($entries);
                if ($remaining !== null && $remaining < 1) {
                    break 2;
                }
                $size = $remaining === null ? $perPage : min($perPage, $remaining);

                $result = $this->fetchPage($apiKey, $userAgent, $page, $size, $branch);
                $pagesFetched++;
                $pagesLeft--;
                $items = $result['SearchResultItems'];

                foreach ($items as $item) {
                    if (! is_array($item)) {
                        throw new DiscoveryProviderException('USAJOBS response contained a malformed job entry.');
                    }
                    // Same identity rule as the candidate's own: announcement number first, control number only when
                    // there is none. The same posting can appear on two pages (the result set shifts) or in two branches.
                    $key = $this->identity($item);
                    if ($key !== null && isset($seenIds[$key])) {
                        $duplicates++;

                        continue;
                    }
                    if ($key !== null) {
                        $seenIds[$key] = true;
                    }
                    $entries[] = $item;
                }

                $total = is_numeric($result['SearchResultCountAll'] ?? null) ? (int) $result['SearchResultCountAll'] : null;
                if (count($items) < $size || ($total !== null && $page * $size >= $total)) {
                    break;
                }
            }
        }

        if ($cap !== null) {
            $entries = array_slice($entries, 0, $cap);
        }

        try {
            $candidates = $this->normalizeEntries($entries, fn (array $item) => $this->normalize($item));
        } catch (\TypeError|\ValueError $e) {
            throw new DiscoveryProviderException('USAJOBS response contained malformed job fields.', previous: $e);
        }

        $this->diagnostics['pages_fetched'] = $pagesFetched;
        $this->diagnostics['search_branches'] = count($branches);

        // Two entries can carry the same announcement number (a re-listed announcement): keep the first.
        $unique = [];
        foreach ($candidates as $candidate) {
            $unique[$candidate->sourceJobId] ??= $candidate;
        }
        $this->diagnostics['duplicate_announcements'] = $duplicates + count($candidates) - count($unique);

        return array_values($unique);
    }

    /**
     * The search branches to run, one bounded search each:
     *   - profile wants `any` location: a single unfiltered branch (the filter decides);
     *   - otherwise a remote branch (`RemoteIndicator=True`), plus one branch per hybrid region the profile allows
     *     (`LocationName=<region>`, e.g. Florida), see hybridRegions().
     *
     * @return list<array<string, string>>
     */
    private function branches(?SearchProfile $profile): array
    {
        if (($profile->remotePreference ?? 'remote_us') !== 'remote_us') {
            return [[]];
        }

        $branches = [['RemoteIndicator' => 'True']];
        foreach (self::hybridRegions($profile) as $region) {
            $branches[] = ['LocationName' => $region];
        }

        return $branches;
    }

    /**
     * The regions (US states) where hybrid / telework-eligible work is acceptable: `search.hybrid_regions`, the same
     * list the discovery filter uses. Without a profile that is the whole list. With a profile it is only the regions the
     * profile names in its allowed locations, so a remote-only profile (the built-ins list just "United States", "Remote")
     * keeps its remote-only behavior and a regional profile opts in by naming the region.
     *
     * @return list<string>
     */
    public static function hybridRegions(?SearchProfile $profile = null): array
    {
        $configured = array_values(array_filter((array) config('job_discovery.search.hybrid_regions', []), fn ($r) => is_string($r) && trim($r) !== ''));
        if ($profile === null) {
            return $configured;
        }

        $named = array_map(fn ($location) => mb_strtolower(trim((string) $location)), $profile->allowedLocations);

        return array_values(array_filter($configured, fn (string $region) => in_array(mb_strtolower($region), $named, true)));
    }

    /**
     * @param  array<string, string>  $branch  Extra query parameters that define this search branch.
     * @return array<string, mixed> The response's SearchResult object (SearchResultItems guaranteed to be a list).
     */
    private function fetchPage(string $apiKey, string $userAgent, int $page, int $perPage, array $branch): array
    {
        $query = array_filter($branch + [
            'JobCategoryCode' => (string) config('job_discovery.usajobs.category', '2210'),
            'HiringPath' => config('job_discovery.usajobs.hiring_path'),
            'SortField' => 'opendate',
            'SortDirection' => 'Desc',
            'Fields' => 'Full',
            'ResultsPerPage' => $perPage,
            'Page' => $page,
        ], fn ($value) => $value !== null && $value !== '');

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->withHeaders(['Host' => 'data.usajobs.gov', 'User-Agent' => $userAgent, 'Authorization-Key' => $apiKey])
                ->retry(
                    self::RETRY_TIMES,
                    self::RETRY_DELAY_MILLISECONDS,
                    fn (Throwable $e) => $this->isRetryable($e),
                    throw: false,
                )
                ->get((string) config('services.usajobs.base_url'), $query);
        } catch (ConnectionException $e) {
            Log::warning('USAJOBS discovery: connection failure.');

            throw new DiscoveryProviderException('USAJOBS request failed: connection error.', previous: $e);
        }

        if ($response->failed()) {
            // Status only: never the URL, headers or body, which carry or echo the credentials.
            Log::warning('USAJOBS discovery: request failed.', ['status' => $response->status()]);

            throw new DiscoveryProviderException("USAJOBS request failed with HTTP status {$response->status()}.");
        }

        $result = $response->json('SearchResult');
        if (! is_array($result) || ! is_array($result['SearchResultItems'] ?? null)) {
            throw new DiscoveryProviderException('USAJOBS response did not contain a SearchResultItems array — response shape may have changed.');
        }

        return $result;
    }

    /**
     * The stable external identity: the announcement number (`PositionID`), falling back to the control number
     * (`MatchedObjectId`) only when there is no announcement number.
     *
     * @param  array<string, mixed>  $item
     */
    private function identity(array $item): ?string
    {
        $descriptor = is_array($item['MatchedObjectDescriptor'] ?? null) ? $item['MatchedObjectDescriptor'] : [];

        return $this->scalarString($descriptor['PositionID'] ?? null) ?? $this->scalarString($item['MatchedObjectId'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function normalize(array $item): ?DiscoveredJobCandidate
    {
        $descriptor = $item['MatchedObjectDescriptor'] ?? null;
        if (! is_array($descriptor)) {
            return null;
        }

        $controlNumber = $this->scalarString($item['MatchedObjectId'] ?? null);
        $announcement = $this->scalarString($descriptor['PositionID'] ?? null);
        $sourceJobId = $this->identity($item);
        $title = $this->text($descriptor['PositionTitle'] ?? null);
        $organization = $this->text($descriptor['OrganizationName'] ?? null) ?? $this->text($descriptor['DepartmentName'] ?? null);

        if ($sourceJobId === null || $title === null || $organization === null) {
            return null;
        }

        $details = is_array($descriptor['UserArea']['Details'] ?? null) ? $descriptor['UserArea']['Details'] : [];
        $locations = $this->locations($descriptor);
        $locationDisplay = $this->text($descriptor['PositionLocationDisplay'] ?? null) ?? ($locations === [] ? null : implode('; ', array_slice($locations, 0, 5)));
        $remoteIndicator = $this->boolish($details['RemoteIndicator'] ?? $descriptor['RemoteIndicator'] ?? null);
        $telework = $this->boolish($details['TeleworkEligible'] ?? $details['Telework'] ?? null);
        [$min, $max, $interval, $remuneration] = $this->remuneration($descriptor['PositionRemuneration'] ?? null);
        [$description, $complete] = $this->description($descriptor, $details);
        $url = $this->url($descriptor['PositionURI'] ?? null) ?? $this->url(is_array($descriptor['ApplyURI'] ?? null) ? ($descriptor['ApplyURI'][0] ?? null) : null);

        return new DiscoveredJobCandidate(
            source: JobDiscoverySource::Usajobs,
            sourceJobId: $sourceJobId,
            company: $organization,
            title: $title,
            description: $description,
            descriptionCompleteness: $complete ? DescriptionCompleteness::Complete : DescriptionCompleteness::Preview,
            location: $locationDisplay,
            remoteStatus: $this->remoteStatus($remoteIndicator, $locationDisplay),
            employmentType: $this->employmentType($descriptor['PositionSchedule'] ?? null),
            compensationMin: $min,
            compensationMax: $max,
            compensationCurrency: $min !== null || $max !== null ? 'USD' : null,
            compensationInterval: $interval,
            applicationUrl: $url,
            postedAt: $this->date($descriptor['PublicationStartDate'] ?? $descriptor['PositionStartDate'] ?? null),
            sourceUpdatedAt: null,
            discoveredAt: now(),
            sourceMetadata: array_filter([
                'announcementNumber' => $announcement,
                'controlNumber' => $controlNumber,
                'department' => $this->text($descriptor['DepartmentName'] ?? null),
                'organization' => $this->text($descriptor['OrganizationName'] ?? null),
                'jobCategories' => $this->names($descriptor['JobCategory'] ?? null, 'Code'),
                'payPlan' => $this->names($descriptor['JobGrade'] ?? null, 'Code'),
                'lowGrade' => $this->scalarString($details['LowGrade'] ?? null),
                'highGrade' => $this->scalarString($details['HighGrade'] ?? null),
                'promotionPotential' => $this->scalarString($details['PromotionPotential'] ?? null),
                'offeringType' => $this->coded($descriptor['PositionOfferingType'] ?? null),
                'schedule' => $this->coded($descriptor['PositionSchedule'] ?? null),
                'remuneration' => $remuneration,
                'remoteIndicator' => $remoteIndicator,
                'teleworkEligible' => $telework,
                'whoMayApply' => $this->raw($details['WhoMayApply'] ?? null),
                'hiringPath' => $this->raw($details['HiringPath'] ?? null),
                'securityClearance' => $this->scalarString($details['SecurityClearance'] ?? null),
                'travel' => $this->scalarString($details['TravelCode'] ?? $details['Travel'] ?? null),
                'relocation' => $this->boolish($details['Relocation'] ?? null),
                'openDate' => $this->date($descriptor['PositionStartDate'] ?? null)?->toIso8601String(),
                'closingDate' => $this->date($descriptor['ApplicationCloseDate'] ?? $descriptor['PositionEndDate'] ?? null)?->toIso8601String(),
                // Computed over EVERY listed location (multi-location announcements can list dozens), unlike the capped list.
                'hybridRegionsListed' => $this->regionsListed($locations),
                'locations' => array_slice($locations, 0, self::MAX_LOCATIONS_KEPT),
                'applyUris' => $this->applyUris($descriptor['ApplyURI'] ?? null),
            ], fn ($value) => $value !== null && $value !== []),
        );
    }

    /**
     * Remote only on the announcement's own say-so: its remote indicator, or the "(remote job)" location USAJOBS shows for
     * those announcements. Everything else, telework-eligible included, stays unknown.
     */
    private function remoteStatus(?bool $remoteIndicator, ?string $locationDisplay): ?JobRemoteStatus
    {
        if ($remoteIndicator === true || ($locationDisplay !== null && preg_match('/\(remote job\)/i', $locationDisplay) === 1)) {
            return JobRemoteStatus::Remote;
        }

        return null;
    }

    /**
     * Which configured hybrid regions appear among the announcement's listed locations.
     *
     * @param  list<string>  $locations
     * @return list<string>
     */
    private function regionsListed(array $locations): array
    {
        $listed = [];
        foreach (self::hybridRegions() as $region) {
            foreach ($locations as $location) {
                if (preg_match('/\b'.preg_quote($region, '/').'\b/i', $location) === 1) {
                    $listed[] = $region;

                    break;
                }
            }
        }

        return $listed;
    }

    /**
     * @param  array<string, mixed>  $descriptor
     * @return list<string>
     */
    private function locations(array $descriptor): array
    {
        $locations = [];
        foreach (is_array($descriptor['PositionLocation'] ?? null) ? $descriptor['PositionLocation'] : [] as $location) {
            $name = is_array($location) ? $this->text($location['LocationName'] ?? null) : null;
            if ($name !== null) {
                $locations[$name] = $name;
            }
        }

        return array_values($locations);
    }

    /**
     * Min/max across the listed pay ranges (one per grade for multi-grade announcements), only when they share one
     * interval; otherwise the first range's. The raw ranges are kept for the metadata.
     *
     * @return array{0: int|null, 1: int|null, 2: string|null, 3: list<array<string, string|null>>}
     */
    private function remuneration(mixed $entries): array
    {
        $ranges = [];
        foreach (is_array($entries) ? $entries : [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $min = is_numeric($entry['MinimumRange'] ?? null) ? (float) $entry['MinimumRange'] : null;
            $max = is_numeric($entry['MaximumRange'] ?? null) ? (float) $entry['MaximumRange'] : null;
            if ($min === null && $max === null) {
                continue;
            }
            $ranges[] = [
                'min' => $min,
                'max' => $max,
                'code' => $this->scalarString($entry['RateIntervalCode'] ?? null),
                'description' => $this->text($entry['Description'] ?? null),
            ];
        }

        if ($ranges === []) {
            return [null, null, null, []];
        }

        $first = $ranges[0]['code'];
        $same = array_values(array_filter($ranges, fn (array $r) => $r['code'] === $first));
        $mins = array_filter(array_column($same, 'min'), fn ($v) => $v !== null);
        $maxes = array_filter(array_column($same, 'max'), fn ($v) => $v !== null);

        return [
            $mins === [] ? null : (int) round(min($mins)),
            $maxes === [] ? null : (int) round(max($maxes)),
            $this->interval($first, $ranges[0]['description']),
            array_map(fn (array $r) => [
                'min' => $r['min'] === null ? null : (string) $r['min'],
                'max' => $r['max'] === null ? null : (string) $r['max'],
                'interval_code' => $r['code'],
                'interval' => $r['description'],
            ], array_slice($ranges, 0, 10)),
        ];
    }

    private function interval(?string $code, ?string $description): ?string
    {
        return match (strtoupper((string) $code)) {
            'PA' => 'year',
            'PH' => 'hour',
            'PM' => 'month',
            'PW' => 'week',
            'PD' => 'day',
            default => $description !== null && preg_match('/\b(year|hour|month|week|day)\b/i', $description, $m) === 1 ? strtolower($m[1]) : null,
        };
    }

    /**
     * The announcement's own text, section by section. Complete once it carries duties or requirements (not just the
     * summary).
     *
     * @param  array<string, mixed>  $descriptor
     * @param  array<string, mixed>  $details
     * @return array{0: string|null, 1: bool}
     */
    private function description(array $descriptor, array $details): array
    {
        $sections = [
            'Summary' => $details['JobSummary'] ?? null,
            'Duties' => $details['MajorDuties'] ?? null,
            'Requirements' => $details['Requirements'] ?? null,
            'Qualifications' => $descriptor['QualificationSummary'] ?? null,
            'Education' => $details['Education'] ?? null,
            'Evaluations' => $details['Evaluations'] ?? null,
        ];

        $parts = [];
        $deep = false;
        foreach ($sections as $heading => $value) {
            $text = $this->text(is_array($value) ? implode("\n", array_filter($value, 'is_string')) : $value);
            if ($text === null) {
                continue;
            }
            $parts[] = "{$heading}:\n{$text}";
            $deep = $deep || in_array($heading, ['Duties', 'Requirements', 'Qualifications'], true);
        }

        $description = $parts === [] ? null : mb_substr(implode("\n\n", $parts), 0, self::DESCRIPTION_LIMIT);

        return [$description, $deep];
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $text = trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5));

        return $text === '' ? null : $text;
    }

    private function scalarString(mixed $value): ?string
    {
        if (is_int($value) || (is_string($value) && trim($value) !== '')) {
            return trim((string) $value);
        }

        return null;
    }

    private function boolish(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return match (strtolower(trim($value))) {
                'true', 'yes', 'y', '1' => true,
                'false', 'no', 'n', '0' => false,
                default => null,
            };
        }

        return null;
    }

    /**
     * `PositionSchedule[].Name` is free text in live data ("Flextime and Alternate Work Schedules are available", "Open",
     * or empty), not an employment type, so the type comes from the code, and only for the two codes USAJOBS's published
     * code list defines unambiguously. Anything else stays null; the raw code and name are kept in metadata.
     */
    private function employmentType(mixed $entries): ?string
    {
        $code = is_array($entries) && is_array($entries[0] ?? null) ? $this->scalarString($entries[0]['Code'] ?? null) : null;

        return match ($code) {
            '1' => 'Full-Time',
            '2' => 'Part-Time',
            default => null,
        };
    }

    /**
     * Code/name pairs as given (live names are often empty or long free text), empty parts left out.
     *
     * @return list<array<string, string>>
     */
    private function coded(mixed $entries): array
    {
        $rows = [];
        foreach (is_array($entries) ? array_slice($entries, 0, 5) : [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $row = array_filter([
                'code' => $this->scalarString($entry['Code'] ?? null),
                'name' => $this->text($entry['Name'] ?? null),
            ], fn ($value) => $value !== null);
            if ($row !== []) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function names(mixed $entries, string $key): array
    {
        $names = [];
        foreach (is_array($entries) ? $entries : [] as $entry) {
            $value = is_array($entry) ? $this->scalarString($entry[$key] ?? null) : null;
            if ($value !== null) {
                $names[$value] = $value;
            }
        }

        return array_values($names);
    }

    /**
     * Eligibility / hiring-path data is preserved as given (bounded), never interpreted.
     */
    private function raw(mixed $value): mixed
    {
        // Live announcements often carry an empty shell ({"Name": "", "Code": ""}): that is "not stated", not data.
        if (is_array($value)) {
            return array_slice(array_filter($value, fn ($v) => (is_scalar($v) && trim((string) $v) !== '') || (is_array($v) && $v !== [])), 0, 20, true);
        }

        return is_scalar($value) && trim((string) $value) !== '' ? $value : null;
    }

    /**
     * @return list<string>
     */
    private function applyUris(mixed $entries): array
    {
        $urls = [];
        foreach (is_array($entries) ? array_slice($entries, 0, 5) : [] as $entry) {
            $url = $this->url($entry);
            if ($url !== null) {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    private function url(mixed $value): ?string
    {
        return is_string($value) && preg_match('~^https?://~i', trim($value)) === 1 ? trim($value) : null;
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function isRetryable(Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        return $exception instanceof RequestException
            && in_array($exception->response->status(), self::RETRYABLE_STATUSES, true);
    }
}
