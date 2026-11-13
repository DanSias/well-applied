<?php

namespace App\Support\JobDecisions;

use App\Enums\DecisionSource;
use App\Enums\DismissalReason;
use App\Enums\JobDisposition;
use App\Models\JobPosting;
use App\Support\CurrentCareerProfile;
use Illuminate\Support\Collection;

/**
 * Applies one decision to an explicitly chosen set of jobs, one job at a time through RecordJobDecision.
 *
 * Each job is its own transaction, so one job refusing never blocks or undoes the others, and the result says exactly
 * what happened to every id: changed, already in that state (so repeating a request is harmless), refused with the
 * reason, or not found. Ids are resolved against the current career profile, and an id from another profile is
 * indistinguishable from one that does not exist.
 */
final class RecordJobDecisionBatch
{
    /** Simple dispositions are cheap writes, so the limit is well above the 50 that analysis and matching allow. */
    public const MAX_ITEMS = 200;

    public function __construct(private readonly RecordJobDecision $decisions) {}

    /**
     * @param  list<int>  $jobIds
     * @return array{to: string, changed: list<int>, unchanged: list<int>, failed: list<array{id: int, message: string}>, not_found: list<int>, decision_ids: array<int, int>}
     */
    public function record(array $jobIds, JobDisposition $to, ?DismissalReason $reason = null, ?string $note = null): array
    {
        $jobs = $this->resolve($jobIds);
        $result = ['to' => $to->value, 'changed' => [], 'unchanged' => [], 'failed' => [], 'not_found' => [], 'decision_ids' => []];

        foreach ($jobIds as $id) {
            $job = $jobs->get($id);

            if ($job === null) {
                $result['not_found'][] = $id;

                continue;
            }

            try {
                $decision = $this->decisions->record($job, $to, DecisionSource::User, $reason, $note);
            } catch (JobDecisionException $e) {
                $result['failed'][] = ['id' => $id, 'message' => $e->getMessage()];

                continue;
            }

            if ($decision === null) {
                $result['unchanged'][] = $id;
            } else {
                $result['changed'][] = $id;
                $result['decision_ids'][$id] = $decision->id;
            }
        }

        return $result;
    }

    /**
     * Undo the given decisions (job id => decision id), each only while it is still that job's latest.
     *
     * @param  array<int, int>  $decisionIds
     * @return array{to: string, changed: list<int>, unchanged: list<int>, failed: list<array{id: int, message: string}>, not_found: list<int>, decision_ids: array<int, int>}
     */
    public function undo(array $decisionIds): array
    {
        $jobs = $this->resolve(array_keys($decisionIds));
        $result = ['to' => 'undo', 'changed' => [], 'unchanged' => [], 'failed' => [], 'not_found' => [], 'decision_ids' => []];

        foreach ($decisionIds as $jobId => $decisionId) {
            $job = $jobs->get($jobId);

            if ($job === null) {
                $result['not_found'][] = $jobId;

                continue;
            }

            try {
                $result['decision_ids'][$jobId] = $this->decisions->undo($job, $decisionId)->id;
                $result['changed'][] = $jobId;
            } catch (JobDecisionException $e) {
                $result['failed'][] = ['id' => $jobId, 'message' => $e->getMessage()];
            }
        }

        return $result;
    }

    /**
     * @param  array<int, int>  $ids
     * @return Collection<int, JobPosting>
     */
    private function resolve(array $ids): Collection
    {
        return CurrentCareerProfile::resolve()->jobPostings()->whereIn('id', $ids)->get()->keyBy('id');
    }
}
