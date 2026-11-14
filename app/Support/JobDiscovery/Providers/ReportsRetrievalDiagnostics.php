<?php

namespace App\Support\JobDiscovery\Providers;

/**
 * Implemented by a provider that can say what happened to the raw feed entries BEFORE the global filter, so the attempt can
 * record stages that candidates_retrieved alone hides. Only what the provider actually observed is reported; a provider
 * that observes nothing does not implement this, and the attempt stores null rather than a guess.
 *
 * Keys (all optional, all counts of the last retrieve()):
 *   raw_entries            entries the provider's response held, before normalization
 *   normalization_dropped  entries that could not be normalized into a candidate (missing id/title/company)
 *   search_rows            (Eightfold) rows returned by the search API, across tenants
 *   unique_positions       (Eightfold) distinct positions among them
 *   prefilter_dropped      (Eightfold) map of provider prefilter reason => count, dropped before any detail fetch
 */
interface ReportsRetrievalDiagnostics
{
    /**
     * @return array<string, int|array<string, int>>
     */
    public function retrievalDiagnostics(): array;
}
