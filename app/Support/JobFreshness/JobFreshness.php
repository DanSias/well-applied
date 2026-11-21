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
     * What a surface is handed: null when the feature is off.
     *
     * @return array{state: string, label: string, actionable: bool, reason: string}|null
     */
    public static function view(JobPosting $job, ?CarbonInterface $now = null): ?array
    {
        if (! self::enabled()) {
            return null;
        }
        $d = self::decide($job, $now);

        return ['state' => $d->state->value, 'label' => $d->state->label(), 'actionable' => $d->actionable(), 'reason' => $d->reason];
    }

    /** Pursued, or has an Application: never changed automatically. */
    public static function protected(JobPosting $job): bool
    {
        return $job->disposition === JobDisposition::Pursued || $job->applications()->exists();
    }
}
