<?php

namespace App\Console\Commands;

use App\Support\JobDiscovery\DiscoveryExecutionRecovery;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('discovery:recover-stale')]
#[Description('Record discovery attempts that were abandoned (a stopped process or worker) as failed, and settle their runs')]
class RecoverStaleDiscoveryRuns extends Command
{
    public function handle(DiscoveryExecutionRecovery $recovery): int
    {
        $count = $recovery->recover();

        $this->info($count === 0 ? 'No abandoned discovery runs.' : "Recorded {$count} abandoned discovery attempt(s) as failed.");

        return self::SUCCESS;
    }
}
