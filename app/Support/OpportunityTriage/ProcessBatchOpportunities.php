<?php

namespace App\Support\OpportunityTriage;

use App\Enums\DescriptionCompleteness;
use App\Enums\GenerationType;
use App\Models\JobAnalysis;
use App\Models\JobPosting;
use App\Support\CurrentCareerProfile;
use App\Support\GenerationAttempt\QueueGenerationAttempt;
use LogicException;
use Throwable;

/**
 * Runs a human-initiated batch of analyze or match operations over an
 * explicitly selected set of job postings — the Phase E3 backend for the
 * Opportunity Triage surface.
 *
 * This class is the single, shared orchestrator behind both
 * `jobs.batch.analyze` and `jobs.batch.match`, so the two stay in lockstep
 * on the invariants they must share:
 *
 * - Profile isolation: every requested id is resolved against the current
 *   career profile (one query, `whereIn`-keyed by integer primary key), and
 *   a nonexistent id and a foreign-profile id are deliberately
 *   indistinguishable — both surface as `not_found`.
 * - Single triage call: `SummarizeTriageStates::forJobPostings()` is invoked
 *   exactly once across the whole found set. Its `stage` drives every
 *   eligibility decision and its selected `analysis_id` is the authoritative
 *   match subject; no separate latest-analysis query is ever issued.
 * - No N+1: the set of match-eligible analyses is pre-fetched in one
 *   `whereIn`, so a per-id decision never performs a per-posting lookup.
 * - Atomic, isolated claims: each eligible item claims through the same
 *   `QueueGenerationAttempt::claim()` the single-shot endpoints use. A null
 *   return (in flight) is a `skipped`/`in_flight`; a throw is a
 *   `failed`/`claim_failed`. One item failing — however it fails — never
 *   aborts the rest of the batch, and a single batch has no database
 *   transaction of its own.
 * - Stable, safe output: the `failed` reasons are a fixed, small set of
 *   literals (`claim_failed`, `analysis_unavailable`) and are never leaked
 *   with raw exception text or provider output. Items are returned in the
 *   requested order, every requested id is classified exactly once, and the
 *   four counts always sum to `requested`.
 *
 * It performs no generation work, persists no history, and introduces no
 * batch model — it only decides, claims, and reports.
 */
final class ProcessBatchOpportunities
{
    private const OPERATION_ANALYZE = 'analyze';

    private const OPERATION_MATCH = 'match';

    public function __construct(
        private readonly SummarizeTriageStates $triage,
        private readonly QueueGenerationAttempt $claims,
    ) {}

    /**
     * @param  array<int>  $requestedIds  job posting ids, in request order
     * @return array{
     *     operation: string,
     *     requested: int,
     *     started: int,
     *     skipped: int,
     *     failed: int,
     *     not_found: int,
     *     items: list<array{job_id: int, outcome: string, reason: string|null}>
     * }
     */
    public function run(string $operation, array $requestedIds): array
    {
        $isMatch = $operation === self::OPERATION_MATCH;
        $generationType = $isMatch ? GenerationType::JobMatch : GenerationType::JobAnalysis;

        $profile = CurrentCareerProfile::resolve();
        $postingsById = $this->resolvePostings($profile->id, $requestedIds);
        $summaries = $this->summariesFor($postingsById);

        // Pre-fetch every match-eligible analysis in one query so the per-id
        // decisions below never issue a per-posting lookup.
        $analysesById = $isMatch ? $this->loadSelectedAnalyses($summaries) : [];

        $started = 0;
        $skipped = 0;
        $failed = 0;
        $notFound = 0;
        $items = [];

        foreach ($requestedIds as $requestedId) {
            $jobId = (int) $requestedId;
            $posting = $postingsById[$jobId] ?? null;

            if ($posting === null) {
                // A missing id and a foreign-profile id are indistinguishable
                // on purpose — both are `not_found`, never `403`/`404`-ish.
                $outcome = 'not_found';
                $reason = null;
            } else {
                $summary = $summaries[$jobId] ?? null;
                $stage = $summary === null ? 'raw' : (string) ($summary['stage'] ?? 'raw');
                $analysis = $this->selectedAnalysis($isMatch, $summaries, $summaries[$jobId] ?? null, $analysesById);

                $decision = $this->decide($operation, $stage, $posting, $analysis);

                if ($decision['outcome'] === 'started') {
                    $subject = $decision['subject'];
                    if ($subject instanceof JobPosting || $subject instanceof JobAnalysis) {
                        $decision = $this->executeClaim($generationType, $subject);
                    }
                }

                $outcome = $decision['outcome'];
                $reason = $decision['reason'];
            }

            $items[] = ['job_id' => $jobId, 'outcome' => $outcome, 'reason' => $reason];

            match ($outcome) {
                'started' => $started++,
                'skipped' => $skipped++,
                'failed' => $failed++,
                'not_found' => $notFound++,
                default => throw new LogicException("Unreachable batch outcome: {$outcome}"),
            };
        }

        return [
            'operation' => $operation,
            'requested' => count($requestedIds),
            'started' => $started,
            'skipped' => $skipped,
            'failed' => $failed,
            'not_found' => $notFound,
            'items' => $items,
        ];
    }

