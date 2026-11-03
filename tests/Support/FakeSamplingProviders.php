<?php

namespace Tests\Support;

use App\Support\ResumeProfile\SamplingProviders;

final class FakeSamplingProviders extends SamplingProviders
{
    /** @param array<string, FakeDiscoveryProvider> $providers */
    public function __construct(public array $providers) {}

    public function all(): array
    {
        return $this->providers;
    }
}
