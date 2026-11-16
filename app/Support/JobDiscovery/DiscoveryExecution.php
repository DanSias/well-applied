<?php

namespace App\Support\JobDiscovery;

use App\Enums\JobDiscoverySource;
use App\Models\DiscoveryRun;

/**
 * What one call to RunJobDiscovery::execute() did: the run it recorded, and the providers it did not execute because
 * another execution held their lease. A skipped provider has no attempt, so it can never read as a success.
 *
 * When nothing was executed only because everything requested was busy, the run is not kept (an empty "successful" run
 * would be a lie): `recorded()` is false and the run is deleted.
 */
final class DiscoveryExecution
{
    /**
     * @param  list<JobDiscoverySource>  $skipped
     */
    public function __construct(
        public readonly DiscoveryRun $run,
        public readonly array $skipped = [],
    ) {}

    public function recorded(): bool
    {
        return $this->run->exists;
    }
}
