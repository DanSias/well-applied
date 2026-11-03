<?php

namespace App\Console\Commands;

use App\Support\CurrentCareerProfile;
use App\Support\ResumeProfile\ProfileDefinitions;
use App\Support\ResumeProfile\QuestionEnrichment;
use Illuminate\Console\Command;

class EnrichResumeProfileCommand extends Command
{
    protected $signature = 'profile:enrich {slug=full-stack-product}';

    protected $description = 'Phrase a Resume Profile\'s open interview questions with the local model, in one batch, and cache them.';

    public function handle(QuestionEnrichment $enrichment): int
    {
        $profile = ProfileDefinitions::ensure(CurrentCareerProfile::resolve(), (string) $this->argument('slug'));
        $stats = $enrichment->enrich($profile);

        $this->line(json_encode($stats, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return $stats['failed'] ? self::FAILURE : self::SUCCESS;
    }
}
