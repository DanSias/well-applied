<?php

namespace App\Support\ResumeProfile;

use App\Enums\DescriptionCompleteness;
use App\Enums\JobDiscoverySource;
use App\Enums\JobRemoteStatus;
use App\Models\JobPosting;
use App\Models\MarketSamplePosting;
use App\Support\JobDiscovery\DiscoveredJobCandidate;
use Carbon\CarbonImmutable;

/**
 * One posting under consideration, whether it came from the stored
 * job_postings or straight from a provider. Immutable: decisions are recorded
 * beside it, never on it.
 */
final readonly class SampleCandidate
{
    public function __construct(
        public string $provider,
        public ?string $sourceJobId,
        public ?string $sourceUrl,
        public string $company,
        public string $title,
        public ?string $location,
        public ?string $remoteStatus,
        public DescriptionCompleteness $completeness,
        public string $text,
        public string $contentHash,
        public ?CarbonImmutable $postedAt,
        public ?int $jobPostingId,
        public ?DiscoveredJobCandidate $raw = null,
    ) {}

    public static function fromStored(JobPosting $job): self
    {
        $text = PostingText::clean($job->description);
        $postedAt = $job->posted_at ?? $job->source_updated_at ?? $job->discovered_at;

        // The same normalized shape providers return, so the discovery filter judges both alike.
        $raw = new DiscoveredJobCandidate(
            $job->discovery_source, $job->discovery_source_id ?? 'job-'.$job->id, $job->company, $job->title, $text, $job->description_completeness,
            $job->location, $job->remote_status, $job->employment_type, null, null, null, null, $job->source_url, $postedAt, $job->source_updated_at,
            $job->discovered_at, (array) ($job->discovery_metadata ?? []),
        );

        return new self(
            $job->discovery_source->value, $job->discovery_source_id, $job->source_url, $job->company, $job->title, $job->location,
            $job->remote_status?->value, $job->description_completeness, $text, PostingText::hash($text), $postedAt, $job->id, $raw,
        );
    }

    /** A posting retrieved by an earlier sample: reused instead of asking the provider again. */
    public static function fromPriorRow(MarketSamplePosting $row): self
    {
        $text = (string) $row->description;
        $postedAt = $row->posted_at === null ? null : CarbonImmutable::instance($row->posted_at);
        $raw = new DiscoveredJobCandidate(
            JobDiscoverySource::from($row->provider), $row->source_job_id ?? 'row-'.$row->id, $row->company, $row->title, $text,
            DescriptionCompleteness::from($row->description_completeness), $row->location,
            $row->remote_status === null ? null : JobRemoteStatus::tryFrom($row->remote_status), null, null, null, null, null, $row->source_url,
            $postedAt, null, CarbonImmutable::instance($row->retrieved_at),
        );

        return new self($row->provider, $row->source_job_id, $row->source_url, $row->company, $row->title, $row->location, $row->remote_status,
            DescriptionCompleteness::from($row->description_completeness), $text, $row->content_hash, $postedAt, null, $raw);
    }

    public static function fromDiscovered(DiscoveredJobCandidate $c): self
    {
        $text = PostingText::clean((string) $c->description);

        return new self(
            $c->source->value, $c->sourceJobId, $c->applicationUrl, $c->company, $c->title, $c->location, $c->remoteStatus?->value,
            $c->descriptionCompleteness, $text, PostingText::hash($text), $c->postedAt ?? $c->sourceUpdatedAt ?? $c->discoveredAt, null, $c,
        );
    }
}
