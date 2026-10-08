<?php

namespace App\Support\JobDiscovery\Providers;

use App\Support\JobDiscovery\SearchProfile;
use InvalidArgumentException;

/**
 * Immutable discovery context handed to DiscoveryProviderContract::retrieve()
 * when a caller wants to hint "search for this profile" and/or "cap the
 * results" — without the caller knowing how the provider expresses either in
 * its own API language.
 *
 * Both fields are optional; null means "no preference from the caller" and
 * the provider falls back to its own default search. Providers may also
 * IGNORE the context entirely — the V1 providers (Himalayas, Jobicy,
 * Adzuna) deliberately do. Accepting the context is a contract capability;
 * honoring it is not yet an obligation, so nothing about an ignored context
 * is a bug.
 *
 * Deliberately NOT here:
 * - Provider-specific API params (Adzuna's `what`, Himalayas's `limit`,
 *   Jobicy's `geo`/`count`): each provider translates the profile into its
 *   own query language privately; duplicating those params here would bind
 *   one provider's API vocabulary to every other provider.
 * - Quota tracking, pagination state, provider health: those belong to the
 *   provider (or the orchestrator) that owns them, not a shared context.
 *
 * The profile itself is provider-neutral (see SearchProfile): terms
 * describing the KIND of job to find — never hiring criteria, never a
 * provider's parameter names.
 */
final readonly class ProviderDiscoveryContext
{
    /**
     * Optional profile describing the jobs the caller wants to find.
     * Present: a provider MAY translate it into a native search (a later
     * Hirebase provider does exactly this). Absent: the provider uses its
     * own built-in default search, unchanged.
     */
    public ?SearchProfile $profile;

    /**
     * Optional cap on how many results this retrieve() call should fetch.
     * A positive integer when set. Providers that honor it treat it as a
     * ceiling (they may return fewer); providers that ignore the context
     * simply keep their own default page size.
     */
    public ?int $maxResults;

    /**
     * @throws InvalidArgumentException when $maxResults is set to zero or a
     *                                  negative number.
     */
    public function __construct(
        ?SearchProfile $profile = null,
        ?int $maxResults = null,
    ) {
        if ($maxResults !== null && $maxResults < 1) {
            throw new InvalidArgumentException('ProviderDiscoveryContext::$maxResults must be a positive integer when set.');
        }

        $this->profile = $profile;
        $this->maxResults = $maxResults;
    }
}
