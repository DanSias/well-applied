<?php

namespace App\Support\Clarification;

use App\Enums\ClarificationSessionStatus;
use App\Models\ClarificationQuestion;
use App\Models\ClarificationSession;
use App\Models\JobAnalysis;
use App\Models\JobMatch;
use App\Models\JobPosting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The optional pause between matching and resume Selection.
 *
 * One session per (posting, analysis): the first time the pipeline reaches
 * Selection it ranks questions. No question worth asking means a
 * `not_needed` session and no pause at all. Otherwise the session is
 * `pending` and the pipeline waits until the user answers, skips or
 * chooses Generate Now. A resolved session is never reopened implicitly,
 * so retries and the re-match that follows approved evidence cannot loop
 * back into questions.
 */
final class ClarificationGate
{
    public function __construct(private readonly DiscoverClarificationQuestions $discovery) {}

    public function sessionFor(JobPosting $posting, JobAnalysis $analysis): ?ClarificationSession
    {
        return ClarificationSession::query()
            ->where('job_posting_id', $posting->id)
            ->where('job_analysis_id', $analysis->id)
            ->latest('id')
            ->first();
    }

    public function pendingFor(JobPosting $posting): ?ClarificationSession
    {
        return ClarificationSession::query()
            ->where('job_posting_id', $posting->id)
            ->where('status', ClarificationSessionStatus::Pending)
            ->latest('id')
            ->first();
    }

    /**
     * True when the pipeline must wait for the user before Selection.
     */
    public function pauseIfNeeded(JobPosting $posting, JobAnalysis $analysis, JobMatch $match, ?string $runId): bool
    {
        return DB::transaction(function () use ($posting, $analysis, $match, $runId) {
            $session = ClarificationSession::query()
                ->where('job_posting_id', $posting->id)
                ->where('job_analysis_id', $analysis->id)
                ->lockForUpdate()
                ->latest('id')
                ->first();

            if ($session !== null) {
                return $session->isPending();
            }

            $candidates = $this->discovery->discover($match, 'standard');

            $session = ClarificationSession::create([
                'job_posting_id' => $posting->id,
                'job_analysis_id' => $analysis->id,
                'job_match_id' => $match->id,
                'pipeline_run_id' => $runId,
                'mode' => 'standard',
                'status' => $candidates->isEmpty() ? ClarificationSessionStatus::NotNeeded : ClarificationSessionStatus::Pending,
                'resolved_at' => $candidates->isEmpty() ? now() : null,
            ]);

            $this->persist($session, $candidates->all(), 0);

            return $session->isPending();
        });
    }

    /**
     * The user asked for questions explicitly (for example on a finished
     * resume), so this opens a new session even when an earlier one was
     * resolved. Returns null when nothing is worth asking.
     */
    public function open(JobPosting $posting, JobAnalysis $analysis, JobMatch $match, string $mode): ?ClarificationSession
    {
        return DB::transaction(function () use ($posting, $analysis, $match, $mode) {
            $existing = $this->pendingFor($posting);

            if ($existing !== null) {
                return $existing;
            }

            $candidates = $this->discovery->discover($match, $mode);

            if ($candidates->isEmpty()) {
                return null;
            }

            $session = ClarificationSession::create([
                'job_posting_id' => $posting->id,
                'job_analysis_id' => $analysis->id,
                'job_match_id' => $match->id,
                'pipeline_run_id' => (string) Str::uuid(),
                'mode' => $mode,
                'status' => ClarificationSessionStatus::Pending,
            ]);

            $this->persist($session, $candidates->all(), 0);

            return $session;
        });
    }

    /**
     * Optional deeper discovery: re-ranks with the relaxed deep policy.
     * Questions already answered or skipped in this session are kept; the
     * unanswered ones are replaced by the deeper list, never duplicated.
     */
    public function deepen(ClarificationSession $session): void
    {
        if (! $session->isPending() || $session->job_match_id === null) {
            return;
        }

        DB::transaction(function () use ($session) {
            $match = JobMatch::query()->findOrFail($session->job_match_id);
            $kept = $session->questions()->where('status', '!=', 'open')->get();
            $keptTopics = $kept->pluck('topic_key')->all();

            $session->questions()->where('status', 'open')->delete();

            $candidates = $this->discovery->discover($match, 'deep')
                ->reject(fn (ClarificationCandidate $c) => in_array($c->topicKey, $keptTopics, true))
                ->values();

            $session->update(['mode' => 'deep']);
            $this->persist($session, $candidates->all(), (int) $kept->max('sort_order') + 1);
        });
    }

    public function resolve(ClarificationSession $session, ClarificationSessionStatus $status): void
    {
        if ($session->isPending()) {
            $session->update(['status' => $status, 'resolved_at' => now()]);
        }
    }

    /**
     * @param  array<int, ClarificationCandidate>  $candidates
     */
    private function persist(ClarificationSession $session, array $candidates, int $startOrder): void
    {
        foreach ($candidates as $index => $candidate) {
            ClarificationQuestion::create([
                'clarification_session_id' => $session->id,
                'topic_key' => $candidate->topicKey,
                'topic_label' => $candidate->topicLabel,
                'job_analysis_finding_id' => $candidate->primaryFindingId,
                'related_finding_ids' => $candidate->relatedFindingIds,
                'question_text' => $candidate->questionText,
                'score' => $candidate->score,
                'score_breakdown' => $candidate->scoreBreakdown,
                'context' => $candidate->context,
                'suggested_attributable_type' => $candidate->suggestedAttributableType,
                'suggested_attributable_id' => $candidate->suggestedAttributableId,
                'suggested_visibility' => $candidate->suggestedVisibility,
                'status' => 'open',
                'sort_order' => $startOrder + $index,
            ]);
        }
    }
}
