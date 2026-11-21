<?php

namespace App\Support\JobDiscovery\JobicyCommercial;

use App\Enums\DescriptionCompleteness;
use App\Enums\JobDiscoverySource;
use App\Support\ApplicationInspection\InspectionUrl;
use App\Support\JobDiscovery\DestinationMetadata;
use App\Support\JobDiscovery\DiscoveredJobCandidate;
use Carbon\CarbonImmutable;

/**
 * Compares one free-tier page with one Commercial-tier page of the SAME window, job by stable id. PURE: it takes decoded pages and returns a report; it makes no
 * request, reads no database and writes nothing. Its report contains aggregates and per-job classifications only: no title, company, description, query string,
 * slug or credential, and URLs only as host + path shape (UrlShape).
 *
 * What it does NOT claim: a sample of the last seven days of Jobicy's public feed is not Jobicy's historical inventory and says nothing about jobs older than that
 * window, so every report carries that scope explicitly. "Genuine destination" is a judgement about the URL's text (an employer or ATS URL), not about
 * reachability or the form behind it. Every candidate it produces is unverified.
 */
final class SampleEvaluator
{
    public const SCHEMA = 'jobicy-commercial-sample/1';

    /**
     * @param  list<mixed>  $publicJobs  the free page's `jobs`
     * @param  list<mixed>  $commercialJobs  the Commercial page's `jobs`
     * @param  array{commercial_total_cost_usd?: float|null, public_page_count?: int, commercial_page_count?: int}  $context
     * @return array<string, mixed>
     */
    public function evaluate(array $publicJobs, array $commercialJobs, array $context = []): array
    {
        $pub = $this->index($publicJobs);
        $com = $this->index($commercialJobs);

        $matched = [];
        $ambiguous = [];
        foreach (array_intersect(array_keys($pub['by_id']), array_keys($com['by_id'])) as $id) {
            if (isset($pub['duplicates'][$id]) || isset($com['duplicates'][$id])) {
                $ambiguous[$id] = 'duplicate_id';
            } elseif (! $this->sameJob($pub['by_id'][$id], $com['by_id'][$id])) {
                $ambiguous[$id] = 'identity_mismatch';
            } else {
                $matched[$id] = [$pub['by_id'][$id], $com['by_id'][$id]];
            }
        }
        foreach (array_keys($pub['duplicates'] + $com['duplicates']) as $id) {
            $ambiguous[$id] ??= 'duplicate_id';
        }
        ksort($matched);
        ksort($ambiguous);

        $publicOnly = array_values(array_diff(array_keys($pub['by_id']), array_keys($com['by_id']), array_keys($ambiguous)));
        $commercialOnly = array_values(array_diff(array_keys($com['by_id']), array_keys($pub['by_id']), array_keys($ambiguous)));
        $publicOnly = array_map('strval', $publicOnly);
        $commercialOnly = array_map('strval', $commercialOnly);
        sort($publicOnly, SORT_STRING);
        sort($commercialOnly, SORT_STRING);

        $rows = [];
        $classes = [];
        $platforms = [];
        $agreement = ['cost_and_url' => 0, 'cost_only' => 0, 'url_only' => 0, 'neither' => 0];
        $reconstructed = 0;
        $reconstructable = 0;
        $reconstructionMisses = [];
        $jobCostUsd = 0.0;
        $costReported = 0;
        $candidates = [];
        $acceptedByContract = 0;

        foreach ($matched as $id => [$p, $c]) {
            $listing = (string) $p['url'];
            $cost = is_numeric($c['job_request_cost'] ?? null) ? (float) $c['job_request_cost'] : null;
            $costReported += $cost !== null ? 1 : 0;
            $jobCostUsd += $cost ?? 0.0;

            $directByCost = $cost !== null && $cost > 0;
            $directByUrl = InspectionUrl::comparable((string) $c['url']) !== InspectionUrl::comparable($listing);
            $agreement[match (true) {
                $directByCost && $directByUrl => 'cost_and_url',
                $directByCost => 'cost_only',
                $directByUrl => 'url_only',
                default => 'neither',
            }]++;

            $slug = $c['jobSlug'] ?? $p['jobSlug'] ?? null;
            $reconstructionOk = null;
            if (is_string($slug) && $slug !== '') {
                $reconstructable++;
                $reconstructionOk = InspectionUrl::comparable('https://jobicy.com/jobs/'.$id.'-'.$slug) === InspectionUrl::comparable($listing);
                $reconstructionOk ? $reconstructed++ : ($reconstructionMisses[] = (string) $id);
            }

            $class = DestinationClassifier::classify($c['url'], $listing);
            $classes[$class['class']] = ($classes[$class['class']] ?? 0) + 1;
            $genuine = in_array($class['class'], DestinationClassifier::GENUINE, true);
            if ($class['platform'] !== null) {
                $platforms[$class['platform']] = ($platforms[$class['platform']] ?? 0) + 1;
            }

            $accepted = null;
            if ($genuine && $directByUrl) {
                [$candidate, $accepted] = $this->candidate((string) $id, $listing, (string) $c['url']);
                $acceptedByContract += $accepted ? 1 : 0;
                if ($accepted) {
                    $candidates[(string) $id] = $candidate;
                }
            }

            $rows[] = [
                'id' => (string) $id,
                'class' => $class['class'],
                'platform' => $class['platform'],
                'genuine' => $genuine,
                'destination' => UrlShape::of((string) $c['url']),
                'signals' => $class['signals'],
                'direct_by_cost' => $directByCost,
                'direct_by_url' => $directByUrl,
                'cost_cents' => $cost === null ? null : (int) round($cost * 100),
                'listing_reconstructed' => $reconstructionOk,
                'accepted_by_contract' => $accepted,
            ];
        }
        ksort($classes);
        ksort($platforms);

        $genuineCount = count(array_filter($rows, fn ($r) => $r['genuine']));
        $nMatched = count($matched);
        $directCount = $agreement['cost_and_url'] + $agreement['cost_only'] + $agreement['url_only'];

        $pageTotal = $context['commercial_total_cost_usd'] ?? null;
        $apiReportedUsd = $pageTotal ?? ($costReported > 0 ? $jobCostUsd : null);
        $tierObserved = $pageTotal !== null || $costReported > 0;

        $warnings = [];
        if (! $tierObserved) {
            $warnings[] = 'commercial_tier_not_observed: no cost field came back, so the key may not have taken effect (the API documents no error for a bad key); do not read this sample as a Commercial result';
        }
        if ($pageTotal !== null && $costReported > 0 && abs($pageTotal - $jobCostUsd) > 0.005) {
            $warnings[] = 'charge_mismatch: the page total and the sum of per-job charges differ';
        }
        if ($agreement['cost_only'] + $agreement['url_only'] > 0) {
            $warnings[] = 'direct_signals_disagree: some jobs show a charge without a different URL, or a different URL without a charge (a repeat retrieval is free, so url_only can be legitimate)';
        }

        return [
            'schema' => self::SCHEMA,
            'scope' => [
                'window' => 'one page of the free public feed (last 7 days) and the same page from the Commercial tier',
                'historical_inventory' => false,
                'note' => 'A sample of the recent public feed. It says nothing about Jobicy jobs older than seven days, and must not be extrapolated to them or to the stored Jobicy jobs.',
                'live_verified' => 'not by this evaluator: it only reads what it is given',
            ],
            'pages' => ['public' => $context['public_page_count'] ?? 1, 'commercial' => $context['commercial_page_count'] ?? 1],
            'totals' => [
                'public_returned' => count($publicJobs),
                'commercial_returned' => count($commercialJobs),
                'matched' => $nMatched,
                'unmatched_public_only' => count($publicOnly),
                'unmatched_commercial_only' => count($commercialOnly),
                'ambiguous' => count($ambiguous),
                'malformed_public' => count($pub['malformed']),
                'malformed_commercial' => count($com['malformed']),
            ],
            'unmatched' => ['public_only' => $publicOnly, 'commercial_only' => $commercialOnly],
            'ambiguous' => array_map(fn ($id, $why) => ['id' => (string) $id, 'reason' => $why], array_keys($ambiguous), array_values($ambiguous)),
            'malformed' => ['public' => $pub['malformed'], 'commercial' => $com['malformed']],
            'destinations' => [
                'direct_url_jobs' => $directCount,
                'direct_signals_agreement' => $agreement,
                'genuine' => $genuineCount,
                'genuine_share_of_matched' => $nMatched > 0 ? round($genuineCount / $nMatched, 4) : null,
                'genuine_share_interval_95' => Wilson::interval($genuineCount, $nMatched),
                'direct_share_of_matched' => $nMatched > 0 ? round($directCount / $nMatched, 4) : null,
                'direct_share_interval_95' => Wilson::interval($directCount, $nMatched),
                'by_class' => $classes,
                'still_listing_aggregator_or_tracker' => ($classes['jobicy_listing'] ?? 0) + ($classes['aggregator'] ?? 0) + ($classes['tracker_redirect'] ?? 0),
                'ats_platforms' => $platforms,
            ],
            'listing_reconstruction' => [
                'rule' => 'jobicy.com/jobs/{id}-{jobSlug} equals the free tier\'s url',
                'checked' => $reconstructable,
                'correct' => $reconstructed,
                'accuracy' => $reconstructable > 0 ? round($reconstructed / $reconstructable, 4) : null,
                'not_checkable_without_jobslug' => $nMatched - $reconstructable,
                'misses' => $reconstructionMisses,
            ],
            'charges' => [
                'commercial_tier_observed' => $tierObserved,
                'api_reported_total_usd' => $apiReportedUsd === null ? null : round($apiReportedUsd, 2),
                'sum_of_job_charges_usd' => round($jobCostUsd, 2),
                'jobs_with_a_reported_charge' => $costReported,
                'cost_per_genuine_destination_usd' => $apiReportedUsd !== null && $genuineCount > 0 ? round($apiReportedUsd / $genuineCount, 4) : null,
                'projected_first_retrieval_per_1000_jobs_usd' => $apiReportedUsd !== null && $nMatched > 0 ? round($apiReportedUsd / $nMatched * 1000, 2) : null,
                'note' => 'The API charges once per key per job, so a repeat retrieval costs nothing and this is a first-retrieval figure. The page total includes any job the two tiers did not match, so the per-1000 projection is slightly conservative.',
            ],
            'b1_contract' => [
                'candidates_accepted' => $acceptedByContract,
                'candidates_rejected' => count(array_filter($rows, fn ($r) => $r['accepted_by_contract'] === false)),
                'note' => 'Each accepted candidate is a destinations[] entry (source jobicy, tier commercial, verification unverified) beside attribution that keeps the Jobicy listing as canonical_url. Nothing is persisted.',
            ],
            'warnings' => $warnings,
            'jobs' => $rows,
            // Not part of the written report: the normalized B1 entries, for a later milestone and for the tests.
            '_candidates' => $candidates,
        ];
    }

