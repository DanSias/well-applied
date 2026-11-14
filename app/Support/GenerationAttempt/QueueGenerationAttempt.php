<?php

namespace App\Support\GenerationAttempt;

use App\Enums\GenerationStatus;
use App\Enums\GenerationType;
use App\Exceptions\UnsupportedGenerationTypeException;
use App\Jobs\GenerateJobAnalysisJob;
use App\Jobs\GenerateJobMatchJob;
use App\Jobs\GenerateJobQualificationJob;
use App\Jobs\GenerateResumeVariantJob;
use App\Jobs\RenderResumePdfJob;
use App\Models\GenerationAttempt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Atomically claims one generation for a subject and starts its job.
 *
 * Replaces the check-then-create pattern JobAnalysisController and
 * JobMatchController previously performed inline (does an active
 * GenerationAttempt exist? -> if not, create one and dispatch). In two
 * statements, two simultaneous store() requests for the same
 * subject+stage could both read "no active attempt" and both create+
 * dispatch — duplicate generation of the same posting/analysis. The
 * claim here is one locked critical section instead: a per
 * (generation type, subject morph class, subject id) cache lock is
 * acquired non-blocking, and only the winner re-checks for an active
 * attempt (the authoritative duplicate rule, re-read inside the lock so
 * a lost lock race is never trusted), creates a Queued attempt, and
 * dispatches the existing generation job. The loser returns null —
 * exactly the observable outcome of "an attempt was already in flight"
 * — and dispatches nothing.
 *
 * The lock covers only the claim/dispatch critical section, never the
 * generation job itself: the TTL is short, and a crashed claim releases
 * within it, so the next request can claim again. The durable
 * in-flight/duplicate semantics remain the active GenerationAttempt
 * row itself (Queued/Running), unchanged — see
 * App\Models\GenerationAttempt::scopeActive().
 *
 * Unsupported generation types (today ResumeVariant) throw
 * UnsupportedGenerationTypeException up front — before any lock, row,
 * or dispatch — so a caller bug is loud on first use and never leaves
 * a stray failed row behind. No raw prompt/provider/generated content
 * is ever written or read here; the attempt row only ever receives the
 * same lifecycle columns the generation jobs already write. See
 * App\Models\GenerationAttempt's docblock.
 */
final class QueueGenerationAttempt
{
    /**
     * Only the claim/dispatch critical section — never the generation
     * job. Sizing: the section is a query + an insert + a dispatch()
     * that, on every production queue connection, serializes one job
     * and hands it to the driver. Well under a second in practice; a
     * claim that runs longer than this has hung and should time out
     * rather than block every other claim forever (10 seconds leaves a
     * generous margin without meaningfully delaying retries).
     */
    private const LOCK_TTL_SECONDS = 10;

    /**
     * Claim exactly one generation for the given type+subject:
     * create a Queued GenerationAttempt and dispatch its job.
     * Returns null — creating nothing and dispatching nothing — when
     * the attempt can't be claimed: the claim lock is already held by
     * a concurrent claim, or the in-lock recheck found an attempt
     * already active (Queued or Running) for this type+subject.
     * Rethrows the original exception if dispatch() throws, after a
     * best-effort mark of the new attempt as Failed (dispatch_error),
     * so the row is not left stranded in Queued while the caller saw
     * an error. If that cleanup itself fails, it is logged and the
     * original dispatch exception is still the one rethrown. Throws UnsupportedGenerationTypeException for types this
     * service doesn't support claiming (today ResumeVariant) — and
     * does so before touching the cache lock, creating a row, or
     * dispatching, so an unsupported type leaves no side effects at all.
     *
     * @param  Model  $subject  A JobPosting for JobAnalysis, a JobAnalysis for JobMatch, a JobMatch for ResumeVariant, a ResumeVariant for ResumePdf.
     */
    public function claim(GenerationType $type, Model $subject, ?string $pipelineRunId = null, bool $chain = false, bool $regenerate = false, ?int $synthesisId = null): ?GenerationAttempt
    {
        $this->ensureSupported($type);

        $lock = Cache::lock(self::claimLockKey($type, $subject), self::LOCK_TTL_SECONDS);

        if (! $lock->get()) {
            return null;
        }

        try {
            $active = GenerationAttempt::query()
                ->where('subject_type', $subject->getMorphClass())
                ->where('subject_id', $subject->getKey())
                ->where('generation_type', $type)
                ->active()
                ->exists();

            if ($active) {
                return null;
            }

            $attempt = GenerationAttempt::query()->create([
                'subject_type' => $subject->getMorphClass(),
                'subject_id' => $subject->getKey(),
                'generation_type' => $type,
                'status' => GenerationStatus::Queued,
                'queued_at' => now(),
                'pipeline_run_id' => $pipelineRunId,
                'chain' => $chain,
                'regenerate' => $regenerate,
                'resume_profile_synthesis_id' => $synthesisId,
            ]);

            try {
                $this->dispatch($type, $attempt);
            } catch (Throwable $e) {
                // Best effort to durably fail the row. If the cleanup
                // itself throws, log it — but never let it swallow the
                // original dispatch exception, which is always rethrown
                // below untouched.
                try {
                    $this->markDispatchFailed($attempt, $e);
                } catch (Throwable $cleanupFailure) {
                    Log::error('Failed to record a GenerationAttempt dispatch failure.', [
                        'generation_attempt_id' => $attempt->id,
                        'generation_type' => $attempt->generation_type->value,
                        'dispatch_exception' => $e::class,
                        'cleanup_exception' => $cleanupFailure::class,
                        'message' => $cleanupFailure->getMessage(),
                    ]);
                }

                throw $e;
            }

            return $attempt;
        } finally {
            $lock->release();
        }
    }

