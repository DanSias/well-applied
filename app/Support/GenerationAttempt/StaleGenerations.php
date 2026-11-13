<?php

namespace App\Support\GenerationAttempt;

use App\Enums\GenerationStatus;
use App\Models\GenerationAttempt;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tells a generation that is still working from one that was abandoned, and records the second as interrupted.
 *
 * A persisted `queued` or `running` is a claim, not proof of life: a worker that is killed, restarted or put to sleep
 * mid-generation cannot mark its own attempt, and the row stays `running` forever (the framework's failure callback
 * never runs for a process that no longer exists). Nothing in an attempt says whether anyone is still on it, so the
 * only signal there is, is how long it has been silent: `updated_at` is touched when the attempt is created, claimed
 * and whenever it reports a phase.
 *
 * The threshold is the queue's own lease, `retry_after` (2 hours for the database queue here). It is the documented
 * upper bound of one legitimate job (a local Ollama Selection then Wording, each up to 15 minutes, plus margin): the
 * queue itself hands a job it has held longer than that to another worker, so an attempt silent for that long cannot
 * still be running. Anything shorter risks marking a slow but live generation as dead. The check is on the
 * persisted timestamp alone, so it is deterministic and needs no worker to answer.
 *
 * An interrupted attempt becomes an ordinary failed one with the category `interrupted`. Its job, if it ever runs,
 * finds it no longer queued and skips it (claiming is one atomic UPDATE), so two workers can never run one attempt; and
 * a retry is a new attempt that reuses every finished stage, as any retry does.
 */
final class StaleGenerations
{
    public const CATEGORY = 'interrupted';

    public const MESSAGE = 'This generation was interrupted before it finished: the worker stopped or restarted. Nothing finished was lost; continue to pick up from where it stopped.';

    /** How long a run may sit between one stage finishing and the next being queued before it is called stalled. */
    public const STALL_GRACE_SECONDS = 120;

    public function leaseSeconds(): int
    {
        $lease = (int) config('queue.connections.'.config('queue.default').'.retry_after', 0);

        return $lease > 0 ? $lease : 7200;
    }

    public function cutoff(): CarbonInterface
    {
        return now()->subSeconds($this->leaseSeconds());
    }

    /** Active (queued or running) and silent for longer than the lease. */
    public function isStale(GenerationAttempt $attempt): bool
    {
        return in_array($attempt->status, GenerationStatus::active(), true)
            && $attempt->updated_at !== null
            && $attempt->updated_at->lessThanOrEqualTo($this->cutoff());
    }

    /** An attempt this class (or the sweep) already recorded as interrupted. */
    public function wasInterrupted(GenerationAttempt $attempt): bool
    {
        return $attempt->status === GenerationStatus::Failed && $attempt->failure_category === self::CATEGORY;
    }

    /**
     * Records every stale active attempt in the given set as interrupted, and returns how many. Each is one conditional
     * UPDATE that re-checks status and silence, so an attempt that finished or reported in the meantime is left alone.
     *
     * @param  Builder<GenerationAttempt>|null  $within  Limit to these attempts (default: all of them).
     */
    public function recover(?Builder $within = null): int
    {
        $cutoff = $this->cutoff();
        $ids = ($within ?? GenerationAttempt::query())->active()->where('updated_at', '<=', $cutoff)->pluck('id');
        $recovered = 0;

        foreach ($ids as $id) {
            $recovered += GenerationAttempt::query()
                ->whereKey($id)
                ->active()
                ->where('updated_at', '<=', $cutoff)
                ->update([
                    'status' => GenerationStatus::Failed,
                    'finished_at' => now(),
                    'failure_category' => self::CATEGORY,
                    'failure_message' => self::MESSAGE,
                    'updated_at' => now(),
                ]);
        }

        return $recovered;
    }
}
