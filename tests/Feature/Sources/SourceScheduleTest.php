<?php

use App\Enums\DiscoveryStatus;
use App\Enums\JobDiscoverySource;
use App\Enums\ScheduleOverride;
use App\Models\CareerProfile;
use App\Models\DiscoveryProviderAttempt;
use App\Models\DiscoveryProviderSetting;
use App\Models\DiscoveryRun;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Http::preventStrayRequests();
    CareerProfile::factory()->create();
});

function storedOverride(string $provider): ?string
{
    return DiscoveryProviderSetting::query()->where('provider', $provider)->first()?->schedule_override->value;
}

it('pauses an automatic provider, stores the override and flashes the result', function () {
    $this->from('/sources?provider=jobicy')
        ->patch('/sources/jobicy/schedule', ['schedule_override' => 'paused'])
        ->assertRedirect('/sources?provider=jobicy')
        ->assertInertiaFlash('schedule_result', ['provider' => 'Jobicy', 'schedule' => 'paused']);

    expect(storedOverride('jobicy'))->toBe('paused');
});

it('resumes a paused provider back to its default', function () {
    DiscoveryProviderSetting::query()->create(['provider' => 'jobicy', 'schedule_override' => ScheduleOverride::Paused]);

    $this->patch('/sources/jobicy/schedule', ['schedule_override' => 'default'])
        ->assertInertiaFlash('schedule_result', ['provider' => 'Jobicy', 'schedule' => 'automatic']);

    expect(storedOverride('jobicy'))->toBe('default');
});

it('enables scheduling for a configured manual-only provider', function () {
    config(['services.usajobs.api_key' => 'k', 'services.usajobs.user_agent' => 'me@example.test', 'job_discovery.usajobs.scheduled' => false]);

    $this->patch('/sources/usajobs/schedule', ['schedule_override' => 'enabled'])
        ->assertInertiaFlash('schedule_result', ['provider' => 'USAJOBS', 'schedule' => 'automatic']);

    expect(storedOverride('usajobs'))->toBe('enabled');
});

it('refuses to schedule a provider that needs setup, and stores nothing', function (string $provider, string $override) {
    $this->from('/sources')->patch("/sources/{$provider}/schedule", ['schedule_override' => $override])
        ->assertSessionHasErrors('schedule_override');

    expect(DiscoveryProviderSetting::query()->count())->toBe(0);
})->with([
    'adzuna, no credentials' => ['adzuna', 'enabled'],
    'usajobs, no credentials' => ['usajobs', 'enabled'],
    'eightfold, switched off' => ['eightfold', 'paused'],
]);

it('rejects unknown providers and invalid overrides', function () {
    $this->patch('/sources/nonsense/schedule', ['schedule_override' => 'paused'])->assertNotFound();
    $this->patch('/sources/manual/schedule', ['schedule_override' => 'paused'])->assertNotFound();

    foreach (['', 'always', 'PAUSED', null] as $bad) {
        $this->from('/sources')->patch('/sources/jobicy/schedule', ['schedule_override' => $bad])->assertSessionHasErrors('schedule_override');
    }
    $this->from('/sources')->patch('/sources/jobicy/schedule')->assertSessionHasErrors('schedule_override');

    expect(DiscoveryProviderSetting::query()->count())->toBe(0);
});

it('is not reachable with GET or POST', function () {
    $this->get('/sources/jobicy/schedule')->assertStatus(405);
    $this->post('/sources/jobicy/schedule', ['schedule_override' => 'paused'])->assertStatus(405);
});

