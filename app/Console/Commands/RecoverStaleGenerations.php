<?php

namespace App\Console\Commands;

use App\Support\GenerationAttempt\StaleGenerations;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('generations:recover-stale')]
#[Description('Record generation attempts that were abandoned (a stopped or restarted worker) as interrupted, so they can be continued')]
class RecoverStaleGenerations extends Command
{
    public function handle(StaleGenerations $stale): int
    {
        $count = $stale->recover();

        $this->info($count === 0 ? 'No abandoned generations.' : "Recorded {$count} abandoned generation(s) as interrupted.");

        return self::SUCCESS;
    }
}
