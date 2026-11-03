<?php

namespace App\Support\ResumeProfile;

use App\Enums\JobDiscoverySource;
use App\Support\JobDiscovery\Providers\AdzunaDiscoveryProvider;
use App\Support\JobDiscovery\Providers\DiscoveryProviderContract;
use App\Support\JobDiscovery\Providers\HimalayasDiscoveryProvider;
use App\Support\JobDiscovery\Providers\JobicyDiscoveryProvider;

/**
 * The existing discovery providers, used for retrieval only: their
 * `retrieve()` returns normalized candidates and writes nothing, so sampling
 * stays out of job tracking. Bound in the container so tests can substitute
 * fakes.
 */
class SamplingProviders
{
    /**
     * @return array<string, DiscoveryProviderContract>
     */
    public function all(): array
    {
        return [
            JobDiscoverySource::Himalayas->value => new HimalayasDiscoveryProvider,
            JobDiscoverySource::Jobicy->value => new JobicyDiscoveryProvider,
            JobDiscoverySource::Adzuna->value => new AdzunaDiscoveryProvider,
        ];
    }
}
