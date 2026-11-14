<?php

namespace App\Support\JobDiscovery\Providers;

use App\Support\JobDiscovery\DiscoveredJobCandidate;

/**
 * Shared normalization step for the single-feed providers: maps raw entries to candidates, drops the ones that cannot be
 * normalized (as before), and now counts them so the drop is observable on the attempt.
 */
trait RecordsRetrievalDiagnostics
{
    /** @var array<string, int> */
    private array $diagnostics = [];

    /**
     * @return array<string, int>
     */
    public function retrievalDiagnostics(): array
    {
        return $this->diagnostics;
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     * @param  callable(array<string, mixed>): ?DiscoveredJobCandidate  $normalize
     * @return DiscoveredJobCandidate[]
     */
    private function normalizeEntries(array $entries, callable $normalize): array
    {
        $candidates = array_values(array_filter(array_map($normalize, $entries)));
        $this->diagnostics = [
            'raw_entries' => count($entries),
            'normalization_dropped' => count($entries) - count($candidates),
        ];

        return $candidates;
    }
}