    /**
     * Precondition: only generation types that map to a claim-supported
     * job may be dispatched here (JobAnalysis -> GenerateJobAnalysisJob,
     * JobMatch -> GenerateJobMatchJob). Anything else — today
     * ResumeVariant, whose store() still runs its own inline
     * check-then-create — means a caller bug (a new claim path that
     * forgot to wire up support), which should be loud on first use
     * rather than disguised as "no attempt claimed". Enforced before
     * any lock is taken, row created, or dispatch attempted, so an
     * unsupported type is guaranteed to leave no observable side effect.
     */
    private function ensureSupported(GenerationType $type): void
    {
        if (! in_array($type, [GenerationType::JobAnalysis, GenerationType::JobMatch, GenerationType::ResumeVariant, GenerationType::ResumePdf, GenerationType::JobQualification], true)) {
            throw new UnsupportedGenerationTypeException($type);
        }
    }

    /**
     * The cache lock key guarding one subject+stage's claim — the one
     * place the three-part identity is composed, so the lock key and
     * the in-lock recheck can never disagree on what a "subject+stage"
     * means. Distinct per generation type, per subject morph class,
     * and per subject id — an in-flight job_analysis on a JobPosting
     * and a job_match on a JobAnalysis share nothing, and neither
     * blocks the other.
     */
    public static function claimLockKey(GenerationType $type, Model $subject): string
    {
        return implode('.', [
            'generation-claims',
            $type->value,
            $subject->getMorphClass(),
            $subject->getKey(),
        ]);
    }

    /**
     * Route the generation type to its existing job and dispatch it —
     * the same JobAnalysis/JobMatch jobs the controllers already
     * dispatched, untouched; this method only decides which job runs.
     *
     * Deliberately a bare statement: GenerateJobAnalysisJob::dispatch()
     * returns a PendingDispatch whose __destruct() performs the actual
     * bus dispatch, and __destruct fires as soon as that temporary is
     * released. Kept as a throwaway temporary (never stored on a
     * property) so it destructs — and thus dispatches, or throws —
     * synchronously, inside claim()'s try/catch. Retaining the value
     * would defer __destruct to the property outliving claim(), moving
     * a dispatch failure out of reach of markDispatchFailed below.
     * The default arm is unreachable via claim() (ensureSupported()
     * already threw) but kept total so the match can never widen.
     */
    private function dispatch(GenerationType $type, GenerationAttempt $attempt): void
    {
        match ($type) {
            GenerationType::JobAnalysis => GenerateJobAnalysisJob::dispatch($attempt),
            GenerationType::JobMatch => GenerateJobMatchJob::dispatch($attempt),
            GenerationType::ResumeVariant => GenerateResumeVariantJob::dispatch($attempt),
            GenerationType::ResumePdf => RenderResumePdfJob::dispatch($attempt),
            GenerationType::JobQualification => GenerateJobQualificationJob::dispatch($attempt),
        };
    }

    /**
     * A synchronous dispatch() failure is an infrastructure failure,
     * not a generation failure: the row must end durably Failed with
     * the category this milestone defined (dispatch_error — see the
     * job classes' own failure_category values for the generation-side
     * categories), or it would sit in Queued forever while the caller
     * already saw the request error. The original exception is
     * rethrown by the caller untouched.
     */
    private function markDispatchFailed(GenerationAttempt $attempt, Throwable $e): void
    {
        $attempt->update([
            'status' => GenerationStatus::Failed,
            'finished_at' => now(),
            'failure_category' => 'dispatch_error',
            'failure_message' => 'Generation dispatch failed: '.$e->getMessage(),
        ]);

        Log::error('Generation dispatch failed.', [
            'generation_attempt_id' => $attempt->id,
            'generation_type' => $attempt->generation_type->value,
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);
    }
}
