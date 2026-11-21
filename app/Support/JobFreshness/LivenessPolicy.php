<?php

namespace App\Support\JobFreshness;

use Carbon\CarbonImmutable;

/**
 * The evidence-based policy. PURE: observations in, a decision out.
 *
 *  1. KNOWN EXPIRY (a provider-stated date that has passed) makes a job non-actionable immediately, from one observation, with no confirmation and no request. It wins over a
 *     conflicting "open" answer (the API can be cached), and the conflict is recorded.
 *  2. An EXPLICIT closed answer (Himalayas 410, Jobicy `closed`) is evidence, not proof: it needs `confirm_closed_count` consecutive decisive observations, at least
 *     `min_gap_hours` apart (so one run can not confirm itself). Until then the job is `uncertain` ("May be closed") and still actionable.
 *  3. UNCERTAIN observations (404, `unknown`, timeout, 429, 5xx, an unreadable answer) are never closure and neither extend nor reset a streak.
 *  4. An `active` observation resets a closed streak.
 *  5. A job already CLOSED is reopened only by `reopen_active_count` consecutive, separate active observations from the provider's own endpoints. Discovery data (a rediscovered
 *     listing, its metadata, a future expiryDate in a feed) is not an observation and never reopens anything. A known expiry is never reopened here.
 *  6. PROTECTED jobs (pursued, or with an Application) are never given a status-changing action: they get `review`.
 */
final class LivenessPolicy
{
    public function __construct(
        private readonly int $confirmClosed = 2,
        private readonly int $minGapHours = 6,
        private readonly int $reopenActive = 2,
    ) {}

    public static function fromConfig(): self
    {
        return new self((int) config('job_freshness.confirm_closed_count', 2), (int) config('job_freshness.min_gap_hours', 6), (int) config('job_freshness.reopen_active_count', 2));
    }

    /**
     * @param  list<Observation>  $observations  any order
     * @param  bool  $currentlyClosed  the job's stored lifecycle status is already closed
     * @param  bool  $protected  pursued, or has an Application
     */
    public function decide(array $observations, bool $currentlyClosed, bool $protected, CarbonImmutable $now): LivenessDecision
    {
        usort($observations, fn (Observation $a, Observation $b) => $a->observedAt <=> $b->observedAt);
        $evidence = array_map(fn (Observation $o) => $o->toArray(), array_slice($observations, -6));

        // 1. Known expiry.
        $expired = array_values(array_filter($observations, fn (Observation $o) => $o->result === Observation::EXPIRED && ($o->expiresAt === null || $o->expiresAt->lessThanOrEqualTo($now))));
        if ($expired !== []) {
            // An "open" answer seen at or after the expiry date contradicts it.
            $since = $expired[0]->expiresAt ?? $expired[0]->observedAt;
            $conflict = array_filter($observations, fn (Observation $o) => $o->result === Observation::ACTIVE && $o->observedAt >= $since) !== [];

            return $this->finish(FreshnessState::Expired, $currentlyClosed ? 'none' : 'set_closed', $conflict ? 'expired_known_conflicting_open_answer' : 'expired_known', $protected, $evidence, $conflict);
        }

        $decisive = array_values(array_filter($observations, fn (Observation $o) => in_array($o->result, [Observation::ACTIVE, Observation::CLOSED], true)));
        $closedStreak = $this->streak($decisive, Observation::CLOSED);
        $activeStreak = $this->streak($decisive, Observation::ACTIVE);

        // 5. A job already closed.
        if ($currentlyClosed) {
            return $activeStreak >= $this->reopenActive
                ? $this->finish(FreshnessState::Active, 'reopen', 'reopen_confirmed_by_provider', $protected, $evidence)
                : $this->finish(FreshnessState::Closed, 'none', 'closed_stays_closed', $protected, $evidence);
        }

        // 2-4. An open job.
        if ($closedStreak >= $this->confirmClosed) {
            return $this->finish(FreshnessState::Closed, 'set_closed', 'closure_confirmed', $protected, $evidence);
        }
        if ($closedStreak >= 1) {
            return $this->finish(FreshnessState::Uncertain, 'none', 'closed_once_awaiting_confirmation', $protected, $evidence);
        }
        if ($decisive !== []) {
            return $this->finish(FreshnessState::Active, 'none', 'provider_reports_open', $protected, $evidence);
        }
        if ($observations !== []) {
            return $this->finish(FreshnessState::Uncertain, 'none', 'only_uncertain_observations', $protected, $evidence);
        }

        return $this->finish(FreshnessState::Unchecked, 'none', 'no_observation', $protected, $evidence);
    }

    /**
     * Trailing run of `$result` among decisive observations, counting only observations at least `min_gap_hours` after the previous COUNTED one.
     *
     * @param  list<Observation>  $decisive  chronological
     */
    private function streak(array $decisive, string $result): int
    {
        $count = 0;
        $last = null;
        for ($i = count($decisive) - 1; $i >= 0; $i--) {
            if ($decisive[$i]->result !== $result) {
                break;
            }
            if ($last === null || $last->diffInHours($decisive[$i]->observedAt, true) >= $this->minGapHours) {
                $count++;
                $last = $decisive[$i]->observedAt;
            }
        }

        return $count;
    }

    /** @param list<array<string, mixed>> $evidence */
    private function finish(FreshnessState $state, string $action, string $reason, bool $protected, array $evidence, bool $conflict = false): LivenessDecision
    {
        // A protected job is labelled, never changed: its would-be status change becomes a review item.
        if ($protected && in_array($action, ['set_closed', 'reopen'], true)) {
            return new LivenessDecision($state, 'review', $reason, true, $evidence, $conflict);
        }

        return new LivenessDecision($state, $action, $reason, $protected, $evidence, $conflict);
    }
}
