<?php

namespace App\Console\Commands;

use App\Models\JobPosting;
use App\Support\JobFreshness\Demo\DemoGuard;
use App\Support\JobFreshness\Demo\DemoRefused;
use App\Support\JobFreshness\Demo\DemoSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Migrates and seeds a DISPOSABLE SQLite demo database for reviewing the freshness UI. It refuses unless every DemoGuard condition holds (explicit marker, a freshness-demo*.sqlite
 * path that is not the production file, this process already running on that file, QUEUE_CONNECTION=discard); the guard runs BEFORE any migration or write. Makes no request.
 */
#[Signature('freshness:demo {--database= : Absolute path of the demo file, e.g. /tmp/freshness-demo.sqlite}')]
#[Description('Create and seed a disposable freshness UI demo database (refuses to touch the production database)')]
class FreshnessDemo extends Command
{
    public function handle(): int
    {
        $database = (string) $this->option('database');
        try {
            DemoGuard::assertSafeToSeed($database);
            DemoGuard::assertProcessIsolated();
        } catch (DemoRefused $e) {
            $this->error('Refused ('.$e->reason.'): '.$e->getMessage());

            return self::FAILURE;
        }

        if (! is_file($database)) {
            touch($database);
        }
        Artisan::call('migrate', ['--force' => true]);
        if (JobPosting::query()->exists()) {
            $this->warn('The demo database is already seeded: delete the file to start again.');

            return self::SUCCESS;
        }
        $ids = DemoSeeder::seed();
        DB::statement('PRAGMA user_version = '.DemoGuard::USER_VERSION_MARKER);

        $this->info('Demo database ready: '.count($ids).' fictional jobs.');
        foreach ($ids as $label => $id) {
            $this->line(sprintf('  #%-3d %s', $id, $label));
        }
        $this->line('Serve it with: FRESHNESS_DEMO=1 QUEUE_CONNECTION=discard JOB_FRESHNESS_ENABLED=true DB_CONNECTION=sqlite DB_DATABASE='.$database.' php artisan serve --port=8123');

        return self::SUCCESS;
    }
}
