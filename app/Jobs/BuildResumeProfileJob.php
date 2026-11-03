<?php

namespace App\Jobs;

use App\Enums\MarketSampleStatus;
use App\Enums\ResumeProfileStatus;
use App\Models\MarketSample;
use App\Models\ResumeProfile;
use App\Support\ResumeProfile\BuildResumeProfile;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs BuildResumeProfile off the request. $tries = 1: provider fetches
 * already retry transient failures, and a second attempt would repeat the
 * requests the quota rules are there to limit.
 */
final class BuildResumeProfileJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public readonly int $resumeProfileId, public readonly bool $pilot = false) {}

    public function handle(BuildResumeProfile $build): void
    {
        $build->run(ResumeProfile::query()->findOrFail($this->resumeProfileId), $this->pilot);
    }

    public function failed(?Throwable $e): void
    {
        MarketSample::query()->where('resume_profile_id', $this->resumeProfileId)->where('status', MarketSampleStatus::Collecting)
            ->update(['status' => MarketSampleStatus::Failed, 'failure' => 'The build did not finish.']);
        ResumeProfile::query()->whereKey($this->resumeProfileId)->where('status', ResumeProfileStatus::Sampling)->update(['status' => ResumeProfileStatus::Draft]);
    }
}
