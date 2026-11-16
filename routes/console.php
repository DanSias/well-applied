<?php

use App\Support\JobDiscovery\SchedulerHeartbeat;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// A generation whose worker stopped or restarted is recorded as interrupted, so it can be continued.
Schedule::command('generations:recover-stale')->everyFiveMinutes();

// Rejected-candidate records are diagnostics, kept 30 days after they were last seen. Independent of discovery itself.
Schedule::command('discovery:prune-rejections')->daily();

// A discovery attempt whose process or worker stopped is recorded as failed (interrupted / never started) so the provider
// can be run again. Independent of the generation sweep above: it only touches discovery runs and attempts.
Schedule::command('discovery:recover-stale')->everyFiveMinutes();

// Proof that a scheduler is ticking at all: the Sources page reports how long ago this last ran. Nothing else writes it.
Schedule::call(fn () => SchedulerHeartbeat::record())->name('scheduler-heartbeat')->everyMinute();
