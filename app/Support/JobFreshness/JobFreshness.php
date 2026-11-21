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
            $stored = $metadata['discovery']['expiryDate'] ?? null;
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
     * @return array{state: string, label: string, actionable: bool, reason: string, tone: string, detail: string, confirmed_at: string|null, checked: bool}|null
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

        return ['state' => $d->state->value, 'label' => $label, 'actionable' => $d->actionable(), 'reason' => $d->reason, 'tone' => $tone, 'detail' => $detail, 'confirmed_at' => $confirmedAt, 'checked' => in_array($d->state, [FreshnessState::Active, FreshnessState::Expired, FreshnessState::Closed, FreshnessState::Uncertain], true)];
    }

    /** Pursued, or has an Application: never changed automatically. */
    public static function protected(JobPosting $job): bool
    {
        return $job->disposition === JobDisposition::Pursued || $job->applications()->exists();
    }
}
