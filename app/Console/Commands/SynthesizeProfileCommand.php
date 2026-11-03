<?php

namespace App\Console\Commands;

use App\Models\ResumeProfile;
use App\Support\CurrentCareerProfile;
use App\Support\ProfileSynthesis\ProfileAssembly;
use App\Support\ProfileSynthesis\SynthesizeProfile;
use Illuminate\Console\Command;

class SynthesizeProfileCommand extends Command
{
    protected $signature = 'profile:synthesize {slug=full-stack-product} {--force : Build a new version even if the evidence and sample are unchanged} {--inventory : Show the deterministic inventory only; no model call, nothing saved}';

    protected $description = 'Build a persistent, reviewable profile from approved evidence (one local-model call for the positioning language). Nothing is approved and no resume is generated.';

    public function handle(SynthesizeProfile $synthesize, ProfileAssembly $assembly): int
    {
        $profile = ResumeProfile::query()->where('career_profile_id', CurrentCareerProfile::resolve()->id)->where('slug', (string) $this->argument('slug'))->firstOrFail();

        if ($this->option('inventory')) {
            $inventory = $assembly->assemble($profile);
            $this->line((string) json_encode(['projects' => array_map(fn (array $p) => [$p['rank'], $p['name'], $p['score']], $inventory['projects']), 'direct' => array_column($inventory['capabilities']['direct'], 'key')], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        try {
            $synthesis = $synthesize->synthesize($profile, (bool) $this->option('force'));
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Profile version {$synthesis->version} ({$synthesis->status}) saved. Model calls: ".($synthesis->usage['model_calls'] ?? 0).'; '.round(($synthesis->usage['duration_ms'] ?? 0) / 1000).'s. Review it on the Resume Profile page; nothing is approved.');

        return self::SUCCESS;
    }
}
