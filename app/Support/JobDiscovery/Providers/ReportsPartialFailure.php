<?php

namespace App\Support\JobDiscovery\Providers;

/**
 * Implemented by a provider that fans out to several independent sources
 * (Eightfold tenants) and can succeed for some while failing for others.
 *
 * retrieve() still returns what it gathered and throws only when nothing
 * could be retrieved; afterwards the orchestrator asks this interface
 * whether the run was fully healthy, so a partially failed run is never
 * recorded as a clean success.
 */
interface ReportsPartialFailure
{
    /**
     * A short, human-readable description of what failed during the last
     * retrieve(), or null if every source completed cleanly.
     */
    public function partialFailureSummary(): ?string;
}
