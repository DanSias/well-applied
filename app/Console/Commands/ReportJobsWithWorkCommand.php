<?php

namespace App\Console\Commands;

use App\Support\JobDecisions\ReportJobsWithWork;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('jobs:existing-work {--json : Print the report as JSON}')]
#[Description('Read-only: list jobs that already have resume or application work, with their current disposition')]
class ReportJobsWithWorkCommand extends Command
{
    public function handle(ReportJobsWithWork $report): int
    {
        $rows = $report->report();

        if ($this->option('json')) {
            $this->line((string) json_encode($rows, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        if ($rows === []) {
            $this->info('No jobs have resume or application work.');

            return self::SUCCESS;
        }

        $this->table(
            ['Job', 'Company', 'Title', 'Disposition', 'Resumes', 'Final', 'Applications', 'Questions'],
            array_map(fn (array $r) => [
                $r['job_id'], $r['company'], $r['title'], $r['disposition'], $r['resume_variants'],
                $r['final_resume_variant_id'] ?? '-', count($r['applications']), $r['clarification_sessions'],
            ], $rows),
        );
        $this->line(count($rows).' job(s). Nothing was changed.');

        return self::SUCCESS;
    }
}
