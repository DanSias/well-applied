<?php

use App\Enums\DiscoveryStatus;
use App\Enums\JobDiscoverySource;
use App\Enums\ScheduleOverride;
use App\Jobs\RunDiscoveryProviderJob;
use App\Models\CareerProfile;
use App\Models\DiscoveryProviderAttempt;
use App\Models\DiscoveryProviderSetting;
use App\Models\DiscoveryRun;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
    CareerProfile::factory()->create();
    config(['services.adzuna.app_id' => 'id', 'services.adzuna.app_key' => 'key']);
});

it('queues a run, flashes it and returns to the same page', function () {
    $this->from('/sources?provider=jobicy&page=2&history_page=3')
        ->post('/sources/jobicy/run')
        ->assertRedirect('/sources?provider=jobicy&page=2&history_page=3')
        ->assertInertiaFlash('run_result', ['provider' => 'Jobicy', 'outcome' => 'queued']);

    Queue::assertPushed(RunDiscoveryProviderJob::class, 1);
    expect(DiscoveryProviderAttempt::query()->where('provider', 'jobicy')->first()->status)->toBe(DiscoveryStatus::Queued);
    Http::assertNothingSent();
});

it('reports a duplicate request without queueing another', function () {
    $this->post('/sources/jobicy/run');
    $this->post('/sources/jobicy/run')->assertInertiaFlash('run_result', ['provider' => 'Jobicy', 'outcome' => 'already_active']);

    Queue::assertPushed(RunDiscoveryProviderJob::class, 1);
    expect(DiscoveryRun::query()->count())->toBe(1);
});

it('refuses an unconfigured provider with an error and records nothing', function () {
    config(['services.adzuna.app_id' => '']);

    $this->from('/sources')->post('/sources/adzuna/run')->assertSessionHasErrors('run');
    $this->from('/sources')->post('/sources/usajobs/run')->assertSessionHasErrors('run');

    expect(DiscoveryRun::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('rejects unknown providers and wrong methods', function () {
    $this->post('/sources/nonsense/run')->assertNotFound();
    $this->post('/sources/manual/run')->assertNotFound();
    $this->get('/sources/jobicy/run')->assertStatus(405);
    $this->patch('/sources/jobicy/run')->assertStatus(405);
});

it('runs a paused provider on request without changing its schedule', function () {
    DiscoveryProviderSetting::query()->create(['provider' => 'jobicy', 'schedule_override' => ScheduleOverride::Paused]);

    $this->post('/sources/jobicy/run')->assertInertiaFlash('run_result', ['provider' => 'Jobicy', 'outcome' => 'queued']);

    expect(DiscoveryProviderSetting::query()->first()->schedule_override)->toBe(ScheduleOverride::Paused);
});

it('shows Run Now availability and the queued state on the page', function () {
    $this->get('/sources')->assertInertia(fn (Assert $page) => $page
        ->where('providers.2.run_now.available', true)
        ->where('providers.2.run_now.active', null)
        ->where('providers.3.run_now.available', false)
        ->where('providers.3.run_now.unavailable_reason', fn ($reason) => is_string($reason) && $reason !== ''));

    $this->post('/sources/jobicy/run');

    $this->get('/sources?provider=jobicy')->assertInertia(fn (Assert $page) => $page
        ->where('providers.2.run_now.available', false)
        ->where('providers.2.run_now.active', 'queued')
        ->where('providers.2.last_attempt.status', 'queued')
        ->where('providers.0.run_now.available', true));
});

it('shows a running execution as running, and a failed one as ready to run again', function () {
    $attempt = DiscoveryProviderAttempt::factory()->create(['provider' => JobDiscoverySource::Himalayas, 'status' => DiscoveryStatus::Running, 'finished_at' => null]);

    $this->get('/sources')->assertInertia(fn (Assert $page) => $page
        ->where('providers.0.run_now.active', 'running')
        ->where('providers.0.run_now.available', false));

    $attempt->update(['status' => DiscoveryStatus::Failed, 'finished_at' => now(), 'failure_message' => 'boom']);

    $this->get('/sources')->assertInertia(fn (Assert $page) => $page
        ->where('providers.0.run_now.active', null)
        ->where('providers.0.run_now.available', true)
        ->where('providers.0.last_attempt.status', 'failed'));
});

it('does not expose credentials through the run fields', function () {
    config(['services.adzuna.app_id' => 'ADZ-ID-SECRET', 'services.adzuna.app_key' => 'ADZ-KEY-SECRET']);

    $this->post('/sources/adzuna/run');
    $this->get('/sources?provider=adzuna')->assertDontSee('ADZ-ID-SECRET')->assertDontSee('ADZ-KEY-SECRET');
});
