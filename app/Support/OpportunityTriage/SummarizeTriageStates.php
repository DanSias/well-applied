<?php

namespace App\Support\OpportunityTriage;

use App\Enums\GenerationStatus;
use App\Enums\GenerationType;
use App\Models\GenerationAttempt;
use App\Models\JobAnalysis;
use App\Models\JobMatch;
use App\Models\JobMatchFinding;
use App\Models\JobPosting;
use App\Support\CurrentCareerProfile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Bulk-derives a compact triage-stage summary per JobPosting for the
 * Opportunity Triage surface. Never a persisted status column on
 * JobPosting — always computed live from the same durable history
 * (JobAnalysis snapshots, JobMatch snapshots, GenerationAttempts) that
 * JobAnalysisController and JobMatchController already read for the
 * detail pages, so the list view cannot drift from them.
 *
 * Stage precedence, highest first:
 *
 *  1. an active (queued/running) job_analysis attempt on the JobPosting
 *     → `analyzing` — a re-analysis is in flight, so nothing about a
 *     prior analysis is presented as current;
 *  2. else, when a JobAnalysis snapshot exists for the posting (the
 *     latest one: `generated_at DESC, id DESC`, the same selection the
 *     detail pages present):
 *      - an active job_match attempt on it → `matching` — likewise a
 *        re-match in flight beats the prior successful match;
 *      - a completed JobMatch for it, scoped to the current
 *        CareerProfile → `matched`;
 *      - a failed job_match attempt, and no completed match →
 *        `match-failed`;
 *      - no match activity at all → `analyzed`;
 *  3. else, a failed job_analysis attempt with no snapshot →
 *     `analysis-failed`;
 *  4. else (no snapshot, no attempts) → `raw`.
 *
 * Snapshots are immutable and only ever exist after a succeeded run,
 * so "a snapshot exists" is exactly "a completed generation exists",
 * and a Failed GenerationAttempt only demotes the stage when no
 * snapshot has ever been produced.
 *
 * An active (queued/running) attempt beats its subject's selected
 * snapshot only when it is chronologically strictly newer than it
 * (attempt created_at > snapshot generated_at). A queued/running row
 * older than a succeeded snapshot is a crash leftover — a run that
 * committed its result and then died before marking its attempt row,
 * freezing the lifecycle in flight — and must not demote the stage.
 * An equal timestamp does not establish that the attempt is newer,
 * so it does not override either.
 *
 * `coverage` is a count of the selected match's JobMatchFinding rows
 * by their holistic classification — shown only in `matched`. It is
 * deliberately null while a re-analysis or re-match is in flight (a
 * stale count would describe a snapshot that is being superseded) and
 * below that stage there is nothing to count. Zero is meaningful
 * ("every finding is in this bucket", or "no findings at all"), never
 * absence. This summarizer never scores, ranks, or classifies — it
 * only reports what the recorded generation history already says.
 *
 * Bulk — a fixed number of small queries total, never a query per
 * posting, never N+1 — because this runs once per Triage render
 * across every listed JobPosting.
 */