    /**
     * Resolve the requested ids against the given profile with a single
     * query (`whereIn` keyed by the integer primary key — no per-id round trip
     * and no cross-profile leak).
     *
     * @param  array<int>  $requestedIds
     * @return array<int, JobPosting>
     */
    private function resolvePostings(int $profileId, array $requestedIds): array
    {
        $postingsById = [];

        if ($requestedIds === []) {
            return $postingsById;
        }

        // Only the three columns that are actually read downstream
        // (ownership + `description_completeness`, everything else the
        // summarizer itself re-fetches) are pulled, so the row payload
        // stays minimal — this is a purely column-level restriction and
        // not a per-row N+1.
        $found = JobPosting::query()
            ->where('career_profile_id', $profileId)
            ->whereIn('id', $requestedIds)
            ->select(['id', 'career_profile_id', 'description_completeness'])
            ->get();

        foreach ($found as $posting) {
            $postingsById[(int) $posting->id] = $posting;
        }

        return $postingsById;
    }

    /**
     * @param  array<int, JobPosting>  $postingsById
     * @return array<int, array<string, mixed>>
     */
    private function summariesFor(array $postingsById): array
    {
        if ($postingsById === []) {
            return [];
        }

        return $this->triage->forJobPostings(collect(array_keys($postingsById))->values());
    }

    /**
     * One query over the analyses every match-started decision needs — the
     * selected (authoritative) analysis for postings in the match-eligible
     * stages `analyzed` and `match-failed`.
     *
     * @param  array<int, array<string, mixed>>  $summaries
     * @return array<int, JobAnalysis>
     */
    private function loadSelectedAnalyses(array $summaries): array
    {
        $ids = [];

        foreach ($summaries as $summary) {
            $stage = $summary['stage'] ?? null;

            if (! in_array($stage, ['analyzed', 'match-failed'], true)) {
                continue;
            }

            $analysisId = $summary['analysis_id'] ?? null;

            if ($analysisId !== null) {
                $ids[(int) $analysisId] = true;
            }
        }

        if ($ids === []) {
            return [];
        }

        $analysesById = [];

        // Only `id` (the morph key used when a claim is taken) and
        // `job_posting_id` (ownership / matching back to the posting) are
        // ever consumed by this path — keep the row payload minimal by
        // selecting just those two columns.
        $analyses = JobAnalysis::query()
            ->whereIn('id', array_keys($ids))
            ->select(['id', 'job_posting_id'])
            ->get();

        foreach ($analyses as $analysis) {
            $analysesById[(int) $analysis->id] = $analysis;
        }

        return $analysesById;
    }

