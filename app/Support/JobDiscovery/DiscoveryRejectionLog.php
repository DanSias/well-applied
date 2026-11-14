<?php

namespace App\Support\JobDiscovery;

use App\Models\DiscoveryRejection;

/**
 * Keeps the discovery_rejections table in step with the filter: a rejected candidate is recorded (or its sighting
 * refreshed), a later-accepted candidate's rejection is resolved. Never touches job_postings.
 *
 * Identity is (source, source_job_id), the same identity ingestion uses. Every provider's normalize() requires an id, so
 * an empty one is not expected; if it ever happens the candidate is simply not recorded, never recorded under a guess.
 */
class DiscoveryRejectionLog
{
    private const TITLE_LIMIT = 255;

    private const URL_LIMIT = 1000;

    private const IDENTITY_LIMIT = 500;

    public function record(DiscoveredJobCandidate $candidate, FilterDecision $decision, ?int $discoveryRunId): void
    {
        $identity = $this->identity($candidate);
        if ($identity === null || $decision->reason === null) {
            return;
        }

        $now = now();
        $current = [
            'title' => mb_substr($candidate->title, 0, self::TITLE_LIMIT),
            'company' => mb_substr($candidate->company, 0, self::TITLE_LIMIT),
            'location' => $candidate->location === null ? null : mb_substr($candidate->location, 0, self::TITLE_LIMIT),
            'remote_status' => $candidate->remoteStatus?->value,
            'source_url' => $candidate->applicationUrl !== null && mb_strlen($candidate->applicationUrl) <= self::URL_LIMIT ? $candidate->applicationUrl : null,
            'reason_code' => $decision->reason,
            'matched_term' => $decision->matchedTerm,
            'matched_field' => $decision->matchedField,
            'evidence_snippet' => $decision->evidence,
            'last_seen_at' => $now,
            'last_discovery_run_id' => $discoveryRunId,
            // Rejected again after an acceptance: it is a current rejection once more.
            'resolved_at' => null,
        ];

        $existing = DiscoveryRejection::query()->where('source', $candidate->source)->where('source_job_id', $identity)->first();

        if ($existing === null) {
            DiscoveryRejection::create($current + [
                'source' => $candidate->source,
                'source_job_id' => $identity,
                'first_seen_at' => $now,
                'seen_count' => 1,
            ]);

            return;
        }

        $existing->update($current + ['seen_count' => $existing->seen_count + 1]);
    }

    /**
     * An accepted candidate that was rejected earlier: keep the row (and its last reason) as history, but stop presenting
     * it as a current rejection.
     */
    public function resolve(DiscoveredJobCandidate $candidate): void
    {
        $identity = $this->identity($candidate);
        if ($identity === null) {
            return;
        }

        DiscoveryRejection::query()
            ->where('source', $candidate->source)
            ->where('source_job_id', $identity)
            ->whereNull('resolved_at')
            ->update(['resolved_at' => now()]);
    }

    private function identity(DiscoveredJobCandidate $candidate): ?string
    {
        $id = trim($candidate->sourceJobId);

        return $id === '' ? null : mb_substr($id, 0, self::IDENTITY_LIMIT);
    }

    /**
     * Delete rejections not seen for more than $days days (a row exactly $days old is kept). Touches only
     * discovery_rejections, resolved rows included, and is safe to repeat.
     */
    public function prune(int $days): int
    {
        return DiscoveryRejection::query()->where('last_seen_at', '<', now()->subDays($days))->delete();
    }
}
