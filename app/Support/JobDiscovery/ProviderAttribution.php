<?php

namespace App\Support\JobDiscovery;

use App\Enums\JobDiscoverySource;
use App\Models\JobPosting;

/**
 * The credit a discovery provider's terms require wherever a job it supplied is shown, and what its link may be called.
 *
 *  - Jobicy (public API): "credit Jobicy and link to the source", and keep the canonical Jobicy listing URL. The stored
 *    source_url IS that canonical listing, so it is shown as "View on Jobicy", never as the employer's "original posting".
 *  - Himalayas: every Apply action must use the API's `applicationLink` UNCHANGED, and a visible link to Himalayas as the source
 *    must be shown. The stored source_url IS that applicationLink, so it is labelled as the Himalayas apply link, and it is
 *    never replaced by a resolved employer URL.
 *
 * Any other source returns null (its link keeps the generic "Original posting" wording): Adzuna's terms are unverified,
 * USAJOBS and Eightfold URLs are the employer's own, and manual jobs have no provider. This describes how a stored URL is
 * LABELLED and credited; it never changes the URL.
 */
final class ProviderAttribution
{
    /**
     * @return array{provider: string, name: string, home_url: string, link_label: string, notice: string, kind: string}|null
     */
    public static function for(JobPosting $job): ?array
    {
        return match ($job->discovery_source) {
            JobDiscoverySource::Jobicy => [
                'provider' => 'jobicy',
                'name' => 'Jobicy',
                'home_url' => 'https://jobicy.com',
                'link_label' => 'View on Jobicy ↗',
                'notice' => 'Listing provided by',
                'kind' => 'canonical_listing',
            ],
            JobDiscoverySource::Himalayas => [
                'provider' => 'himalayas',
                'name' => 'Himalayas',
                'home_url' => 'https://himalayas.app',
                'link_label' => 'Apply on Himalayas ↗',
                'notice' => 'Job provided by',
                'kind' => 'apply_link',
            ],
            default => null,
        };
    }
}
