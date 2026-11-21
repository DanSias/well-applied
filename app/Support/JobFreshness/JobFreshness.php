<?php

namespace App\Support\JobFreshness;

use App\Enums\JobDiscoverySource;
use App\Enums\JobDisposition;
use App\Enums\JobPostingLifecycleStatus;
use App\Models\JobPosting;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The read model: how fresh a job is, derived from what is STORED (the provider's own `expiryDate`, the lifecycle status, recorded observations). It reads and writes
 * nothing else, makes no request, and with the feature off (the default) every surface gets null and behaves exactly as before.
 *
 * A job that has been pursued or has an Application keeps its place and its history: freshness only LABELS it.
 */
final class JobFreshness
{
    public static function enabled(): bool
    {
        return (bool) config('job_freshness.enabled', false);
    }

    /** The decision for a job right now, whether or not the feature is on (reports and tests use this). @param list<Observation> $extra */
    public static function decide(JobPosting $job, ?CarbonInterface $now = null, array $extra = []): LivenessDecision
    {
        $now = CarbonImmutable::instance($now ?? now());
        $metadata = $job->discovery_metadata;
        $observations = [...LivenessRecord::observations($metadata), ...$extra];

        // Himalayas states its own expiry on every job; a passed date is known expiry without any request.
        if ($job->discovery_source === JobDiscoverySource::Himalayas) {
            $stored = HimalayasLivenessInterpreter::storedSeconds($metadata['discovery']['expiryDate'] ?? null);
            foreach ((new HimalayasLivenessInterpreter)->interpret($stored, null, $now) as $o) {
                $observations[] = $o;
            }
        }

        return LivenessPolicy::fromConfig()->decide($observations, $job->status === JobPostingLifecycleStatus::Closed, self::protected($job), $now);
    }

    /**
     * What a surface is handed: null when the feature is off. The `tone` says how loudly to show it, so the three kinds of "open" are never confused:
     *   - `ok`      the PROVIDER confirmed the job open (an observation from its own endpoint), with when;
     *   - `neutral` no live check has been made ("Not live-checked": open only as far as our records go), or the last check was inconclusive;
     *   - `warn`    reserved for a known expiry, a confirmed closure, and a closure reported once but not yet confirmed.
     *
     * @return array{state: string, label: string, actionable: bool, reason: string, tone: string, detail: string, new_work_block: string|null, confirmed_at: string|null, checked: bool}|null
     */
    public static function view(JobPosting $job, ?CarbonInterface $now = null): ?array
    {
        if (! self::enabled()) {
            return null;
        }
        $d = self::decide($job, $now);

        $confirmedAt = null;
        foreach ($d->evidence as $e) {
            if (($e['result'] ?? null) === Observation::ACTIVE) {
                $confirmedAt = $e['observed_at'] ?? $confirmedAt;
            }
        }
        $expiredOn = null;
        foreach ($d->evidence as $e) {
            if (($e['result'] ?? null) === Observation::EXPIRED && isset($e['expires_at'])) {
                $expiredOn = $e['expires_at'];
            }
        }

        [$tone, $label, $detail] = match (true) {
            $d->state === FreshnessState::Expired => ['warn', 'Expired', $expiredOn !== null ? 'The provider gave this job an expiry date, and it has passed.' : 'The provider says this job has expired.'],
            $d->state === FreshnessState::Closed => ['warn', 'Closed', 'The provider reported this job closed on repeated checks.'],
            $d->state === FreshnessState::Uncertain && $d->reason === 'closed_once_awaiting_confirmation' => ['warn', 'May be closed', 'The provider reported this job closed once; it has not been confirmed.'],
            $d->state === FreshnessState::Uncertain => ['neutral', 'Check inconclusive', 'The last check with the provider gave no clear answer. This is not a sign the job is closed.'],
            $d->state === FreshnessState::Active => ['ok', 'Confirmed open', 'The provider confirmed this job open.'],
            default => ['neutral', 'Not live-checked', 'No live check with the provider has been made, so this job is open only as far as our records go.'],
        };

        return ['state' => $d->state->value, 'label' => $label, 'actionable' => $d->actionable(), 'reason' => $d->reason, 'tone' => $tone, 'detail' => $detail, 'new_work_block' => $d->actionable() ? null : self::blockMessage($d->state), 'confirmed_at' => $confirmedAt, 'checked' => in_array($d->state, [FreshnessState::Active, FreshnessState::Expired, FreshnessState::Closed, FreshnessState::Uncertain], true)];
    }

    /** Pursued, or has an Application: never changed automatically, never left out of a list. */
    public static function protected(JobPosting $job): bool
    {
        if ($job->disposition === JobDisposition::Pursued) {
            return true;
        }

        // `withCount('applications')` (used by leftOutIds) spares one query per job.
        return isset($job->applications_count) ? (int) $job->applications_count > 0 : $job->applications()->exists();
    }

    /**
     * THE effective-actionability decision, shared by every list, count, backend guard and label. A job is actionable unless it is known EXPIRED or confirmed CLOSED:
     * a persisted `closed` status, a passed provider expiry, or closure confirmed by recorded observations. "May be closed" and "Not live-checked" stay actionable. With
     * the feature off it is always true (nothing changes).
     */
    public static function actionable(JobPosting $job, ?CarbonInterface $now = null): bool
    {
        return ! self::enabled() || self::decide($job, $now)->actionable();
    }

    /** A list leaves a job out only if it is not actionable AND not protected (a pursued job or one with an Application keeps its place, labelled). */
    public static function leftOut(JobPosting $job, ?CarbonInterface $now = null): bool
    {
        return self::enabled() && ! self::decide($job, $now)->actionable() && ! self::protected($job);
    }

    /**
     * Why NEW pursuit work (shortlist, pursue, a new inspection, a new tailored resume, a preparation) may not be started on this job, or null when it may. Protected jobs are NOT
     * exempt: protection is historical access, not permission to start new work. Always null with the feature off.
     */
    public static function newWorkBlock(JobPosting $job, ?CarbonInterface $now = null): ?string
    {
        if (self::actionable($job, $now)) {
            return null;
        }

        return self::blockMessage(self::decide($job, $now)->state);
    }

    private static function blockMessage(FreshnessState $state): string
    {
        return ($state === FreshnessState::Expired ? 'This job has expired' : 'This job is closed').', so new pursuit work (shortlisting, pursuing, inspecting, tailoring a resume or preparing an application) cannot be started on it. Everything already done, and the history, stay available.';
    }

    /** Refuses (HTTP 422) when new pursuit work may not be started on this job. A no-op with the feature off. */
    public static function abortUnlessNewWorkAllowed(JobPosting $job): void
    {
        $block = self::newWorkBlock($job);
        abort_if($block !== null, 422, (string) $block);
    }

    /**
     * Ids, within `$scope` (an Eloquent builder or relation with whatever filters apply), of the jobs a list leaves out. Candidates are narrowed in SQL to the only jobs
     * that can be non-actionable (persisted closed, a passed integer expiry, or a recorded liveness record); each is then judged by the SAME decide() as everywhere else.
     *
     * @return list<int>
     */
    public static function leftOutIds(mixed $scope): array
    {
        if (! self::enabled()) {
            return [];
        }
        $now = CarbonImmutable::now();

        return (clone $scope)->reorder()
            ->where(fn ($q) => $q->where('status', JobPostingLifecycleStatus::Closed->value)
                ->orWhere('discovery_metadata->discovery->expiryDate', '<=', $now->getTimestamp())
                ->orWhereNotNull('discovery_metadata->liveness'))
            ->withCount('applications')
            ->get()
            ->filter(fn (JobPosting $job) => self::leftOut($job, $now))
            ->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }
}