    /**
     * @param  array<int, array<string, mixed>>  $summaries
     * @param  array<string, mixed>|null  $summary
     * @param  array<int, JobAnalysis>  $analysesById
     */
    private function selectedAnalysis(bool $isMatch, array $summaries, ?array $summary, array $analysesById): ?JobAnalysis
    {
        if (! $isMatch || $summary === null) {
            return null;
        }

        $analysisId = $summary['analysis_id'] ?? null;

        if ($analysisId === null) {
            return null;
        }

        return $analysesById[(int) $analysisId] ?? null;
    }

    /**
     * Decide an item's outcome and (for a `started` decision) the subject the
     * claim runs against — purely from the triage stage, the posting's
     * description completeness, and the selected analysis. No claim is made
     * here; that happens in executeClaim(), so a null/throw is reclassified
     * uniformly.
     *
     * @return array{outcome: string, reason: string|null, subject: JobPosting|JobAnalysis|null}
     */
    private function decide(string $operation, string $stage, JobPosting $posting, ?JobAnalysis $analysis): array
    {
        if ($operation === self::OPERATION_ANALYZE) {
            return match ($stage) {
                'raw', 'analysis-failed' => $this->decideAnalyze($posting),
                'analyzing' => $this->skipped('in_flight'),
                'analyzed', 'matching', 'match-failed', 'matched' => $this->skipped('already_analyzed'),
                default => $this->skipped('already_analyzed'),
            };
        }

        return match ($stage) {
            'raw', 'analysis-failed' => $this->skipped('no_analysis'),
            'analyzing', 'matching' => $this->skipped('in_flight'),
            'matched' => $this->skipped('already_matched'),
            'analyzed', 'match-failed' => $this->decideMatch($posting, $analysis),
            default => $this->skipped('no_analysis'),
        };
    }

    /**
     * @return array{outcome: string, reason: string|null, subject: JobPosting|JobAnalysis|null}
     */
    private function skipped(string $reason): array
    {
        return ['outcome' => 'skipped', 'reason' => $reason, 'subject' => null];
    }

    /**
     * @return array{outcome: string, reason: string|null, subject: JobPosting|JobAnalysis|null}
     */
    private function decideAnalyze(JobPosting $posting): array
    {
        if ($posting->description_completeness !== DescriptionCompleteness::Complete) {
            return $this->skipped('incomplete_description');
        }

        return ['outcome' => 'started', 'reason' => null, 'subject' => $posting];
    }

    /**
     * @return array{outcome: string, reason: string|null, subject: JobPosting|JobAnalysis|null}
     */
    private function decideMatch(JobPosting $posting, ?JobAnalysis $analysis): array
    {
        // Match always runs against the triage-selected analysis, and only
        // after confirming it truly belongs to this posting — never a stale
        // or foreign snapshot.
        if ($analysis === null || $analysis->job_posting_id !== $posting->id) {
            return ['outcome' => 'failed', 'reason' => 'analysis_unavailable', 'subject' => null];
        }

        return ['outcome' => 'started', 'reason' => null, 'subject' => $analysis];
    }

    /**
     * Execute a claim for a `started` decision. claim() is never wrapped in a
     * batch transaction: it is already atomic under its own per-subject lock,
     * and if it throws the exception is caught and reclassified as a stable,
     * safe `claim_failed` so it can never surface raw.
     *
     * @return array{outcome: string, reason: string|null, subject: JobPosting|JobAnalysis|null}
     */
    private function executeClaim(GenerationType $type, JobPosting|JobAnalysis $subject): array
    {
        try {
            $attempt = $this->claims->claim($type, $subject);
        } catch (Throwable) {
            return ['outcome' => 'failed', 'reason' => 'claim_failed', 'subject' => null];
        }

        if ($attempt === null) {
            return $this->skipped('in_flight');
        }

        return ['outcome' => 'started', 'reason' => null, 'subject' => $subject];
    }
}
