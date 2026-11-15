<?php

namespace App\Support\JobDiscovery;

use App\Enums\DiscoveryRejectionReason;
use App\Enums\JobDiscoverySource;
use App\Enums\JobRemoteStatus;

/**
 * Deterministic discovery filtering — never fit scoring. Every check here is a plain, explainable yes/no; nothing ranks
 * or scores a candidate against another. Conservative by design: a criterion only rejects a candidate when the provider
 * actually gave a value that contradicts it — missing or ambiguous metadata is never itself a reason to reject, per
 * docs/job-discovery.md.
 *
 * evaluate() returns the structured decision (one primary reason per rejection); accepts() is its boolean shorthand.
 *
 * Rule precedence, first match wins:
 *   1. title names an excluded role identity (excluded_title_role: SRE, DevOps, firmware, compiler)
 *   2. title names a junior/intern role      (excluded_seniority)
 *   3. title is not a target role            (title_not_matching)
 *   4. explicit on-site, or hybrid outside Florida (work_arrangement_not_allowed); for USAJOBS, anything the
 *      announcement does not itself mark remote
 *   5. explicit non-US-only location or country restriction (location_not_allowed)
 *   6. employment type contradicts the criteria (employment_type_not_allowed)
 */
final class FilterDiscoveredCandidates
{
    public function accepts(DiscoveredJobCandidate $candidate, DiscoverySearchCriteria $criteria): bool
    {
        return $this->evaluate($candidate, $criteria)->accepted;
    }

    public function evaluate(DiscoveredJobCandidate $candidate, DiscoverySearchCriteria $criteria): FilterDecision
    {
        $title = $candidate->title;

        // Title-only: infrastructure terminology in a DESCRIPTION is common in legitimate product-engineering roles.
        if ($criteria->excludedTitleKeywords !== [] && ($term = $this->matchedKeyword($title, $criteria->excludedTitleKeywords)) !== null) {
            return FilterDecision::reject(DiscoveryRejectionReason::ExcludedTitleRole, $term, 'title', $title);
        }

        // USAJOBS only: supervisory federal titles are management, not the individual-contributor role searched for.
        if ($candidate->source === JobDiscoverySource::Usajobs && ($term = FederalTitles::excludedTerm($title)) !== null) {
            return FilterDecision::reject(DiscoveryRejectionReason::ExcludedTitleRole, $term, 'title', $title);
        }

        // Title-only: "mentors junior developers" in a description says nothing about the role's own level.
        if ($criteria->excludedKeywords !== [] && ($term = $this->matchedKeyword($title, $criteria->excludedKeywords)) !== null) {
            return FilterDecision::reject(DiscoveryRejectionReason::ExcludedSeniority, $term, 'title', $title);
        }

        // Empty keywords = no positive filter = accept everything; empty exclusion lists exclude nothing.
        if ($criteria->keywords !== [] && $this->matchedKeyword($title, $criteria->keywords, pluralRoles: true) === null
            && ! ($criteria->titleFamilies && TitleFamilies::matches($title))
            && ! ($candidate->source === JobDiscoverySource::Usajobs && FederalTitles::matches($title))) {
            return FilterDecision::reject(DiscoveryRejectionReason::TitleNotMatching, null, 'title', $title);
        }

        if ($criteria->remotePreference === 'remote_us') {
            $rejection = $this->workplaceRejection($candidate);
            if ($rejection !== null) {
                return $rejection;
            }
        }

        if ($criteria->employmentType !== null && $candidate->employmentType !== null
            && mb_strtolower($candidate->employmentType) !== mb_strtolower($criteria->employmentType)) {
            return FilterDecision::reject(DiscoveryRejectionReason::EmploymentTypeNotAllowed, $criteria->employmentType, 'employment_type', $candidate->employmentType);
        }

        return FilterDecision::accept();
    }

    /**
     * With $pluralRoles, a phrase ending in a role noun ("software engineer") also matches its plural ("software
     * engineers"). Only used for the positive title keywords, never the exclusions.
     *
     * @param  string[]  $keywords
     */
    private function matchedKeyword(string $haystack, array $keywords, bool $pluralRoles = false): ?string
    {
        foreach ($keywords as $keyword) {
            $words = preg_split('/\\s+/u', trim($keyword)) ?: [];
            if ($words === [] || $words === ['']) {
                continue;
            }
            $phrase = implode('\\s+', array_map(fn (string $word) => preg_quote($word, '~'), $words));
            if ($pluralRoles && in_array(mb_strtolower((string) end($words)), TitleFamilies::roles(), true)) {
                $phrase .= 's?';
            }
            if (preg_match('~(?<![\\p{L}\\p{N}_])'.$phrase.'(?![\\p{L}\\p{N}_])~iu', $haystack) === 1) {
                return $keyword;
            }
        }

        return null;
    }

