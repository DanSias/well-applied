<?php

namespace App\Console\Commands;

use App\Models\ResumeProfile;
use App\Support\Consolidation\PrepareConsolidation;
use App\Support\CurrentCareerProfile;
use Illuminate\Console\Command;

class PrepareConsolidationCommand extends Command
{
    protected $signature = 'evidence:consolidate {--dry-run : Report what would be prepared without saving} {--profile=full-stack-product : Resume Profile whose interview receives the follow-up questions}';

    protected $description = 'Prepare the evidence-consolidation review queue. Creates review items and questions only; no fact, proposal or answer changes.';

    public function handle(PrepareConsolidation $prepare): int
    {
        $career = CurrentCareerProfile::resolve();
        $profile = ResumeProfile::query()->where('career_profile_id', $career->id)->where('slug', (string) $this->option('profile'))->first();
        $result = $prepare->run($career, $profile, (bool) $this->option('dry-run'));

        $this->info(sprintf('%s %d review item(s); %d already existed; %d question(s).', $this->option('dry-run') ? 'Would prepare' : 'Prepared', $result['created'], $result['existing'], $result['questions']));

        foreach ($result['problems'] as $problem) {
            $this->warn($problem);
        }

        return $result['problems'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
