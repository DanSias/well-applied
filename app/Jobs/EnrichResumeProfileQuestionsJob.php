<?php

namespace App\Jobs;

use App\Models\ResumeProfile;
use App\Support\ResumeProfile\QuestionEnrichment;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The "Improve interview questions" action: one batched call through the existing
 * QuestionEnrichment (cache, validation and fallback included). Started only by the user.
 */
final class EnrichResumeProfileQuestionsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 700;

    public static function lockKey(int $profileId): string
    {
        return "resume-profile-enrich:{$profileId}";
    }

    public function __construct(public readonly int $resumeProfileId) {}

    public function handle(QuestionEnrichment $enrichment): void
    {
        try {
            $enrichment->enrich(ResumeProfile::query()->findOrFail($this->resumeProfileId));
        } finally {
            Cache::forget(self::lockKey($this->resumeProfileId));
        }
    }

    public function failed(?Throwable $e): void
    {
        Cache::forget(self::lockKey($this->resumeProfileId));
    }
}
