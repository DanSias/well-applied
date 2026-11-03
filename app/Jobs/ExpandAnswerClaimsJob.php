<?php

namespace App\Jobs;

use App\Models\ClarificationAnswer;
use App\Support\Expansion\ExpandAnswerClaims;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * "Expand into separate facts" for one answer. Started only by the user; the single model call
 * is cached by fingerprint inside ExpandAnswerClaims.
 */
final class ExpandAnswerClaimsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 700;

    public static function lockKey(int $answerId): string
    {
        return "expand-answer:{$answerId}";
    }

    public function __construct(public readonly int $answerId, public readonly bool $force = false) {}

    public function handle(ExpandAnswerClaims $expand): void
    {
        try {
            $expand->expand(ClarificationAnswer::query()->findOrFail($this->answerId), $this->force);
        } finally {
            Cache::forget(self::lockKey($this->answerId));
        }
    }

    public function failed(?Throwable $e): void
    {
        Cache::forget(self::lockKey($this->answerId));
    }
}
