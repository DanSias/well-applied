<?php

namespace App\Support\ResumeProfile;

use App\Models\MarketSampleExclusion;
use App\Models\MarketSamplePosting;
use App\Models\ResumeProfile;

/**
 * Lets the user take a posting out of (or put it back into) a profile's market sample.
 *
 * Removing a posting records an exclusion that survives rebuilds, marks the row, and recomputes
 * the capability map and interview ranking from the remaining postings. Extraction is cached, so
 * recalculating is instant and makes no external call. The source job posting, if the row came from
 * one, and application tracking are never modified.
 */
final class SampleReview
{
    public function __construct(private readonly AggregateMarketRequirements $aggregate, private readonly ProfileInterview $interview) {}

    public function exclude(ResumeProfile $profile, MarketSamplePosting $posting, ?string $reason = null): void
    {
        MarketSampleExclusion::query()->firstOrCreate(
            ['resume_profile_id' => $profile->id, 'content_hash' => $posting->content_hash],
            [
                'provider' => $posting->provider, 'source_job_id' => $posting->source_job_id,
                'company_key' => PostingText::normalizeCompany($posting->company), 'title_key' => PostingText::normalizeTitle($posting->title),
                'company' => $posting->company, 'title' => $posting->title, 'reason' => $reason !== null && trim($reason) !== '' ? mb_substr(trim($reason), 0, 200) : null,
            ],
        );

        if ($posting->included) {
            $posting->update(['included' => false, 'decision' => 'user_excluded', 'decision_detail' => $reason !== null && trim($reason) !== '' ? 'You removed this posting: '.trim($reason) : 'You removed this posting from the sample.']);
        }

        $this->recalculate($profile);
    }

    public function restore(ResumeProfile $profile, MarketSamplePosting $posting): void
    {
        $key = PostingText::normalizeCompany($posting->company);
        $title = PostingText::normalizeTitle($posting->title);

        MarketSampleExclusion::query()->where('resume_profile_id', $profile->id)->where(fn ($q) => $q
            ->where('content_hash', $posting->content_hash)
            ->orWhere(fn ($w) => $w->where('company_key', $key)->where('title_key', $title)))->delete();

        if ($posting->decision === 'user_excluded') {
            $posting->update(['included' => true, 'decision' => MarketSampleCollector::INCLUDED, 'decision_detail' => null]);
        }

        $this->recalculate($profile);
    }

    private function recalculate(ResumeProfile $profile): void
    {
        $sample = $profile->currentSample;
        $sample->update(['decision_counts' => $sample->postings()->get()->countBy('decision')->all()]);

        $this->aggregate->run($sample);
        $profile->update(['revision' => $profile->revision + 1]);
        $this->interview->sync($profile->refresh());
    }
}
