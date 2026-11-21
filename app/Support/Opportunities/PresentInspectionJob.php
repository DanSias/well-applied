<?php

namespace App\Support\Opportunities;

use App\Models\JobPosting;
use App\Support\ApplicationInspection\PresentApplicationInspection;
use App\Support\JobDiscovery\DestinationMetadata;
use App\Support\JobDiscovery\ProviderAttribution;

/**
 * What the inspection drawer is handed for one job: the job, and its Application's inspection state when one exists.
 * Shared by the Opportunities list and the Opportunity workspace, so both drawers read the same shape.
 */
final class PresentInspectionJob
{
    public function __construct(private readonly PresentApplicationInspection $inspection) {}

    /**
     * @return array<string, mixed>
     */
    public function present(JobPosting $job): array
    {
        $application = $job->applications()->first();

        return [
            'id' => $job->id,
            'title' => $job->title,
            'company' => $job->company,
            'source_url' => $job->source_url,
            'attribution' => ProviderAttribution::for($job),
            // Candidate destinations a provider supplied: unverified suggestions, shown redacted. Never the Application URL.
            'destinations' => DestinationMetadata::view($job),
            'application_id' => $application?->id,
            'inspection' => $application !== null ? $this->inspection->present($application) : null,
        ];
    }
}
