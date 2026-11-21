<?php

namespace App\Support\JobFreshness\Pilot;

use App\Models\JobPosting;
use App\Support\JobFreshness\HimalayasLivenessInterpreter;
use App\Support\JobFreshness\JobicyStatusInterpreter;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Runs the approved pilot against a TRANSPORT (offline: recorded answers; the real HTTP transport only under test). It reads the manifest's rows, writes NOTHING to the
 * database, never retries, never paginates, never follows a redirect and never sends more than the cap. It returns an evidence array and an observations list for offline replay.
 *
 * Stop conditions (the run ends at once, keeping what it has): 403; 429 (Retry-After is recorded and respected by STOPPING); any 5xx; any redirect; a content type that is not JSON;
 * a body over the limit; malformed JSON; two Himalayas 404s in a row; a connection failure or any interruption; the request cap.
 */
final class PilotRunner
{
    /** @var callable(int): void */
    private $sleeper;

    /** @param  (callable(int): void)|null  $sleeper  called with the seconds to wait BEFORE every request after the first (the minimum gap) */
    public function __construct(private readonly PilotTransport $transport, ?callable $sleeper = null, private readonly ?CarbonImmutable $now = null)
    {
        $this->sleeper = $sleeper ?? static function (int $seconds): void {};
    }

    /**
     * @return array{evidence: array<string, mixed>, observations: list<array{job_id: int, observations: list<array<string, mixed>>}>}
     *
     * @throws PilotRefused before any request, for anything that does not match the approved manifest
     */
    public function run(PilotManifest $manifest, string $mode): array
    {
        $manifest->assertMatchesApproved();
        $plan = $manifest->resolve();   // identities re-read and verified; nothing has been requested yet
        $now = $this->now ?? CarbonImmutable::now();

        $steps = [];
        foreach ($plan['himalayas'] as $row) {
            $steps[] = ['provider' => 'himalayas', 'host' => (string) parse_url($row['url'], PHP_URL_HOST), 'url' => $row['url'], 'jobs' => [$row['job']]];
        }
        $steps[] = ['provider' => 'jobicy', 'host' => (string) parse_url($plan['jobicy']['url'], PHP_URL_HOST), 'url' => $plan['jobicy']['url'], 'jobs' => $plan['jobicy']['jobs']];

        $evidence = [
            'schema' => 'job-freshness-pilot-evidence', 'version' => 1, 'mode' => $mode, 'manifest_sha256' => $manifest->sha256, 'started_at' => $now->toIso8601String(),
            'planned_requests' => count($steps), 'request_cap' => PilotManifest::REQUEST_CAP, 'min_gap_seconds' => PilotManifest::MIN_GAP_SECONDS,
            'status' => 'completed', 'stopped_because' => null, 'requests_made' => 0, 'requests' => [],
        ];
        $observations = [];
        $himalayas = new HimalayasLivenessInterpreter;
        $jobicy = new JobicyStatusInterpreter;
        $consecutive404 = 0;

        try {
            foreach ($steps as $i => $step) {
                if ($evidence['requests_made'] >= PilotManifest::REQUEST_CAP) {
                    $evidence['status'] = 'stopped';
                    $evidence['stopped_because'] = 'request_cap';
                    break;
                }
                if ($i > 0) {
                    ($this->sleeper)(PilotManifest::MIN_GAP_SECONDS);
                }

                $started = hrtime(true);
                $stop = null;
                $evidence['requests_made']++;   // counted when attempted: a request that fails or is interrupted still counts against the cap
                try {
                    $response = $this->transport->get($step['url'], PilotManifest::TIMEOUT_SECONDS);
                } catch (PilotTransportException) {
                    $response = null;
                    $stop = 'connection_failure';
                }
                $elapsed = (int) round((hrtime(true) - $started) / 1_000_000);

                $record = ['seq' => $i + 1, 'provider' => $step['provider'], 'host' => $step['host'], 'local_job_ids' => array_map(fn (JobPosting $j) => $j->id, $step['jobs']), 'http_status' => $response?->status, 'elapsed_ms' => $elapsed, 'headers' => [], 'body_keys' => null];
                $check = $response === null ? ['http_status' => null] : ['http_status' => $response->status];

                if ($response !== null) {
                    $record['headers'] = array_intersect_key($response->headers, array_flip(['content-type', 'retry-after', 'cache-control', 'age']));
                    $retry = $response->headers['retry-after'] ?? null;
                    if ($retry !== null && ctype_digit(trim($retry))) {
                        $check['retry_after'] = (int) trim($retry);
                    }
                    $decoded = null;
                    $json = str_contains(strtolower($response->headers['content-type'] ?? ''), 'json');
                    if (strlen($response->body) > PilotManifest::MAX_BODY_BYTES) {
                        $stop = 'body_too_large';
                    } elseif ($json) {
                        $decoded = json_decode($response->body, true);
                        if (! is_array($decoded) && in_array($response->status, [200, 410], true)) {
                            $stop = 'malformed_json';
                        }
                    }
                    $stop ??= match (true) {
                        $response->status === 403 => 'forbidden',
                        $response->status === 429 => 'rate_limited',
                        $response->status >= 500 => 'server_error',
                        $response->status >= 300 && $response->status < 400 => 'redirect',
                        ! $json => 'non_json_content_type',
                        default => null,
                    };
                    if (is_array($decoded)) {
                        $record['body_keys'] = self::keys($decoded);
                        $check['body'] = $step['provider'] === 'himalayas' ? ['expiryDate' => self::scalarExpiry($decoded['expiryDate'] ?? null)] : self::jobicyBody($decoded, array_map(fn (JobPosting $j) => (string) $j->discovery_source_id, $step['jobs']));
                    }
                    $consecutive404 = ($step['provider'] === 'himalayas' && $response->status === 404) ? $consecutive404 + 1 : 0;
                    if ($stop === null && $consecutive404 >= 2) {
                        $stop = 'two_consecutive_404';
                    }
                }

                $record['check'] = $check;
                $evidence['requests'][] = $record;

                foreach ($step['jobs'] as $job) {
                    $obs = $step['provider'] === 'himalayas'
                        ? $himalayas->interpret(HimalayasLivenessInterpreter::storedSeconds($job->discovery_metadata['discovery']['expiryDate'] ?? null), $check, $now)
                        : [$jobicy->interpret((string) $job->discovery_source_id, $check, $now)];
                    $observations[] = ['job_id' => $job->id, 'observations' => array_map(fn ($o) => $o->toArray(), $obs)];
                }

                if ($stop !== null) {
                    $evidence['status'] = 'stopped';
                    $evidence['stopped_because'] = $stop;
                    break;
                }
            }
        } catch (Throwable $e) {
            $evidence['status'] = 'interrupted';
            $evidence['stopped_because'] = 'interrupted';
            $evidence['interrupted_by'] = class_basename($e);
        }

        $evidence['finished_at'] = ($this->now ?? CarbonImmutable::now())->toIso8601String();

        return ['evidence' => $evidence, 'observations' => $observations];
    }