    /**
     * @param  list<mixed>  $jobs
     * @return array{by_id: array<string, array<string, mixed>>, duplicates: array<string, true>, malformed: list<array{index: int, reason: string}>}
     */
    private function index(array $jobs): array
    {
        $byId = [];
        $duplicates = [];
        $malformed = [];
        foreach (array_values($jobs) as $i => $job) {
            if (! is_array($job)) {
                $malformed[] = ['index' => $i, 'reason' => 'not_an_object'];

                continue;
            }
            $id = $job['id'] ?? null;
            if ((! is_int($id) && ! is_string($id)) || trim((string) $id) === '') {
                $malformed[] = ['index' => $i, 'reason' => 'missing_id'];

                continue;
            }
            if (! is_string($job['url'] ?? null) || trim($job['url']) === '') {
                $malformed[] = ['index' => $i, 'reason' => 'missing_url'];

                continue;
            }
            $id = (string) $id;
            if (isset($byId[$id])) {
                $duplicates[$id] = true;

                continue;
            }
            $byId[$id] = $job;
        }

        return ['by_id' => $byId, 'duplicates' => $duplicates, 'malformed' => $malformed];
    }

    /** The same id must still be the same job: title and company agree wherever both tiers state them. */
    private function sameJob(array $public, array $commercial): bool
    {
        foreach (['jobTitle', 'companyName'] as $field) {
            if (is_string($public[$field] ?? null) && is_string($commercial[$field] ?? null)
                && mb_strtolower(trim($public[$field])) !== mb_strtolower(trim($commercial[$field]))) {
                return false;
            }
        }

        return true;
    }

