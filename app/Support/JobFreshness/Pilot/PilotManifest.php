<?php

namespace App\Support\JobFreshness\Pilot;

use App\Enums\JobDiscoverySource;
use App\Models\JobPosting;
use App\Support\JobFreshness\CheckRequests;

/**
 * The approved sample, frozen. It holds local job ids and one-way identity fingerprints of each job's provider key (source, source id, company and job slug): no URL,
 * slug, title or company name. Its SHA-256 (of the file's bytes) is what an authorization names; the runner refuses a file whose hash is not the approved one, so ANY change
 * (an id added, removed or reordered, a limit changed) needs a new authorization and a new approved hash in config.
 */
final class PilotManifest
{
    public const SCHEMA = 'job-freshness-pilot-manifest';

    public const VERSION = 1;

    public const HIMALAYAS_JOBS = 8;

    public const JOBICY_JOBS = 23;

    public const PLANNED_REQUESTS = 9;

    public const REQUEST_CAP = 10;

    public const MIN_GAP_SECONDS = 2;

    public const TIMEOUT_SECONDS = 10;

    public const MAX_BODY_BYTES = 262144;

    public const HOSTS = ['himalayas.app', 'jobicy.com'];

    /**
     * @param  list<array{job_id: int, identity: string}>  $himalayas
     * @param  list<array{job_id: int, identity: string}>  $jobicy
     */
    public function __construct(public readonly array $himalayas, public readonly array $jobicy, public readonly string $generatedAt, public readonly string $sha256 = '') {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA, 'version' => self::VERSION, 'generated_at' => $this->generatedAt,
            'limits' => ['planned_requests' => self::PLANNED_REQUESTS, 'request_cap' => self::REQUEST_CAP, 'min_gap_seconds' => self::MIN_GAP_SECONDS, 'retries' => 0, 'pagination' => false, 'timeout_seconds' => self::TIMEOUT_SECONDS, 'max_body_bytes' => self::MAX_BODY_BYTES],
            'hosts' => self::HOSTS,
            'himalayas' => $this->himalayas,
            'jobicy' => $this->jobicy,
        ];
    }

    public function json(): string
    {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    }

    /**
     * Builds the manifest from the CURRENT rows (read only). Refuses a job that is missing, of the wrong source, or that cannot be checked.
     *
     * @param  list<int>  $himalayasIds
     * @param  list<int>  $jobicyIds
     */
    public static function build(array $himalayasIds, array $jobicyIds, string $generatedAt): self
    {
        $h = self::fingerprints($himalayasIds, JobDiscoverySource::Himalayas);
        $j = self::fingerprints($jobicyIds, JobDiscoverySource::Jobicy);
        $m = new self($h, $j, $generatedAt);
        $m->assertShape();

        return $m;
    }

    /** @throws PilotRefused */
    public static function fromFile(string $path): self
    {
        $raw = is_file($path) ? file_get_contents($path) : false;
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (! is_array($data) || ($data['schema'] ?? null) !== self::SCHEMA || ($data['version'] ?? null) !== self::VERSION) {
            throw new PilotRefused('manifest_unreadable', 'The manifest is missing, malformed, or not schema version '.self::VERSION.'.');
        }
        $m = new self(self::rows($data['himalayas'] ?? null), self::rows($data['jobicy'] ?? null), is_string($data['generated_at'] ?? null) ? $data['generated_at'] : '', hash('sha256', $raw));
        // Every field must be exactly what this code would have written: an edited limit or host list is a different manifest.
        $expected = $m->toArray();
        if ($data !== $expected) {
            throw new PilotRefused('manifest_altered', 'The manifest differs from the approved schema (limits, hosts or structure were changed).');
        }
        $m->assertShape();

        return $m;
    }

    /** @throws PilotRefused when this file's bytes are not the approved manifest (the runner checks this itself, whoever calls it) */
    public function assertMatchesApproved(): void
    {
        $approved = config('job_freshness.pilot.approved_manifest_sha256');
        if (! is_string($approved) || $approved === '' || ! hash_equals($approved, $this->sha256)) {
            throw new PilotRefused('manifest_not_approved', 'This manifest\'s SHA-256 is not the approved one: any change needs a new authorization.');
        }
    }

    /** @throws PilotRefused */
    public function assertApproved(?string $presentedHash): void
    {
        $this->assertMatchesApproved();
        $approved = (string) config('job_freshness.pilot.approved_manifest_sha256');
        if (! is_string($presentedHash) || ! hash_equals($approved, strtolower($presentedHash))) {
            throw new PilotRefused('manifest_hash_mismatch', 'The --manifest-hash given is not the approved manifest hash.');
        }
    }

    /**
     * Re-reads every selected row and checks its identity against the manifest. Nothing is requested before this passes.
     *
     * @return array{himalayas: array<int, array{job: JobPosting, url: string}>, jobicy: array{jobs: array<int, JobPosting>, url: string, ids: array<int, string>}}
     *
     * @throws PilotRefused
     */
    public function resolve(): array
    {
        $this->assertShape();
        $problems = [];
        $himalayas = [];
        $jobs = JobPosting::query()->whereIn('id', [...array_column($this->himalayas, 'job_id'), ...array_column($this->jobicy, 'job_id')])->get()->keyBy('id');

        foreach ($this->himalayas as $row) {
            $job = $jobs[$row['job_id']] ?? null;
            $spec = $job === null ? null : CheckRequests::himalayas($job);
            if ($job === null || $spec === null || self::identity($job) !== $row['identity']) {
                $problems[] = $row['job_id'];

                continue;
            }
            $himalayas[] = ['job' => $job, 'url' => $spec['url']];
        }
        $jobicyJobs = [];
        foreach ($this->jobicy as $row) {
            $job = $jobs[$row['job_id']] ?? null;
            if ($job === null || $job->discovery_source !== JobDiscoverySource::Jobicy || ! ctype_digit((string) $job->discovery_source_id) || self::identity($job) !== $row['identity']) {
                $problems[] = $row['job_id'];

                continue;
            }
            $jobicyJobs[] = $job;
        }
        if ($problems !== []) {
            throw new PilotRefused('identity_mismatch', 'The source record changed or is missing for local job ids: '.implode(', ', $problems).'. A changed sample needs a new authorization.');
        }
        $batches = CheckRequests::jobicyBatches($jobicyJobs);
        if (count($batches) !== 1) {
            throw new PilotRefused('unexpected_batching', 'The Jobicy ids do not fit exactly one status request.');
        }
        $planned = count($himalayas) + 1;
        if ($planned !== self::PLANNED_REQUESTS || $planned > self::REQUEST_CAP) {
            throw new PilotRefused('request_count', 'The plan is not exactly '.self::PLANNED_REQUESTS.' requests.');
        }
        foreach ([...array_column($himalayas, 'url'), $batches[0]['url']] as $url) {
            if (parse_url($url, PHP_URL_SCHEME) !== 'https' || ! in_array(parse_url($url, PHP_URL_HOST), self::HOSTS, true)) {
                throw new PilotRefused('host_not_in_manifest', 'A planned request is not an https request to a manifest host.');
            }
        }

        return ['himalayas' => $himalayas, 'jobicy' => ['jobs' => $jobicyJobs, 'url' => $batches[0]['url'], 'ids' => $batches[0]['ids']]];
    }

    /** One-way fingerprint of the provider key a request is built from. */
    public static function identity(JobPosting $job): string
    {
        $company = $job->discovery_metadata['discovery']['companySlug'] ?? '';
        $slug = is_string($job->source_url) && preg_match('~^/companies/[^/]+/jobs/([^/]+)/?$~', (string) parse_url($job->source_url, PHP_URL_PATH), $m) === 1 ? $m[1] : '';

        return hash('sha256', implode('|', ['identity-v1', $job->discovery_source->value, (string) $job->discovery_source_id, $job->discovery_source === JobDiscoverySource::Himalayas ? (string) $company : '', $job->discovery_source === JobDiscoverySource::Himalayas ? $slug : '']));
    }

    /** @throws PilotRefused */
    private function assertShape(): void
    {
        $ids = [...array_column($this->himalayas, 'job_id'), ...array_column($this->jobicy, 'job_id')];
        if (count($this->himalayas) !== self::HIMALAYAS_JOBS || count($this->jobicy) !== self::JOBICY_JOBS || count($ids) !== count(array_unique($ids))) {
            throw new PilotRefused('manifest_shape', 'The manifest must name exactly '.self::HIMALAYAS_JOBS.' Himalayas and '.self::JOBICY_JOBS.' Jobicy distinct jobs.');
        }
    }

    /** @return list<array{job_id: int, identity: string}> */
    private static function fingerprints(array $ids, JobDiscoverySource $source): array
    {
        $jobs = JobPosting::query()->whereIn('id', $ids)->get()->keyBy('id');
        $out = [];
        foreach ($ids as $id) {
            $job = $jobs[$id] ?? null;
            if ($job === null || $job->discovery_source !== $source) {
                throw new PilotRefused('identity_mismatch', "Local job {$id} is missing or is not a {$source->value} job.");
            }
            $out[] = ['job_id' => (int) $id, 'identity' => self::identity($job)];
        }

        return $out;
    }

    /** @return list<array{job_id: int, identity: string}> */
    private static function rows(mixed $rows): array
    {
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $r) {
            if (! is_array($r) || ! is_int($r['job_id'] ?? null) || ! is_string($r['identity'] ?? null) || preg_match('/^[0-9a-f]{64}$/', $r['identity']) !== 1) {
                throw new PilotRefused('manifest_unreadable', 'A manifest row is malformed.');
            }
            $out[] = ['job_id' => $r['job_id'], 'identity' => $r['identity']];
        }

        return $out;
    }
}
