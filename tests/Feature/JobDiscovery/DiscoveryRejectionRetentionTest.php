<?php

use App\Enums\DiscoveryRejectionReason;
use App\Enums\JobDiscoverySource;
use App\Enums\JobDisposition;
use App\Models\CareerProfile;
use App\Models\DiscoveryProviderAttempt;
use App\Models\DiscoveryRejection;
use App\Models\JobPosting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

function retentionRejection(string $id, DateTimeInterface $lastSeen, ?DateTimeInterface $resolvedAt = null): DiscoveryRejection
{
    return DiscoveryRejection::create([
        'source' => JobDiscoverySource::Jobicy, 'source_job_id' => $id, 'title' => 'Warehouse Associate', 'company' => 'Acme',
        'reason_code' => DiscoveryRejectionReason::TitleNotMatching, 'first_seen_at' => $lastSeen, 'last_seen_at' => $lastSeen, 'resolved_at' => $resolvedAt,
    ]);
}

it('prunes rejections last seen more than 30 days ago and keeps the boundary and newer ones', function () {
    Carbon::setTestNow('2026-12-01 12:00:00');
    retentionRejection('stale', now()->subDays(30)->subSecond());
    retentionRejection('stale-resolved', now()->subDays(45), now()->subDays(44));
    retentionRejection('boundary', now()->subDays(30));
    retentionRejection('recent', now()->subDays(29));
    retentionRejection('today', now());

    $this->artisan('discovery:prune-rejections')->expectsOutputToContain('Pruned 2 rejection record(s)')->assertSuccessful();

    expect(DiscoveryRejection::orderBy('source_job_id')->pluck('source_job_id')->all())->toBe(['boundary', 'recent', 'today']);

    // Repeating it is a no-op.
    $this->artisan('discovery:prune-rejections')->expectsOutputToContain('Pruned 0 rejection record(s)')->assertSuccessful();
    expect(DiscoveryRejection::count())->toBe(3);

    Carbon::setTestNow();
});

it('only ever deletes rejection rows', function () {
    CareerProfile::factory()->create();
    $job = JobPosting::factory()->create(['disposition' => JobDisposition::Pursued, 'last_checked_at' => now()->subDays(90)]);
    $attempt = DiscoveryProviderAttempt::factory()->create(['started_at' => now()->subDays(90), 'rejection_counts' => ['title_not_matching' => 4]]);
    retentionRejection('stale', now()->subDays(90));

    $this->artisan('discovery:prune-rejections')->assertSuccessful();

    expect(DiscoveryRejection::count())->toBe(0)
        ->and($job->fresh()->disposition)->toBe(JobDisposition::Pursued)
        ->and(JobPosting::count())->toBe(1)
        ->and($attempt->fresh()->rejection_counts)->toBe(['title_not_matching' => 4]);
});

it('honors a configured retention period and rejects an invalid one', function () {
    retentionRejection('ten-days', now()->subDays(10));
    config(['job_discovery.rejections.retention_days' => 7]);

    $this->artisan('discovery:prune-rejections')->assertSuccessful();
    expect(DiscoveryRejection::count())->toBe(0);

    $this->artisan('discovery:prune-rejections --days=0')->assertFailed();
});

it('is scheduled daily without changing the discovery schedule', function () {
    Artisan::call('list');
    $commands = collect(app(Schedule::class)->events())->pluck('command');

    expect($commands->filter(fn ($c) => str_contains($c, 'discovery:prune-rejections')))->toHaveCount(1)
        ->and($commands->filter(fn ($c) => str_contains($c, 'discovery:run')))->toHaveCount(1);
});