    /**
     * The B1 destination contract applied to one job: the Jobicy listing stays the candidate's application URL (and the attribution's canonical URL), the
     * Commercial `url` becomes an unverified candidate destination, and DestinationMetadata::sanitize decides whether the contract accepts it.
     *
     * @return array{0: array<string, mixed>, 1: bool}
     */
    private function candidate(string $id, string $listing, string $destination): array
    {
        $candidate = new DiscoveredJobCandidate(
            source: JobDiscoverySource::Jobicy, sourceJobId: $id, company: '-', title: '-', description: null, descriptionCompleteness: DescriptionCompleteness::Unknown,
            location: null, remoteStatus: null, employmentType: null, compensationMin: null, compensationMax: null, compensationCurrency: null, compensationInterval: null,
            applicationUrl: $listing, postedAt: null, sourceUpdatedAt: null, discoveredAt: CarbonImmutable::now(),
            sourceMetadata: ['destinations' => [DestinationMetadata::destination(JobDiscoverySource::Jobicy, $destination, 'url', 'commercial', ['kind' => 'api_field'])]],
        );
        $stored = DestinationMetadata::sanitize($candidate);

        return [['listing_url' => $listing, 'destinations' => $stored['destinations'] ?? [], 'attribution' => $stored['attribution'] ?? null], isset($stored['destinations'][0])];
    }
}
