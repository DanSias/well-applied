<?php

namespace App\Support\JobFreshness;

use App\Enums\JobDiscoverySource;
use App\Models\JobPosting;

/**
 * The provider checks a later, separately approved runner would make, as PURE specifications: nothing here sends a request. They exist so the dry-run can say how many
 * requests a real check would need, and so the provider restrictions are written down beside the URLs.
 *
 *  - Himalayas: one GET per job, `{himalayas base}/companies/{companySlug}/jobs/{slug}` (200 open, 410 closed with expiryDate, 404 not found; cached up to 10 minutes). The slug is the
 *    last path segment of the stored applicationLink, which is READ, never changed. Free limits are lower without a key (unstated); 60/min with one.
 *  - Jobicy: one GET per up to 100 ids, `{status url}?ids=1,2,3` (free, `active|closed|unknown`). At most 1 request per second per IP; automated passes no more than hourly.
 */
final class CheckRequests
{
    public const JOBICY_BATCH = 100;

    /** @return array{method: string, url: string, limits: string}|null null when the job's stored link does not carry what the check needs */
    public static function himalayas(JobPosting $job): ?array
    {
        if ($job->discovery_source !== JobDiscoverySource::Himalayas) {
            return null;
        }
        $company = $job->discovery_metadata['discovery']['companySlug'] ?? null;
        $path = is_string($job->source_url) ? (string) parse_url($job->source_url, PHP_URL_PATH) : '';
        if (! is_string($company) || preg_match('/^[a-z0-9][a-z0-9-]{0,80}$/i', $company) !== 1 || preg_match('~^/companies/([^/]+)/jobs/([^/]+)/?$~', $path, $m) !== 1 || strcasecmp($m[1], $company) !== 0) {
            return null;
        }

        return ['method' => 'GET', 'url' => rtrim((string) config('services.himalayas.base_url'), '/').'/companies/'.rawurlencode($company).'/jobs/'.rawurlencode($m[2]), 'limits' => 'one request per job; no key: lower unstated limit; with key 60/min'];
    }

    /**
     * @param  list<JobPosting>  $jobs  Jobicy jobs
     * @return list<array{method: string, url: string, ids: list<string>, limits: string}>
     */
    public static function jobicyBatches(array $jobs): array
    {
        $ids = [];
        foreach ($jobs as $job) {
            if ($job->discovery_source === JobDiscoverySource::Jobicy && is_string($job->discovery_source_id) && ctype_digit($job->discovery_source_id)) {
                $ids[] = $job->discovery_source_id;
            }
        }

        return array_map(fn (array $chunk) => ['method' => 'GET', 'url' => (string) config('job_freshness.jobicy_status_url').'?ids='.implode(',', $chunk), 'ids' => $chunk, 'limits' => 'one request per batch; at most 1 request/second/IP; passes no more than hourly'], array_chunk($ids, self::JOBICY_BATCH));
    }
}
