<?php

namespace App\Support\ApplicationInspection;

use App\Enums\JobDiscoverySource;
use App\Models\Application;

/**
 * Where an inspection should START, and where that URL came from. This is the only place the choice is made; the browser
 * then follows Apply links from there if it has to, and is the only thing that does. Five URLs are kept apart:
 *
 *  - source URL: where the job was discovered (job_postings.source_url). Never changed by any of this.
 *  - provider destination: an application URL a discovery provider supplied separately from the source URL.
 *  - application URL: where the application is completed (applications.application_url), set by a person ('user') or by
 *    an earlier inspection that reached a form ('resolved').
 *  - requested / final URLs: per run, in the run's evidence.
 *
 * Precedence: the Application's own URL, then a provider destination, then the source URL. A provider URL is not trusted
 * for coming from an API: every candidate passes InspectionUrl, and anything that does not is skipped, falling through to
 * the next. If nothing passes, the raw source URL is returned anyway (origin 'source'), so the worker's own navigation
 * checks refuse it and report why, rather than the run silently never starting.
 *
 * Only USAJobs currently supplies a destination distinct from the source URL (its ApplyURI list, kept in the posting's
 * discovery metadata). Jobicy, Himalayas and Adzuna supply their own page or tracking redirect as the source URL itself,
 * so there is nothing separate to expose for them: the browser follows the redirect or Apply link.
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

        if ($job->discovery_source !== JobDiscoverySource::Usajobs) {
            return null;
        }

        $uris = $job->discovery_metadata['discovery']['applyUris'] ?? [];

        foreach (is_array($uris) ? $uris : [] as $uri) {
            $url = InspectionUrl::acceptable($uri);

            if ($url !== null && InspectionUrl::comparable($url) !== InspectionUrl::comparable((string) $job->source_url)) {
                return $url;
            }
        }

        return null;
    }
}
