<?php

namespace App\Support\ApplicationInspection;

use App\Models\Application;
use App\Support\JobDiscovery\DestinationMetadata;

/**
 * Where an inspection should START, and where that URL came from. This is the only place the choice is made; the browser
 * then follows Apply links from there if it has to, and is the only thing that does. Five URLs are kept apart:
 *
 *  - source URL: where the job was discovered (job_postings.source_url). Never changed by any of this.
 *  - provider destination: a CANDIDATE application URL a discovery provider's API supplied separately from the source URL
 *    (discovery_metadata.discovery.destinations[], see DestinationMetadata). Unverified by construction; never the Application URL.
 *  - application URL: where the application is completed (applications.application_url), set by a person ('user') or by
 *    an earlier inspection that reached a form ('resolved').
 *  - requested / final URLs: per run, in the run's evidence.
 *
 * Precedence: the Application's own URL, then a provider destination, then the source URL. A provider URL is not trusted
 * for coming from an API: every candidate passes InspectionUrl, and anything that does not is skipped, falling through to
 * the next. If nothing passes, the raw source URL is returned anyway (origin 'source'), so the worker's own navigation
 * checks refuse it and report why, rather than the run silently never starting.
 *
 * Only USAJobs currently supplies a destination distinct from the source URL (its ApplyURI list). Jobicy (public tier),
 * Himalayas and Adzuna supply their own page or tracking redirect as the source URL itself, so there is nothing separate to
 * expose for them; a Jobicy commercial destination, when one is added, will arrive through the same destinations[] list.
 *
 * A provider-MANDATED Apply link (Himalayas' applicationLink, to be used unchanged) is never replaced by a candidate: such a job
 * yields no provider destination at all, so its start origin stays 'source'.
 */
final class ResolveInspectionStartUrl
{
    public const ORIGIN_USER = 'user';

    public const ORIGIN_RESOLVED = 'resolved';

    public const ORIGIN_PROVIDER = 'provider';

    public const ORIGIN_SOURCE = 'source';

    /**
     * @return array{url: string, origin: string, source_url: string|null}
     */
    public function resolve(Application $application): array
    {
        $job = $application->jobPosting;
        $source = (string) $job->source_url;

        $own = InspectionUrl::acceptable($application->application_url);
        if ($own !== null) {
            return [
                'url' => $own,
                'origin' => $application->application_url_origin === Application::URL_USER ? self::ORIGIN_USER : self::ORIGIN_RESOLVED,
                'source_url' => $source,
            ];
        }

        $provider = $this->providerDestination($application);
        if ($provider !== null) {
            return ['url' => $provider, 'origin' => self::ORIGIN_PROVIDER, 'source_url' => $source];
        }

        return [
            'url' => InspectionUrl::acceptable($source) ?? $source,
            'origin' => self::ORIGIN_SOURCE,
            'source_url' => $source,
        ];
    }

    private function providerDestination(Application $application): ?string
    {
        $job = $application->jobPosting;

        // A provider-mandated Apply link (Himalayas' applicationLink) is never replaced by a candidate destination, however it got there.
        if (DestinationMetadata::applyLinkMandated($job)) {
            return null;
        }

        // Candidate destinations a provider's API supplied (validated on every read; legacy USAJOBS applyUris included). A candidate is an input
        // to the inspection only: it is unverified, and it is never copied into applications.application_url.
        foreach (DestinationMetadata::read($job)['destinations'] as $candidate) {
            $url = InspectionUrl::acceptable($candidate['url']);

            if ($url !== null && InspectionUrl::comparable($url) !== InspectionUrl::comparable((string) $job->source_url)) {
                return $url;
            }
        }

        return null;
    }
}
