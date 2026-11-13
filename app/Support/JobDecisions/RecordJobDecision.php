<?php

namespace App\Support\JobDecisions;

use App\Enums\DecisionSource;
use App\Enums\DismissalReason;
use App\Enums\JobDisposition;
use App\Models\JobDecision;
use App\Models\JobPosting;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of a job's disposition. Every change goes through here so the rules hold no matter who asks
 * (a button, a bulk action, a later automated rule):
 *
 * - the move must be an allowed one (JobDisposition::allowedNext());
 * - a dismissal always carries a reason;
 * - a rule may never pursue a job, and may only act on a job still in the inbox that no person has ever decided on,
 *   so automation can neither promote nor overwrite what a user chose;
 * - the job row is re-read under a lock inside the transaction, so two requests cannot both act on a stale state;
 * - a request that would change nothing writes nothing (and returns null), which also makes a repeated request safe;
 * - the change and its history row are written together or not at all.
 *
 * Undo restores the disposition a decision replaced, from that decision's own history row, and is itself recorded.
 * Rediscovery never touches any of this: it updates a fixed list of posting columns that does not include them.
 */
final class RecordJobDecision
{
    /**
     * @param  array<string, mixed>  $details
     * @return JobDecision|null the history row written, or null when the job was already in that disposition
     *
     * @throws JobDecisionException
     */
    public function record(
        JobPosting $job,
        JobDisposition $to,
        DecisionSource $source,
        ?DismissalReason $reason = null,
        ?string $note = null,
        ?string $rule = null,
        array $details = [],
    ): ?JobDecision {
        return $this->apply($job, $to, $source, $reason, $note, $rule, $details, undoOf: null);
    }

    /**
     * Reverse one decision: put the job back to what it was before, provided that decision is still the job's latest.
     *
     * @throws JobDecisionException
     */
    public function undo(JobPosting $job, int $decisionId, DecisionSource $source = DecisionSource::User): JobDecision
    {
        return DB::transaction(function () use ($job, $decisionId, $source) {
            $latest = JobDecision::query()->where('job_posting_id', $job->id)->orderByDesc('id')->first();

            if ($latest === null || $latest->id !== $decisionId) {
                throw new JobDecisionException('This job has changed since, so that decision can no longer be undone.');
            }

            $reason = null;

            if ($latest->from_disposition === JobDisposition::Dismissed) {
                $earlier = JobDecision::query()
                    ->where('job_posting_id', $job->id)->where('id', '<', $latest->id)
                    ->where('to_disposition', JobDisposition::Dismissed->value)->orderByDesc('id')->first();
                $reason = DismissalReason::tryFrom((string) $earlier?->reason) ?? DismissalReason::Other;
            }

            $written = $this->apply($job, $latest->from_disposition, $source, $reason, null, null, ['undo_of' => $latest->id], undoOf: $latest);

            return $written ?? throw new JobDecisionException('This job has changed since, so that decision can no longer be undone.');
        });
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function apply(JobPosting $job, JobDisposition $to, DecisionSource $source, ?DismissalReason $reason, ?string $note, ?string $rule, array $details, ?JobDecision $undoOf): ?JobDecision
    {
        if ($source === DecisionSource::Rule) {
            if ($to === JobDisposition::Pursued) {
                throw new JobDecisionException('Only a person can pursue a job.');
            }

            if ($rule === null || trim($rule) === '') {
                throw new JobDecisionException('A rule decision must name the rule.');
            }
        }

        if ($to === JobDisposition::Dismissed && $reason === null) {
            throw new JobDecisionException('A dismissal needs a reason.');
        }

        return DB::transaction(function () use ($job, $to, $source, $reason, $note, $rule, $details, $undoOf) {
            // Re-read under a lock: the state this decision is judged against is the one it will replace.
            $current = JobPosting::query()->lockForUpdate()->findOrFail($job->id);
            $from = $current->disposition;

            if ($from === $to) {
                return null;
            }

            if ($undoOf !== null) {
                if ($current->disposition !== $undoOf->to_disposition) {
                    return null;
                }
            } elseif (! in_array($to, $from->allowedNext(), true)) {
                throw new JobDecisionException("A {$from->value} job cannot go straight to {$to->value}.");
            }

            if ($source === DecisionSource::Rule) {
                $touchedByPerson = JobDecision::query()->where('job_posting_id', $current->id)->where('source', DecisionSource::User->value)->exists();

                if ($from !== JobDisposition::New || $touchedByPerson) {
                    throw new JobDecisionException('A rule cannot change a job a person has already decided on.');
                }
            }

            $now = now();
            $current->forceFill([
                'disposition' => $to,
                'disposition_source' => $source,
                'disposition_reason' => $to === JobDisposition::Dismissed ? $reason->value : null,
                'dispositioned_at' => $now,
                'pursued_at' => $to === JobDisposition::Pursued ? $now : null,
            ])->save();

            $job->setRawAttributes($current->getAttributes(), true);

            return JobDecision::query()->create([
                'job_posting_id' => $current->id,
                'from_disposition' => $from,
                'to_disposition' => $to,
                'source' => $source,
                'reason' => $to === JobDisposition::Dismissed ? $reason->value : null,
                'note' => $note !== null && trim($note) !== '' ? trim($note) : null,
                'rule' => $rule,
                'details' => $details === [] ? null : $details,
            ]);
        });
    }
}
