<?php

namespace App\Jobs;

use App\Models\ResumeProfile;
use App\Support\ProfileSynthesis\SynthesizeProfile;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

final class SynthesizeProfileJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1500;

    public function __construct(public readonly int $resumeProfileId, public readonly bool $force = false) {}

    public static function lockKey(int $resumeProfileId): string
    {
        return "profile-synthesis:{$resumeProfileId}";
    }

    public static function errorKey(int $resumeProfileId): string
    {
        return "profile-synthesis-error:{$resumeProfileId}";
    }

    public function handle(SynthesizeProfile $synthesize): void
    {
        Cache::forget(self::errorKey($this->resumeProfileId));

        try {
            $synthesize->synthesize(ResumeProfile::query()->findOrFail($this->resumeProfileId), $this->force);
        } catch (\RuntimeException $e) {
            Cache::put(self::errorKey($this->resumeProfileId), mb_substr($e->getMessage(), 0, 600), now()->addHour());
        } finally {
            Cache::forget(self::lockKey($this->resumeProfileId));
        }
    }

    public function failed(?\Throwable $e): void
    {
        Cache::put(self::errorKey($this->resumeProfileId), 'The synthesis did not finish.', now()->addHour());
        Cache::forget(self::lockKey($this->resumeProfileId));
    }
}
