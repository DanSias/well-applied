<?php

use App\Models\CareerProfile;
use App\Support\JobDiscovery\SchedulerHeartbeat;
use App\Support\Sources\PresentSources;
use Illuminate\Console\Scheduling\Schedule;
use Inertia\Testing\AssertableInertia as Assert;

it('is unknown until a tick has been recorded', function () {
    expect(SchedulerHeartbeat::status())->toBe(['state' => 'unknown', 'last_observed_at' => null, 'age_seconds' => null]);
});

it('is recent right after a tick, tolerates one missed tick, and is stale after that', function () {
    SchedulerHeartbeat::record();
    expect(SchedulerHeartbeat::status())->toMatchArray(['state' => 'recent', 'age_seconds' => 0]);

    $this->travel(120)->seconds();
    expect(SchedulerHeartbeat::status())->toMatchArray(['state' => 'recent', 'age_seconds' => 120]);

    $this->travel(61)->seconds();
    expect(SchedulerHeartbeat::status())->toMatchArray(['state' => 'stale', 'age_seconds' => 181]);

    SchedulerHeartbeat::record();
    expect(SchedulerHeartbeat::status()['state'])->toBe('recent');
});

it('is registered on the scheduler every minute, and only the heartbeat call writes it', function () {
    $events = collect(app(Schedule::class)->events());
    $beat = $events->first(fn ($event) => $event->description === 'scheduler-heartbeat');

    expect($beat)->not->toBeNull()->and($beat->expression)->toBe('* * * * *');

    $beat->run(app());
    expect(SchedulerHeartbeat::status()['state'])->toBe('recent');
});

it('reaches the Sources summary, separate from provider state', function () {
    CareerProfile::factory()->create();
    expect((new PresentSources)->summary()['scheduler']['state'])->toBe('unknown');

    SchedulerHeartbeat::record();
    $this->get('/sources')->assertInertia(fn (Assert $page) => $page
        ->where('summary.scheduler.state', 'recent')
        ->where('summary.scheduler.age_seconds', 0));
});
