<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// A generation whose worker stopped or restarted is recorded as interrupted, so it can be continued.
Schedule::command('generations:recover-stale')->everyFiveMinutes();
