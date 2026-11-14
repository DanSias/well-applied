<?php

namespace App\Console\Commands;

use App\Support\JobDiscovery\DiscoveryRejectionLog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Removes rejected-candidate records not seen for the retention period. Only discovery_rejections rows are deleted — never
 * job postings, discovery attempts, dispositions or decisions — and running it again deletes nothing further.
 */
#[Signature('discovery:prune-rejections {--days= : Override the retention period in days (default: job_discovery.rejections.retention_days)}')]
#[Description('Delete discovery rejection records that have not been seen within the retention period.')]
class PruneDiscoveryRejectionsCommand extends Command
{
    public function handle(DiscoveryRejectionLog $log): int
    {
        $option = $this->option('days');
        $days = $option === null || $option === '' ? (int) config('job_discovery.rejections.retention_days') : (int) $option;

        if ($days < 1) {
            $this->components->error('The retention period must be at least 1 day.');

            return self::FAILURE;
        }

        $deleted = $log->prune($days);
        $this->components->info("Pruned {$deleted} rejection record(s) not seen in the last {$days} days.");

        return self::SUCCESS;
    }
}
