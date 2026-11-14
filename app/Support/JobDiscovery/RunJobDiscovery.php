<?php

namespace App\Support\JobDiscovery;

use App\Enums\DiscoveryStatus;
use App\Enums\JobCanonicalSource;
use App\Enums\JobDiscoverySource;
use App\Models\CareerProfile;
use App\Models\DiscoveryRun;
use App\Support\CurrentCareerProfile;
use App\Support\JobDiscovery\Canonical\AshbyCanonicalAdapter;
use App\Support\JobDiscovery\Canonical\CanonicalJobPosting;
use App\Support\JobDiscovery\Canonical\CanonicalRetrievalContract;
use App\Support\JobDiscovery\Canonical\GreenhouseCanonicalAdapter;
use App\Support\JobDiscovery\Canonical\LeverCanonicalAdapter;
use App\Support\JobDiscovery\Canonical\MatchCanonicalPosting;
use App\Support\JobDiscovery\Canonical\ResolveAtsBoard;
use App\Support\JobDiscovery\Providers\AdzunaDiscoveryProvider;
use App\Support\JobDiscovery\Providers\DiscoveryProviderContract;
use App\Support\JobDiscovery\Providers\DiscoveryProviderException;
use App\Support\JobDiscovery\Providers\EightfoldDiscoveryProvider;
use App\Support\JobDiscovery\Providers\HimalayasDiscoveryProvider;
use App\Support\JobDiscovery\Providers\JobicyDiscoveryProvider;
use App\Support\JobDiscovery\Providers\ProviderDiscoveryContext;
use App\Support\JobDiscovery\Providers\ReportsPartialFailure;
use App\Support\JobDiscovery\Providers\ReportsRetrievalDiagnostics;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The single discovery pipeline both `php artisan discovery:run` and
 * (a later commit's) scheduled execution call — never two separate
 * implementations. See docs/job-discovery.md "Scheduling /
 * orchestration".
 *
 * Provider attempts settle on expected and unexpected execution failures.
 * Earlier candidate transactions remain committed; independent providers
 * continue. Identity conflicts are reported without merging existing rows.
 *
 * Never wrapped in one all-or-nothing transaction — each candidate's
 * ingest() call is its own small transaction (see
 * IngestDiscoveredCandidate), not the whole run.
 */
final class RunJobDiscovery
{
    /**
     * @var array<string, DiscoveryProviderContract>
     */
    private readonly array $providers;

    /**
     * @var array<string, CanonicalRetrievalContract>
     */
    private readonly array $canonicalAdapters;

    public function __construct(
        ?HimalayasDiscoveryProvider $himalayas = null,
        ?AdzunaDiscoveryProvider $adzuna = null,
        private readonly FilterDiscoveredCandidates $filter = new FilterDiscoveredCandidates,
        private readonly ResolveAtsBoard $resolveBoard = new ResolveAtsBoard,
        private readonly MatchCanonicalPosting $matchCanonical = new MatchCanonicalPosting,
        private readonly IngestDiscoveredCandidate $ingestCandidate = new IngestDiscoveredCandidate,
        private readonly DiscoveryRejectionLog $rejections = new DiscoveryRejectionLog,
        ?GreenhouseCanonicalAdapter $greenhouse = null,
        ?LeverCanonicalAdapter $lever = null,
        ?AshbyCanonicalAdapter $ashby = null,
        ?JobicyDiscoveryProvider $jobicy = null,
        ?EightfoldDiscoveryProvider $eightfold = null,
    ) {
        $providers = [
            JobDiscoverySource::Himalayas->value => $himalayas ?? new HimalayasDiscoveryProvider,
            JobDiscoverySource::Adzuna->value => $adzuna ?? new AdzunaDiscoveryProvider,
            JobDiscoverySource::Jobicy->value => $jobicy ?? new JobicyDiscoveryProvider,
        ];

        // Registered only when configured and at least one tenant is enabled
        // (see config/job_discovery.php `eightfold`); an injected instance
        // always registers. Absent, discovery behaves exactly as before.
        $eightfold ??= EightfoldDiscoveryProvider::isActive() ? new EightfoldDiscoveryProvider : null;
        if ($eightfold !== null) {
            $providers[JobDiscoverySource::Eightfold->value] = $eightfold;
        }
        $this->providers = $providers;

        $this->canonicalAdapters = [
            JobCanonicalSource::Greenhouse->value => $greenhouse ?? new GreenhouseCanonicalAdapter,
            JobCanonicalSource::Lever->value => $lever ?? new LeverCanonicalAdapter,
            JobCanonicalSource::Ashby->value => $ashby ?? new AshbyCanonicalAdapter,
        ];
    }

    /**
     * @param  JobDiscoverySource[]|null  $only  Restrict this run to these
     *                                           providers (the scheduler passes only those currently due);
     *                                           null runs every registered provider, as manual execution does.
     * @param  ProviderDiscoveryContext|null  $context  Optional discovery context
     *                                                  forwarded verbatim to each provider's retrieve();
     *                                                  V1 providers ignore it (their requests are
     *                                                  unchanged). null preserves the original
     *                                                  zero-context behavior exactly.
     */
    public function run(?array $only = null, ?ProviderDiscoveryContext $context = null): DiscoveryRun
    {
        $careerProfile = CurrentCareerProfile::resolve();
        $criteria = DiscoverySearchCriteria::fromConfig();

        $discoveryRun = DiscoveryRun::create([
            'status' => DiscoveryStatus::Running,
            'started_at' => now(),
        ]);

        // Resolved boards are cached per run — several candidates from
        // different providers may resolve to the same company, and
        // there is no reason to re-fetch that board's full listing
        // more than once per run.
        $boardCache = [];

        $orchestrationFailed = false;
        try {
            foreach ($this->providers as $source => $provider) {
                if ($only !== null && ! in_array(JobDiscoverySource::from($source), $only, true)) {
                    continue;
                }
                $this->runProviderAttempt($discoveryRun, JobDiscoverySource::from($source), $provider, $criteria, $careerProfile, $boardCache, $context);
            }
        } catch (Throwable) {
            $orchestrationFailed = true;
            $discoveryRun->providerAttempts()->where('status', DiscoveryStatus::Running)->update([
                'status' => DiscoveryStatus::Failed,
                'finished_at' => now(),
                'failure_category' => 'unexpected_error',
                'failure_message' => 'Unexpected discovery orchestration failure.',
            ]);
        }

        $attempts = $discoveryRun->providerAttempts()->get();
        $failures = $attempts->where('status', DiscoveryStatus::Failed)->count();
        // A provider that only partly succeeded (some Eightfold tenants failed)
        // is not a clean success either.
        $degraded = $failures + $attempts->where('status', DiscoveryStatus::Partial)->count();
        $status = $degraded === 0 && ! $orchestrationFailed ? DiscoveryStatus::Succeeded
            : ($failures < $attempts->count() ? DiscoveryStatus::Partial : DiscoveryStatus::Failed);
        $discoveryRun->update(['status' => $status, 'finished_at' => now()]);

        return $discoveryRun->fresh('providerAttempts');
    }

    /**
     * @param  array<string, CanonicalJobPosting[]>  $boardCache
     */
    private function runProviderAttempt(
        DiscoveryRun $discoveryRun,
        JobDiscoverySource $source,
        DiscoveryProviderContract $provider,
        DiscoverySearchCriteria $criteria,
        CareerProfile $careerProfile,
        array &$boardCache,
        ?ProviderDiscoveryContext $context = null,
    ): void {
        $attempt = $discoveryRun->providerAttempts()->create([
            'provider' => $source,
            'status' => DiscoveryStatus::Running,
            'started_at' => now(),
        ]);

        $retrieved = $accepted = $created = $updated = $canonicalized = 0;
        /** @var array<string, int> $rejectionCounts */
        $rejectionCounts = [];
        $diagnostics = null;
        $retrievalCompleted = false;
        $failureCategory = $failureMessage = $failureExceptionClass = null;
        $partialSummary = null;
        $candidate = null;

        try {
            $candidates = $provider->retrieve($context);
            $retrieved = count($candidates);
            $retrievalCompleted = true;
            if ($provider instanceof ReportsRetrievalDiagnostics) {
                $diagnostics = $provider->retrievalDiagnostics();
            }
            if ($provider instanceof ReportsPartialFailure) {
                $partialSummary = $provider->partialFailureSummary();
            }
            foreach ($candidates as $candidate) {
                $decision = $this->filter->evaluate($candidate, $criteria);
                if (! $decision->accepted) {
                    $reasonCode = $decision->reason->value ?? 'unknown';
                    $rejectionCounts[$reasonCode] = ($rejectionCounts[$reasonCode] ?? 0) + 1;
                    $this->recordRejection($candidate, $decision, $discoveryRun->id);

                    continue;
                }
                $accepted++;
                $this->resolveRejection($candidate);
                $canonicalMatch = $this->resolveCanonicalMatch($candidate, $boardCache);
                try {
                    $result = $this->ingestCandidate->ingest($candidate, $canonicalMatch, $careerProfile);
                } catch (DiscoveryIngestionException $e) {
                    // Preserve both existing identities and continue independent
                    // candidates. This attempt is terminal failed, with partial counts.
                    $failureCategory = 'identity_conflict';
                    $failureMessage = $e->getMessage();

                    continue;
                }
                $canonicalized += $canonicalMatch !== null ? 1 : 0;
                if ($result->wasCreated) {
                    $created++;
                } else {
                    $updated++;
                }
            }
        } catch (DiscoveryProviderException $e) {
            $failureCategory = 'provider_error';
            $failureExceptionClass = $e::class;
            $failureMessage = $e->getMessage();
        } catch (Throwable $e) {
            // Arbitrary messages can contain credentials, SQL or full payloads.
            // Keep structural diagnostics only; no exception object/trace arguments.
            $failureCategory = 'unexpected_error';
            $failureExceptionClass = $e::class;
            $failureMessage = 'Unexpected discovery execution failure; completed candidate writes were retained.';

            Log::error('Discovery provider attempt failed unexpectedly.', [
                'discovery_run_id' => $discoveryRun->id,
                'provider' => $source->value,
                'exception' => $e::class,
                'exception_file' => $e->getFile(),
                'exception_line' => $e->getLine(),
                'phase' => $candidate === null ? 'retrieval' : 'candidate_processing',
                'candidate_identity_hash' => $candidate === null ? null : hash('sha256', $candidate->sourceJobId),
                'candidates_retrieved' => $retrieved,
                'candidates_accepted' => $accepted,
            ]);
        }

        // Failed beats Partial; a partial provider failure keeps its counts and
        // is recorded in the same failure columns rather than a new structure.
        $status = $failureCategory !== null ? DiscoveryStatus::Failed
            : ($partialSummary !== null ? DiscoveryStatus::Partial : DiscoveryStatus::Succeeded);
        if ($failureCategory === null && $partialSummary !== null) {
            $failureCategory = 'partial_provider_failure';
            $failureMessage = $partialSummary;
        } elseif ($partialSummary !== null) {
            $failureMessage = trim("{$failureMessage} {$partialSummary}");
        }

        $attempt->update([
            'status' => $status,
            'finished_at' => now(),
            'candidates_retrieved' => $retrieved,
            'candidates_accepted' => $accepted,
            'jobs_created' => $created,
            'jobs_updated' => $updated,
            'jobs_canonicalized' => $canonicalized,
            // null = retrieval never completed, so the filter stage was never observed; [] = observed, nothing rejected.
            'rejection_counts' => $retrievalCompleted ? $rejectionCounts : null,
            'retrieval_diagnostics' => $diagnostics,
            'failure_category' => $failureCategory,
            'failure_message' => $failureMessage,
            'failure_exception_class' => $failureExceptionClass,
        ]);
    }

    /**
     * The rejection log is a diagnostic: failing to write it must never fail the provider attempt or lose the candidates
     * after it, so a write error is logged (structurally, no payload) and discovery carries on.
     */
    private function recordRejection(DiscoveredJobCandidate $candidate, FilterDecision $decision, int $discoveryRunId): void
    {
        try {
            $this->rejections->record($candidate, $decision, $discoveryRunId);
        } catch (Throwable $e) {
            Log::warning('Discovery rejection could not be recorded.', ['provider' => $candidate->source->value, 'exception' => $e::class]);
        }
    }

    private function resolveRejection(DiscoveredJobCandidate $candidate): void
    {
        try {
            $this->rejections->resolve($candidate);
        } catch (Throwable $e) {
            Log::warning('Discovery rejection could not be resolved.', ['provider' => $candidate->source->value, 'exception' => $e::class]);
        }
    }

    /**
     * @param  array<string, CanonicalJobPosting[]>  $boardCache
     */
    private function resolveCanonicalMatch(DiscoveredJobCandidate $candidate, array &$boardCache): ?CanonicalJobPosting
    {
        $board = $this->resolveBoard->resolve($candidate->company);

        if ($board === null) {
            return null;
        }

        $cacheKey = "{$board->ats_type->value}:{$board->board_identifier}";

        if (! array_key_exists($cacheKey, $boardCache)) {
            // Every JobCanonicalSource case has an adapter registered
            // in the constructor — the lookup below always succeeds.
            $adapter = $this->canonicalAdapters[$board->ats_type->value];

            try {
                $boardCache[$cacheKey] = $adapter->retrieveBoard($board->board_identifier);
            } catch (DiscoveryProviderException) {
                // Canonical retrieval failing is an enhancement
                // failing, not an ingestion blocker — the candidate
                // still proceeds, aggregator-only. See
                // docs/job-discovery.md "Canonical resolution must not
                // block ingestion".
                $boardCache[$cacheKey] = [];
            }
        }

        return $this->matchCanonical->match($candidate, $boardCache[$cacheKey]);
    }
}
