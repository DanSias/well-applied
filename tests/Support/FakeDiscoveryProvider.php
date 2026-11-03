<?php

namespace Tests\Support;

use App\Support\JobDiscovery\DiscoveredJobCandidate;
use App\Support\JobDiscovery\Providers\DiscoveryProviderContract;
use App\Support\JobDiscovery\Providers\ProviderDiscoveryContext;
use Throwable;

/** Canned provider: returns candidates, counts calls, or fails on demand. */
final class FakeDiscoveryProvider implements DiscoveryProviderContract
{
    public int $calls = 0;

    /** @param array<int, DiscoveredJobCandidate> $candidates */
    public function __construct(public array $candidates = [], public ?Throwable $fail = null) {}

    public function retrieve(?ProviderDiscoveryContext $context = null): array
    {
        $this->calls++;

        if ($this->fail !== null) {
            throw $this->fail;
        }

        return $this->candidates;
    }
}
