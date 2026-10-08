<?php

namespace App\Support\JobDiscovery\Providers;

use App\Support\JobDiscovery\DiscoveredJobCandidate;

interface DiscoveryProviderContract
{
    /**
     * Retrieves and normalizes candidates from this provider. Throws
     * DiscoveryProviderException on any provider-level failure
     * (network, malformed response, missing config) — never returns a
     * partial/empty array to signal failure, so a genuine "no jobs
     * matched" response is never confused with an error.
     *
     * $context is an optional forward-compatibility hook: the caller MAY
     * pass a ProviderDiscoveryContext carrying an optional SearchProfile
     * and an optional max-results cap. Providers MAY ignore it — the V1
     * providers (Himalayas, Jobicy, Adzuna) intentionally do, and their
     * requests are byte-identical with or without a context.
     *
     * Backward compatibility, and why this is an OPTIONAL parameter rather
     * than a new method:
     * - Existing zero-argument call sites (`retrieve()`) remain valid,
     *   because the parameter defaults to null and passing no context is
     *   indistinguishable from the old zero-argument call.
     * - Every implementer must declare the parameter — PHP makes an
     *   implementing method omitting an interface's optional parameter a
     *   fatal error — and the three existing implementations are updated
     *   in the same commit as this interface.
     * - A separate retrieveWithContext() would force every provider to
     *   implement two methods for one operation and every orchestrator to
     *   branch between them. An optional argument is the minimal change.
     *
     * @return DiscoveredJobCandidate[]
     *
     * @throws DiscoveryProviderException
     */
    public function retrieve(?ProviderDiscoveryContext $context = null): array;
}
