<?php

namespace App\Support\JobDiscovery;

use Illuminate\Support\Facades\Cache;

/**
 * Evidence that a scheduler process is actually ticking. `routes/console.php` runs `record()` every minute, so the
 * timestamp is only ever written by a live `schedule:work` / `schedule:run`; having a schedule defined in code proves
 * nothing. It says nothing about queue workers or about any provider, which are separate questions.
 *
 * Three states, from the age of the last tick: `recent` (within three minutes, so one missed tick is tolerated),
 * `stale` (older than that) and `unknown` (never observed, for example before the first tick after an upgrade).
 */
final class SchedulerHeartbeat
{
    public const KEY = 'discovery:scheduler-last-observed';

    /** The scheduler fires every minute; three minutes of silence is more than a missed tick. */
    public const RECENT_WITHIN_SECONDS = 180;

    public static function record(): void
    {
        Cache::forever(self::KEY, now()->getTimestamp());
    }

    /**
     * @return array{state: 'recent'|'stale'|'unknown', last_observed_at: string|null, age_seconds: int|null}
     */
    public static function status(): array
    {
        $at = Cache::get(self::KEY);

        if (! is_int($at)) {
            return ['state' => 'unknown', 'last_observed_at' => null, 'age_seconds' => null];
        }

        $age = max(0, now()->getTimestamp() - $at);

        return [
            'state' => $age <= self::RECENT_WITHIN_SECONDS ? 'recent' : 'stale',
            'last_observed_at' => now()->setTimestamp($at)->toIso8601String(),
            'age_seconds' => $age,
        ];
    }
}