final class SummarizeTriageStates
{
    /**
     * @param  Collection<int, int>  $jobPostingIds
     * @return array<int, array<string, mixed>> keyed by job_posting_id
     */
    public function forJobPostings(Collection $jobPostingIds): array
    {
        $jobPostingIds = $jobPostingIds->values();
        $profileId = CurrentCareerProfile::resolve()->id;

        $summaries = [];
        foreach ($jobPostingIds as $jobPostingId) {
            $summaries[(int) $jobPostingId] = $this->raw();
        }

        if ($jobPostingIds->isEmpty()) {
            return $summaries;
        }

        // The latest JobAnalysis snapshot per posting — the one the
        // detail pages would present — selected exactly like they do:
        // generated_at DESC, then id DESC as the deterministic tiebreak.
        $selectedAnalysisByPosting = $this->selectedAnalysisByPosting($jobPostingIds);

        $analysisIds = $selectedAnalysisByPosting->pluck('id');

        // The completed JobMatch the detail page would show for that
        // analysis: current-profile-scoped, same generated_at/id order.
        // Read here — before the flag queries — because its generated_at
        // is the chronology cutoff that gates stale match attempts.
        // Resume_variant attempts hang off a JobMatch, not a JobAnalysis,
        // so they cannot reach the flag queries below and never influence
        // triage stage — resume generation is not a triage stage.
        $selectedMatchByAnalysis = $this->selectedMatchByAnalysis($analysisIds, $profileId);

        // Chronology cutoffs: an active attempt counts as in flight only
        // when it is strictly newer than the selected snapshot it would
        // supersede. A subject without a selected snapshot has no cutoff,
        // so its active attempts fall through exactly as before.
        $analysisCutoffs = $selectedAnalysisByPosting->mapWithKeys(
            fn (JobAnalysis $analysis, $jobPostingId) => [(int) $jobPostingId => $analysis->generated_at],
        )->all();
        $matchCutoffs = $selectedMatchByAnalysis->mapWithKeys(
            fn (JobMatch $match, $analysisId) => [(int) $analysisId => $match->generated_at],
        )->all();

        $analysisAttemptsByPosting = $this->attemptFlagsBySubject(
            $jobPostingIds,
            JobPosting::class,
            GenerationType::JobAnalysis,
            $analysisCutoffs,
        );

        // Match-stage facts are only ever read for the posting's selected
        // analysis — attempts and results attached to an older,
        // superseded analysis describe a snapshot that is no longer
        // current and must not influence its stage.
        $matchAttemptsByAnalysis = $analysisIds->isEmpty()
            ? []
            : $this->attemptFlagsBySubject(
                $analysisIds,
                JobAnalysis::class,
                GenerationType::JobMatch,
                $matchCutoffs,
            );

        $coverageByMatch = $selectedMatchByAnalysis->isEmpty()
            ? []
            : $this->coverageCountsByMatch($selectedMatchByAnalysis->pluck('id'));

        foreach ($jobPostingIds as $jobPostingId) {
            $jobPostingId = (int) $jobPostingId;
            $analysis = $selectedAnalysisByPosting->get($jobPostingId);
            $analysisAttempts = $analysisAttemptsByPosting[$jobPostingId] ?? ['active' => false, 'failed' => false];

            if ($analysis === null) {
                $stage = match (true) {
                    $analysisAttempts['active'] => 'analyzing',
                    $analysisAttempts['failed'] => 'analysis-failed',
                    default => 'raw',
                };

                $summaries[$jobPostingId] = $this->summary($stage, null, null, null);

                continue;
            }

            $match = $selectedMatchByAnalysis->get($analysis->id);
            $matchAttempts = $matchAttemptsByAnalysis[$analysis->id] ?? ['active' => false, 'failed' => false];

            $stage = match (true) {
                $analysisAttempts['active'] => 'analyzing',
                $matchAttempts['active'] => 'matching',
                $match !== null => 'matched',
                $matchAttempts['failed'] => 'match-failed',
                default => 'analyzed',
            };

            // Coverage is the only field gated to `matched` — never
            // presented while a re-generation is superseding the
            // snapshot it counts (see the class docblock). Reaching the
            // `matched` arm of the stage `match` above only happens when
            // a match is selected, so `$match` is provably non-null here
            // and no separate null test is needed.
            $coverage = $stage === 'matched'
                ? ($coverageByMatch[$match->id] ?? $this->zeroCounts())
                : null;

            $summaries[$jobPostingId] = $this->summary($stage, $analysis, $match, $coverage);
        }

        return $summaries;
    }

    /**
     * The JobAnalysis snapshot the detail page would present for each
     * posting — the latest one per posting, selected in exactly the
     * order the detail pages do it: generated_at DESC, then id DESC
     * as the deterministic tiebreak.
     *
     * @param  Collection<int, int>  $jobPostingIds
     * @return Collection<int, JobAnalysis> keyed by job_posting_id
     */
    private function selectedAnalysisByPosting(Collection $jobPostingIds): Collection
    {
        return JobAnalysis::query()
            ->whereIn('job_posting_id', $jobPostingIds)
            ->orderByDesc('generated_at')
            ->orderByDesc('id')
            ->get(['id', 'job_posting_id', 'generated_at'])
            ->groupBy('job_posting_id')
            ->map(fn (Collection $rows): JobAnalysis => $rows->first());
    }

    /**
     * The completed JobMatch the detail page would present for each
     * of the given analyses — scoped to one CareerProfile (only that
     * profile's match is ever "the" match for triage purposes) and
     * selected in the same generated_at DESC, id DESC order.
     *
     * @param  Collection<int, int>  $analysisIds
     * @return Collection<int, JobMatch> keyed by job_analysis_id
     */
    private function selectedMatchByAnalysis(Collection $analysisIds, int $profileId): Collection
    {
        if ($analysisIds->isEmpty()) {
            return collect();
        }

        return JobMatch::query()
            ->whereIn('job_analysis_id', $analysisIds)
            ->where('career_profile_id', $profileId)
            ->orderByDesc('generated_at')
            ->orderByDesc('id')
            ->get(['id', 'job_analysis_id', 'generated_at'])
            ->groupBy('job_analysis_id')
            ->map(fn (Collection $rows): JobMatch => $rows->first());
    }