    /** @return list<string> top-level key NAMES only, bounded and sanitised */
    private static function keys(array $body): array
    {
        $keys = [];
        foreach (array_keys($body) as $k) {
            if (is_string($k) && preg_match('/^[A-Za-z0-9_]{1,40}$/', $k) === 1) {
                $keys[] = $k;
            }
            if (count($keys) >= 50) {
                break;
            }
        }

        return $keys;
    }

    /** An expiry value is kept only if it is a plain number or a short date-like string; nothing else from the body survives. */
    private static function scalarExpiry(mixed $v): int|string|null
    {
        if (is_int($v)) {
            return $v;
        }

        return is_string($v) && preg_match('/^[0-9TZ:+.\- ]{1,40}$/', $v) === 1 ? $v : null;
    }

    /**
     * Only the requested ids with a recognised status survive; everything else in the body is dropped.
     *
     * @param  list<string>  $ids
     * @return array{jobs: list<array{id: int, status: string}>}
     */
    private static function jobicyBody(array $body, array $ids): array
    {
        $out = [];
        foreach (['jobs', 'results'] as $key) {
            foreach (is_array($body[$key] ?? null) ? $body[$key] : [] as $row) {
                $id = is_array($row) ? ($row['id'] ?? null) : null;
                $status = is_array($row) && is_string($row['status'] ?? null) ? strtolower($row['status']) : null;
                if ((is_int($id) || (is_string($id) && ctype_digit($id))) && in_array((string) $id, $ids, true) && in_array($status, ['active', 'closed', 'unknown'], true)) {
                    $out[] = ['id' => (int) $id, 'status' => $status];
                }
            }
        }

        return ['jobs' => $out];
    }
}
