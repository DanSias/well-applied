<?php

namespace App\Support\ResumeProfile;

use App\Models\ResumeProfile;

/**
 * "Build My Profile": study the market, then interview. Three deterministic
 * steps (collect a sample, aggregate its requirements, rank and open the
 * interview); no model call anywhere.
 */
final class BuildResumeProfile
{
    public function __construct(
        private readonly MarketSampleCollector $collector,
        private readonly AggregateMarketRequirements $aggregate,
        private readonly ProfileInterview $interview,
        private readonly QuestionEnrichment $enrichment,
    ) {}

    /**
     * @return array{sample_id: int, included: int, considered: int, decisions: array<string, int>, providers: array<int, array<string, mixed>>, extraction: array{extracted: int, cached: int, postings: int}, questions: int, enrichment: array<string, mixed>|null, seconds: float}
     */
    public function run(ResumeProfile $profile, bool $pilot = false, bool $fetch = true, bool $force = false, ?bool $enrich = null): array
    {
        $started = microtime(true);
        $sample = $this->collector->collect($profile, $pilot, $fetch, $force);
        $extraction = $this->aggregate->run($sample);
        $session = $this->interview->sync($profile->refresh());

        // Compute is spent here, while the profile is built, never on a page load.
        $enrichment = ($enrich ?? (bool) config('resume_profiles.enrichment.enabled')) ? $this->enrichment->enrich($profile->refresh()) : null;

        return [
            'sample_id' => $sample->id,
            'included' => $sample->includedPostings()->count(),
            'considered' => $sample->postings()->count(),
            'decisions' => $sample->decision_counts ?? [],
            'providers' => $sample->provider_log ?? [],
            'extraction' => $extraction,
            'questions' => $session->questions()->count(),
            'enrichment' => $enrichment,
            'seconds' => round(microtime(true) - $started, 2),
        ];
    }
}
