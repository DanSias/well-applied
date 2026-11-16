<?php

namespace App\Support\JobDiscovery;

use App\Enums\DiscoveryStatus;
use App\Enums\JobCanonicalSource;
use App\Enums\JobDiscoverySource;
use App\Models\CareerProfile;
use App\Models\DiscoveryProviderAttempt;
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
use App\Support\JobDiscovery\Providers\UsajobsDiscoveryProvider;
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

    private readonly ProviderExecutionLeases $leases;

    private readonly ExecutionHeartbeat $heartbeat;

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
        ?UsajobsDiscoveryProvider $usajobs = null,
        ?ProviderExecutionLeases $leases = null,
        ?ExecutionHeartbeat $heartbeat = null,
    ) {
        $this->leases = $leases ?? new ProviderExecutionLeases;
        $this->heartbeat = $heartbeat ?? app(ExecutionHeartbeat::class);
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

        // Registered only when both USAJOBS credentials are configured (an injected instance always registers), so an
        // install without them behaves exactly as before. Routine scheduling is a separate opt-in
        // (see DiscoveryScheduleIsDue); a manual `discovery:run` runs it once registered.
        $usajobs ??= UsajobsDiscoveryProvider::isActive() ? new UsajobsDiscoveryProvider : null;
        if ($usajobs !== null) {
            $providers[JobDiscoverySource::Usajobs->value] = $usajobs;
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
        return $this->execute($only, $context)->run;
    }

    /**
     * Like run(), and also reports the providers that were not executed because another execution held their lease.
     * Each provider is executed only while holding its own lease, taken before any attempt exists: a busy provider is
     * skipped (no attempt, so never a false success), and the others carry on.
     *
     * @param  JobDiscoverySource[]|null  $only  See run().
     */
    public function execute(?array $only = null, ?ProviderDiscoveryContext $context = null): DiscoveryExecution
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

        $skipped = [];
        $orchestrationFailed = false;
        try {
            foreach ($this->providers as $source => $provider) {
                if ($only !== null && ! in_array(JobDiscoverySource::from($source), $only, true)) {
                    continue;
                }
                $discoverySource = JobDiscoverySource::from($source);
                $lease = $this->leases->acquire($discoverySource);
                if ($lease === null) {
                    $skipped[] = $discoverySource;

                    continue;
                }
                try {
                    $this->runProviderAttempt($discoveryRun, $discoverySource, $provider, $criteria, $careerProfile, $boardCache, $context, $lease);
                } finally {
                    $this->heartbeat->stop();
                    $lease->release();
                }
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

        if ($skipped !== [] && ! $discoveryRun->providerAttempts()->exists()) {
            // Everything requested was busy: nothing ran, so there is no run to record.
            $discoveryRun->delete();

            return new DiscoveryExecution($discoveryRun, $skipped);
        }

        return new DiscoveryExecution($this->settle($discoveryRun, $orchestrationFailed), $skipped);
    }

    /**
     * Executes a Run Now request that was recorded as a queued attempt, with the same pipeline as any other run. It
     * takes the provider's lease first, then claims the attempt with one conditional UPDATE (queued -> running), so a
     * job delivered twice, or a request already recovered as never started, is a no-op rather than a second run.
     *
     * @return 'executed'|'busy'|'not_queued' `busy`: another execution holds the provider's lease, nothing was started.
     *                                        `not_queued`: the attempt was no longer queued (claimed by another delivery, or recovered).
     */
    public function executeQueued(DiscoveryProviderAttempt $queued): string
    {
        $source = $queued->provider;
        $lease = $this->leases->acquire($source, self::queuedOwner($queued));
        if ($lease === null) {
            return 'busy';
        }

        $discoveryRun = null;
        try {
            $claimed = DiscoveryProviderAttempt::query()->whereKey($queued->id)->where('status', DiscoveryStatus::Queued)
                ->update(['status' => DiscoveryStatus::Running, 'started_at' => now(), 'updated_at' => now()]);
            if ($claimed === 0) {
                return 'not_queued';
            }

            $discoveryRun = DiscoveryRun::query()->findOrFail($queued->discovery_run_id);
            $discoveryRun->update(['status' => DiscoveryStatus::Running, 'started_at' => now()]);
            $attempt = $queued->fresh() ?? $queued;
            $provider = $this->providers[$source->value] ?? null;
            $orchestrationFailed = false;

            try {
                if ($provider === null) {
                    // Configured when requested, gone by the time a worker got to it.
                    $attempt->update([
                        'status' => DiscoveryStatus::Failed,
                        'finished_at' => now(),
                        'failure_category' => 'provider_unavailable',
                        'failure_message' => 'The provider is no longer registered, so the queued run did not start.',
                    ]);
                } else {
                    $boardCache = [];
                    $this->runProviderAttempt(
                        $discoveryRun, $source, $provider, DiscoverySearchCriteria::fromConfig(), CurrentCareerProfile::resolve(),
                        $boardCache, null, $lease, $attempt,
                    );
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

            $this->settle($discoveryRun, $orchestrationFailed);

            return 'executed';
        } finally {
            $this->heartbeat->stop();
            $lease->release();
        }
    }

    /** The owner token of the lease a queued request holds, so its job's failure handler can free exactly that lease. */
    public static function queuedOwner(DiscoveryProviderAttempt $attempt): string
    {
        return 'queued-attempt-'.$attempt->id;
    }

    private function settle(DiscoveryRun $discoveryRun, bool $orchestrationFailed): DiscoveryRun
    {
        return SettleDiscoveryRun::settle($discoveryRun, $orchestrationFailed);
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
        ?ExecutionLease $lease = null,
        ?DiscoveryProviderAttempt $claimed = null,
    ): void {
        // A queued request is already an attempt (claimed by executeQueued); anything else creates it here, after the
        // lease is held.
        $attempt = $claimed ?? $discoveryRun->providerAttempts()->create([
            'provider' => $source,
            'status' => DiscoveryStatus::Running,
            'started_at' => now(),
        ]);
        if ($lease !== null) {
            $this->heartbeat->start($lease, $attempt);
        }

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
                $this->heartbeat->beat();
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