it('shows the effective schedule and the control on the page, and the paused state after pausing', function () {
    $this->get('/sources?provider=jobicy&page=2&history_page=2')->assertInertia(fn (Assert $page) => $page
        ->where('providers.2.effective_schedule', 'automatic')
        ->where('providers.2.schedule_control', ['label' => 'Pause scheduling', 'override' => 'paused'])
        ->where('providers.1.effective_schedule', 'unavailable')
        ->where('providers.1.schedule_control', null));

    $this->patch('/sources/jobicy/schedule', ['schedule_override' => 'paused']);

    $this->get('/sources?provider=jobicy')->assertInertia(fn (Assert $page) => $page
        ->where('providers.2.effective_schedule', 'paused')
        ->where('providers.2.schedule_override', 'paused')
        ->where('providers.2.schedule_control', ['label' => 'Resume scheduling', 'override' => 'default'])
        ->where('summary.paused', 1)
        ->where('filters.provider', 'jobicy'));
});

it('keeps the drawer, its history page and the activity page when redirecting back', function () {
    $url = '/sources?provider=jobicy&history_page=2&page=3';

    $this->from($url)->patch('/sources/jobicy/schedule', ['schedule_override' => 'paused'])->assertRedirect($url);
});

it('does not touch any discovery history when scheduling changes', function () {
    $run = DiscoveryRun::factory()->create(['status' => DiscoveryStatus::Failed]);
    $attempt = DiscoveryProviderAttempt::factory()->create([
        'discovery_run_id' => $run->id, 'provider' => JobDiscoverySource::Jobicy, 'status' => DiscoveryStatus::Failed, 'failure_message' => 'boom',
    ]);
    $before = [$run->fresh()->toArray(), $attempt->fresh()->toArray()];

    $this->patch('/sources/jobicy/schedule', ['schedule_override' => 'paused']);
    $this->patch('/sources/jobicy/schedule', ['schedule_override' => 'default']);

    expect([$run->fresh()->toArray(), $attempt->fresh()->toArray()])->toBe($before)
        ->and(DiscoveryRun::query()->count())->toBe(1)
        ->and(DiscoveryProviderAttempt::query()->count())->toBe(1);
});

it('never exposes credentials through the scheduling fields', function () {
    config(['services.adzuna.app_id' => 'ADZ-ID-SECRET', 'services.adzuna.app_key' => 'ADZ-KEY-SECRET']);

    $this->get('/sources?provider=adzuna')->assertDontSee('ADZ-ID-SECRET')->assertDontSee('ADZ-KEY-SECRET');
    $this->patch('/sources/adzuna/schedule', ['schedule_override' => 'paused'])->assertSessionHasNoErrors();
    $this->get('/sources?provider=adzuna')->assertDontSee('ADZ-ID-SECRET')->assertDontSee('ADZ-KEY-SECRET');
});

it('shows Overview the paused state, without calling the paused provider overdue', function () {
    config(['services.adzuna.app_id' => 'id', 'services.adzuna.app_key' => 'key']);
    DiscoveryProviderAttempt::factory()->create([
        'discovery_run_id' => DiscoveryRun::factory()->create()->id, 'provider' => JobDiscoverySource::Jobicy,
        'status' => DiscoveryStatus::Succeeded, 'started_at' => now()->subDays(4), 'finished_at' => now()->subDays(4),
    ]);
    $this->patch('/sources/jobicy/schedule', ['schedule_override' => 'paused']);

    $this->get('/')->assertInertia(fn (Assert $page) => $page
        ->where('discovery.providers', fn ($providers) => collect($providers)->firstWhere('provider', 'jobicy')['schedule'] === 'paused'
            && collect($providers)->firstWhere('provider', 'jobicy')['stale'] === false)
    );
});

it('has no automatic providers, so nothing for the scheduler warning to be about, when all are paused or manual', function () {
    foreach (['himalayas', 'adzuna', 'jobicy'] as $provider) {
        $this->patch("/sources/{$provider}/schedule", ['schedule_override' => 'paused']);
    }

    $this->get('/sources')->assertInertia(fn (Assert $page) => $page
        ->where('summary.automatic', 0)
        ->where('summary.paused', 3)
        ->where('summary.scheduler.state', 'unknown'));
});