    /**
     * @param  array{supported: int, partial: int, no_evidence: int, not_assessable: int}|null  $coverage
     * @return array<string, mixed>
     */
    private function summary(string $stage, ?JobAnalysis $analysis, ?JobMatch $match, ?array $coverage): array
    {
        // analysis_at / match_at are always the selected snapshot's
        // own generated_at (null when that stage's snapshot does not
        // exist) — never a GenerationAttempt lifecycle timestamp, even
        // while a re-generation of the same snapshot is in flight.
        return [
            'stage' => $stage,
            'coverage' => $coverage,
            'analysis_at' => $analysis?->generated_at?->toDateTimeString(),
            'match_at' => $match?->generated_at?->toDateTimeString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function raw(): array
    {
        return [
            'stage' => 'raw',
            'coverage' => null,
            'analysis_at' => null,
            'match_at' => null,
        ];
    }

    /**
     * @return array{supported: int, partial: int, no_evidence: int, not_assessable: int}
     */
    private function zeroCounts(): array
    {
        return [
            'supported' => 0,
            'partial' => 0,
            'no_evidence' => 0,
            'not_assessable' => 0,
        ];
    }

    /**
     * One pass over every job_analysis/job_match/resume attempt touching
     * the given subjects, condensed to the only two booleans triage
     * stage derivation needs: is work still in flight (queued or
     * running), and has one ever failed. Succeeded attempts are
     * already reflected in the snapshot rows themselves, so they carry
     * no stage information and are dropped here.
     *
     * An active attempt counts as in flight only when it is strictly
     * newer (created_at) than the selected snapshot for its subject —
     * an attempt row older than, or equal to, the snapshot's
     * generated_at is a crash leftover and does not demote the stage.
     * A subject with no selected snapshot has no cutoff, so its active
     * attempts count unconditionally. Failed attempts are not subject
     * to the chronology gate at all — a failure is a past event, never
     * an in-flight claim.
     *
     * @param  Collection<int, int>  $subjectIds
     * @param  array<int, Carbon>  $cutoffsBySubject  selected snapshot generated_at per subject id
     * @return array<int, array{active: bool, failed: bool}> keyed by subject id
     */
    private function attemptFlagsBySubject(Collection $subjectIds, string $subjectClass, GenerationType $generationType, array $cutoffsBySubject = []): array
    {
        $rows = GenerationAttempt::query()
            ->whereMorphedTo('subject', $subjectClass)
            ->where('generation_type', $generationType)
            ->whereIn('subject_id', $subjectIds)
            ->get(['subject_id', 'status', 'created_at']);

        $flags = [];
        foreach ($rows as $row) {
            $subjectId = (int) $row->subject_id;
            $flags[$subjectId] ??= ['active' => false, 'failed' => false];

            if (in_array($row->status, GenerationStatus::active(), true)) {
                $cutoff = $cutoffsBySubject[$subjectId] ?? null;
                if ($cutoff === null || ($row->created_at !== null && $row->created_at->gt($cutoff))) {
                    $flags[$subjectId]['active'] = true;
                }
            } elseif ($row->status === GenerationStatus::Failed) {
                $flags[$subjectId]['failed'] = true;
            }
        }

        return $flags;
    }

    /**
     * Count each selected match's JobMatchFinding rows by coverage
     * classification, zero-filled for all four buckets up front so a
     * match with no findings (or none in a given bucket) reports
     * explicit zeros rather than missing keys — absence and zero are
     * different claims the UI must be able to tell apart.
     *
     * @param  Collection<int, int>  $matchIds
     * @return array<int, array{supported: int, partial: int, no_evidence: int, not_assessable: int}> keyed by job_match_id
     */
    private function coverageCountsByMatch(Collection $matchIds): array
    {
        $rows = JobMatchFinding::query()
            ->whereIn('job_match_id', $matchIds)
            ->get(['job_match_id', 'coverage']);

        $counts = [];
        foreach ($matchIds as $matchId) {
            $matchId = (int) $matchId;
            $findings = $rows->filter(
                fn (JobMatchFinding $finding): bool => (int) $finding->job_match_id === $matchId,
            );

            $counts[$matchId] = $this->tallyCoverage($findings);
        }

        return $counts;
    }

    /**
     * Tally one match's findings into the four coverage buckets,
     * tallied on the enum's backing string value since enum instances
     * cannot serve as array keys.
     *
     * @param  Collection<int, JobMatchFinding>  $findings
     * @return array{supported: int, partial: int, no_evidence: int, not_assessable: int}
     */
    private function tallyCoverage(Collection $findings): array
    {
        $tally = $this->zeroCounts();

        foreach ($findings as $finding) {
            $tally[$finding->coverage->value]++;
        }

        return $tally;
    }
}