    /**
     * A non-remote USAJOBS posting: only a telework-eligible posting that lists a configured hybrid region
     * (`search.hybrid_regions`) passes; an explicit on-site one, or one that names no such region, is rejected.
     */
    private function federalArrangementRejection(DiscoveredJobCandidate $candidate, string $location): ?FilterDecision
    {
        $regions = array_values(array_filter((array) config('job_discovery.search.hybrid_regions', []), 'is_string'));
        $listed = array_values(array_intersect($regions, (array) ($candidate->sourceMetadata['hybridRegionsListed'] ?? [])));
        $telework = $candidate->sourceMetadata['teleworkEligible'] ?? null;
        $where = $location !== '' ? "; location: {$location}" : '';

        if ($listed !== []) {
            if ($telework === true) {
                return null;
            }

            return self::workArrangement($telework === false ? 'onsite' : 'telework_not_stated', ($telework === false ? 'not telework eligible' : 'telework eligibility not stated').' in '.implode(', ', $listed).$where);
        }

        return self::workArrangement('not_remote', ($telework === true ? 'telework eligible' : 'no remote designation').$where);
    }

    private static function workArrangement(string $term, string $evidence): FilterDecision
    {
        return FilterDecision::reject(DiscoveryRejectionReason::WorkArrangementNotAllowed, $term, 'remote_status', $evidence);
    }

    /**
     * The remote-US policy. Only an explicit contradiction rejects:
     *   - on-site, always (Florida included, for now);
     *   - hybrid, unless the location is Florida (a hybrid role with no location stays, as unknown);
     *   - a location or country restriction that names only places outside the US.
     * A bare "US", "USA" or state name is NOT proof of remote work, and unknown remote status passes through unchanged for
     * qualification to assess.
     */
    private function workplaceRejection(DiscoveredJobCandidate $candidate): ?FilterDecision
    {
        $location = trim((string) $candidate->location);

        // USAJOBS only. Unknown remote status normally passes, but a federal posting is wanted when the announcement itself
        // says it is remote, or (a hybrid region such as Florida) when it lists that region AND is telework eligible.
        // Telework eligibility is never stored as remote; it only decides acceptance here.
        if ($candidate->source === JobDiscoverySource::Usajobs && $candidate->remoteStatus !== JobRemoteStatus::Remote) {
            return $this->federalArrangementRejection($candidate, $location);
        }

        if ($candidate->remoteStatus === JobRemoteStatus::Onsite) {
            return FilterDecision::reject(DiscoveryRejectionReason::WorkArrangementNotAllowed, 'onsite', 'remote_status', $location !== '' ? "on-site; location: {$location}" : 'on-site');
        }

        // Eightfold's `location` text is truncated for display; its metadata carries the signals of every listed place.
        $floridaListed = LocationSignals::mentionsFlorida($location) || ($candidate->sourceMetadata['floridaListed'] ?? false) === true;
        $usListed = ($candidate->sourceMetadata['usLocationListed'] ?? false) === true;

        if ($candidate->remoteStatus === JobRemoteStatus::Hybrid && $location !== '' && ! $floridaListed) {
            return FilterDecision::reject(DiscoveryRejectionReason::WorkArrangementNotAllowed, 'hybrid', 'remote_status', "hybrid outside Florida; location: {$location}");
        }

        // Himalayas supplies explicit eligible-country restrictions. Remote describes the workplace, not permission to
        // work from any country.
        if ($candidate->source === JobDiscoverySource::Himalayas) {
            $restrictions = $candidate->sourceMetadata['locationRestrictions'] ?? null;
            if (is_array($restrictions)) {
                $countries = array_values(array_filter($restrictions, fn ($country) => is_string($country) && trim($country) !== ''));
                if ($countries !== [] && array_intersect(array_map(fn (string $country) => mb_strtolower(trim($country)), $countries), ['united states', 'united states of america', 'us', 'usa', 'worldwide', 'anywhere', 'global']) === []) {
                    return FilterDecision::reject(DiscoveryRejectionReason::LocationNotAllowed, implode(', ', $countries), 'location_restrictions', 'restricted to: '.implode(', ', $countries));
                }
            }
        }

        if ($location !== '' && ! $usListed && LocationSignals::isNonUsOnly($location)) {
            return FilterDecision::reject(DiscoveryRejectionReason::LocationNotAllowed, null, 'location', $location);
        }

        return null;
    }
}
