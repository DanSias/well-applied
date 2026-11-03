<?php

namespace App\Jobs;

use App\Models\ResumeProfileSynthesis;
use App\Support\ProfileSynthesis\GenerateReferenceResume;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

final class GenerateReferenceResumeJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** The wording stage alone allows up to three provider calls. */
    public int $timeout = 3000;

    public function __construct(public readonly int $synthesisId) {}

    public static function lockKey(int $synthesisId): string
    {
        return "reference-resume:{$synthesisId}";
    }

    public function handle(GenerateReferenceResume $generate): void
    {
        try {
            $generate->generate(ResumeProfileSynthesis::query()->findOrFail($this->synthesisId));
        } finally {
            Cache::forget(self::lockKey($this->synthesisId));
        }
    }

    public function failed(?\Throwable $e): void
    {
        Cache::forget(self::lockKey($this->synthesisId));
    }
}
