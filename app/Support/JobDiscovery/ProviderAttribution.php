<?php

namespace App\Support\JobDiscovery;

use App\Enums\JobDiscoverySource;
use App\Models\JobPosting;

/**
 * What a discovery provider's terms require, in one place: the credit shown wherever a job it supplied is displayed, how its
 * stored link may be labelled, and whether its Apply link is mandated. This registry is the single source for both the UI
 * label (for()) and the persisted `discovery_metadata.discovery.attribution` (see DestinationMetadata).
 *
 *  - Jobicy (public API): credit Jobicy with a link, keep the canonical Jobicy listing URL. The stored source_url IS that
 *    listing, so it is "View on Jobicy", never the employer's "original posting".
 *  - Himalayas: every Apply action must use the API's `applicationLink` UNCHANGED, and a visible link to Himalayas as the source
 *    must be shown. The stored source_url IS that applicationLink, so it is the Himalayas apply link, and no candidate or resolved
 *    employer URL may replace it.
 *
 * Any other source has no rules here: Adzuna's terms are unverified, USAJOBS and Eightfold URLs are the employer's own, and
 * manual jobs have no provider. This describes how a stored URL is LABELLED and credited; it never changes the URL.
 */
final class ProviderAttribution
{
    /** Restriction codes a destination or attribution may carry. Anything else is dropped. */
    public const RESTRICTIONS = [
        'credit_required',
        'listing_url_must_be_preserved',
        'apply_action_must_use_application_link',
        'no_redistribution_to_other_job_boards',
        'remove_when_expired',
    ];

    /**
     * @return array{provider: string, name: string, home_url: string, link_label: string, notice: string, kind: string, restrictions: list<string>, apply_link_mandated: bool, apply_link_policy: string}|null
     */
    public static function rules(?JobDiscoverySource $source): ?array
    {
        return match ($source) {
            JobDiscoverySource::Jobicy => [
                'provider' => 'jobicy',
                'name' => 'Jobicy',
                'home_url' => 'https://jobicy.com',
                'link_label' => 'View on Jobicy ↗',
                'notice' => 'Listing provided by',
                'kind' => 'canonical_listing',
                'restrictions' => ['credit_required', 'listing_url_must_be_preserved'],
                'apply_link_mandated' => false,
                'apply_link_policy' => 'public_tier_apply_links_use_the_listing_url',
            ],
            JobDiscoverySource::Himalayas => [
                'provider' => 'himalayas',
                'name' => 'Himalayas',
                'home_url' => 'https://himalayas.app',
                'link_label' => 'Apply on Himalayas ↗',
                'notice' => 'Job provided by',
                'kind' => 'apply_link',
                'restrictions' => ['credit_required', 'apply_action_must_use_application_link', 'no_redistribution_to_other_job_boards', 'remove_when_expired'],
                'apply_link_mandated' => true,
                'apply_link_policy' => 'use_application_link_unchanged',
            ],
            default => null,
        };
    }

    /**
     * What the UI shows for a job. `kind` is 'canonical_listing' (Jobicy's own page), 'apply_link' (Himalayas' applicationLink, to be
     * used unchanged) or 'credit_only': the credit is still owed, but the stored link is no longer the provider's (canonical
     * enrichment replaced it with the authoritative ATS URL), so it keeps the generic label.
     *
     * @return array{provider: string, name: string, home_url: string, link_label: string, notice: string, kind: string}|null
     */
    public static function for(JobPosting $job): ?array
    {
        $rules = self::rules($job->discovery_source);
        if ($rules === null) {
            return null;
        }

        $creditOnly = $job->canonical_source !== null;

        return [
            'provider' => $rules['provider'],
            'name' => $rules['name'],
            'home_url' => $rules['home_url'],
            'link_label' => $creditOnly ? 'Original posting ↗' : $rules['link_label'],
            'notice' => $rules['notice'],
            'kind' => $creditOnly ? 'credit_only' : $rules['kind'],
        ];
    }
}
