<?php

namespace App\Support\JobFreshness\Demo;

use App\Enums\JobDiscoverySource;
use App\Enums\JobDisposition;
use App\Models\Application;
use App\Models\CareerProfile;
use App\Models\JobPosting;
use App\Support\JobFreshness\Observation;
use Carbon\CarbonImmutable;

/**
 * Representative freshness states as fictional jobs, for a person to look at in a browser. It only creates rows through the factories (no provider, no job queue); the
 * command that calls it has already passed DemoGuard. Every row is plainly labelled DEMO.
 */
final class DemoSeeder
{
    /** @return array<string, int> label => job id */
    public static function seed(): array
    {
        $now = CarbonImmutable::now();
        $profile = CareerProfile::query()->first() ?? CareerProfile::factory()->create();
        $ids = [];
        $n = 0;

        $make = function (string $label, array $attributes = [], ?int $expiresAt = null, array $observations = [], JobDiscoverySource $source = JobDiscoverySource::Himalayas) use (&$ids, &$n, $profile) {
            $n++;
            $discovery = array_filter(['expiryDate' => $expiresAt, 'companySlug' => 'demo-co'], fn ($v) => $v !== null);
            $job = JobPosting::factory()->create($attributes + [
                'career_profile_id' => $profile->id,
                'company' => 'Demo Co (fictional)', 'title' => "DEMO: {$label}", 'location' => 'Nowhere',
                'description' => "A fictional posting used only to review how \"{$label}\" is shown. It is not a real job.",
                'discovery_source' => $source, 'discovery_source_id' => 'demo-'.$n,
                'source_url' => $source === JobDiscoverySource::Himalayas ? "https://example.test/companies/demo-co/jobs/demo-{$n}" : "https://example.test/jobs/{$n}-demo",
                'disposition' => JobDisposition::New,
                'discovery_metadata' => ['discovery' => $discovery] + ($observations === [] ? [] : ['liveness' => ['schema' => 1, 'observations' => array_map(fn (Observation $o) => $o->toArray(), $observations)]]),
            ]);
            $ids[$label] = $job->id;

            return $job;
        };
        $obs = fn (string $result, string $basis, int $hoursAgo, string $source = 'job_endpoint', string $provider = 'himalayas') => new Observation($provider, $source, $result, $basis, $now->subHours($hoursAgo), null, $result === 'closed' ? 410 : 200);
        $future = $now->addMonth()->timestamp;
        $past = $now->subDays(2)->timestamp;

        $make('Not live-checked', [], $future);
        $make('Confirmed open by the provider', [], $future, [$obs('active', 'http_200', 3)]);
        $make('Possibly closed (reported once)', [], $future, [$obs('closed', 'http_410', 1)]);
        $make('Check inconclusive', [], $future, [$obs('uncertain', 'http_404', 5), $obs('uncertain', 'network_error', 2)]);
        $make('Expired (known expiry date)', [], $past);
        $make('Closed (confirmed twice)', [], $future, [$obs('closed', 'http_410', 40), $obs('closed', 'http_410', 1)]);
        $make('Jobicy, not live-checked', [], null, [], JobDiscoverySource::Jobicy);
        $make('Jobicy, confirmed open', [], null, [$obs('active', 'status_active', 2, 'status_endpoint', 'jobicy')], JobDiscoverySource::Jobicy);
        // Protected: both are expired, and both must stay reachable.
        $make('Expired but PURSUED', ['disposition' => JobDisposition::Pursued, 'pursued_at' => $now], $past);
        $withApplication = $make('Expired but has an APPLICATION', [], $past);
        Application::factory()->create(['job_posting_id' => $withApplication->id]);

        return $ids;
    }
}
