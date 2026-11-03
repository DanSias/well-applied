<?php

namespace Tests\Support;

use App\Enums\CareerFactType;
use App\Enums\DescriptionCompleteness;
use App\Enums\JobDiscoverySource;
use App\Enums\JobRemoteStatus;
use App\Enums\Verification;
use App\Enums\Visibility;
use App\Models\CareerFact;
use App\Models\CareerProfile;
use App\Models\Employer;
use App\Models\JobPosting;
use App\Models\MarketSample;
use App\Models\ResumeProfile;
use App\Models\Role;
use App\Support\JobDiscovery\DiscoveredJobCandidate;
use App\Support\ResumeProfile\AggregateMarketRequirements;
use App\Support\ResumeProfile\PostingText;
use App\Support\ResumeProfile\ProfileDefinitions;
use App\Support\ResumeProfile\SamplingProviders;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Throwable;

final class ResumeProfileFixtures
{
    private static int $sequence = 0;

    private const WORDS = ['alpha', 'basalt', 'cinder', 'dune', 'ember', 'fjord', 'glacier', 'harbor', 'island', 'juniper', 'kelp', 'lagoon', 'meadow', 'nectar', 'orchard', 'prairie', 'quartz', 'reef', 'summit', 'tundra', 'umber', 'valley', 'willow', 'yarrow', 'zephyr'];

    /**
     * @return array{career: CareerProfile, profile: ResumeProfile, employer: Employer, role: Role, react: CareerFact, webhook: CareerFact, private: CareerFact}
     */
    public static function career(): array
    {
        $career = CareerProfile::factory()->create();
        $employer = Employer::factory()->create(['career_profile_id' => $career->id, 'name' => 'RocketGate']);
        $role = Role::factory()->create(['employer_id' => $employer->id, 'title' => 'Developer Support Engineer', 'start_year' => 2025, 'start_month' => 5]);
        $make = fn (string $key, string $statement, Visibility $v, Verification $verification = Verification::StronglySupported) => CareerFact::factory()->create([
            'career_profile_id' => $career->id, 'key' => $key.'-'.$career->id, 'statement' => $statement, 'visibility' => $v, 'verification' => $verification,
            'fact_type' => CareerFactType::Bullet, 'attributable_type' => 'role', 'attributable_id' => $role->id,
        ]);

        return [
            'career' => $career, 'profile' => ProfileDefinitions::ensure($career, 'full-stack-product'), 'employer' => $employer, 'role' => $role,
            'react' => $make('react-ui', 'Built React and TypeScript interfaces for merchant dashboards.', Visibility::Public),
            'webhook' => $make('webhook-support', 'Supported merchants integrating payment webhooks.', Visibility::Restricted),
            'private' => $make('private-zebra', 'Handled a sealed Kubernetes outage for ZEBRA-CLIENT-X.', Visibility::Private),
        ];
    }

    /**
     * A realistic HTML posting body with sections. `$unique` makes the filler
     * text distinct so different postings are not near-duplicates.
     *
     * @param  array<int, string>  $required
     * @param  array<int, string>  $preferred
     * @param  array<int, string>  $duties
     */
    public static function description(array $required, array $preferred = [], array $duties = [], string $unique = 'a', string $benefits = 'Fully remote, great equity, wellness stipend.'): string
    {
        mt_srand(crc32($unique));
        $filler = implode(' ', array_map(fn () => self::WORDS[mt_rand(0, count(self::WORDS) - 1)].mt_rand(10, 99), range(1, 90)));
        $li = fn (array $items) => '<ul>'.implode('', array_map(fn (string $i) => "<li>{$i}</li>", $items)).'</ul>';

        return "<p>About us</p><p>We are a company number {$unique}. {$filler}</p>"
            .'<h3>What you will do</h3>'.$li($duties ?: ['Build and ship product features across the stack.'])
            .'<h3>Requirements</h3>'.$li($required)
            .($preferred === [] ? '' : '<h3>Nice to have</h3>'.$li($preferred))
            ."<h3>Benefits</h3><ul><li>{$benefits}</li></ul>";
    }

    public static function candidate(string $source, string $id, string $company, string $title, ?string $description = null, array $overrides = []): DiscoveredJobCandidate
    {
        self::$sequence++;
        $description ??= self::description(['3+ years of React and Node.js experience.', 'Strong REST API design.'], unique: "{$company}-{$id}");

        return new DiscoveredJobCandidate(
            source: JobDiscoverySource::from($source), sourceJobId: $id, company: $company, title: $title, description: $description,
            descriptionCompleteness: $overrides['completeness'] ?? DescriptionCompleteness::Complete, location: $overrides['location'] ?? 'Remote, United States',
            remoteStatus: $overrides['remote'] ?? JobRemoteStatus::Remote, employmentType: null, compensationMin: null, compensationMax: null,
            compensationCurrency: null, compensationInterval: null, applicationUrl: "https://jobs.example/{$source}/{$id}",
            postedAt: $overrides['posted'] ?? CarbonImmutable::now()->subDays(5 + self::$sequence % 20), sourceUpdatedAt: null, discoveredAt: CarbonImmutable::now(),
        );
    }

    /**
     * @param  array<string, array<int, DiscoveredJobCandidate>|Throwable>  $byProvider
     */
    public static function providers(array $byProvider): FakeSamplingProviders
    {
        $fakes = [];

        foreach ($byProvider as $name => $value) {
            $fakes[$name] = $value instanceof Throwable ? new FakeDiscoveryProvider([], $value) : new FakeDiscoveryProvider($value);
        }

        $providers = new FakeSamplingProviders($fakes);
        app()->instance(SamplingProviders::class, $providers);

        return $providers;
    }

    /** A stored, complete posting (an existing opportunity). */
    public static function stored(CareerProfile $career, string $company, string $title, ?string $description = null, array $attrs = []): JobPosting
    {
        self::$sequence++;

        return JobPosting::factory()->discovered($attrs['source'] ?? JobDiscoverySource::Himalayas)->create([
            'career_profile_id' => $career->id, 'company' => $company, 'title' => $title,
            'description' => $description ?? self::description(['React and Node.js experience.', 'Build REST APIs.'], unique: "stored-{$company}"),
            'description_completeness' => DescriptionCompleteness::Complete, 'remote_status' => JobRemoteStatus::Remote,
            'location' => 'Remote, United States', 'posted_at' => now()->subDays(7), ...Arr::except($attrs, ['source']),
        ]);
    }

    /**
     * A ready, aggregated sample built straight from specs (no collection).
     * Each spec: required[], preferred[], duties[], title?, company?.
     *
     * @param  array<int, array<string, mixed>>  $specs
     */
    public static function sampleWith(ResumeProfile $profile, array $specs): MarketSample
    {
        $sample = MarketSample::create([
            'resume_profile_id' => $profile->id, 'status' => 'ready', 'pilot' => true, 'target_size' => count($specs),
            'extraction_version' => (string) config('resume_profiles.extraction_version'), 'collected_at' => now(), 'provider_log' => [], 'decision_counts' => ['included' => count($specs)],
        ]);

        foreach ($specs as $i => $spec) {
            $company = $spec['company'] ?? "Co {$i}";
            $text = PostingText::clean(self::description($spec['required'] ?? [], $spec['preferred'] ?? [], $spec['duties'] ?? [], "{$company}-{$i}"));
            $sample->postings()->create([
                'provider' => $spec['provider'] ?? 'himalayas', 'source_job_id' => "s{$i}", 'source_url' => "https://jobs.example/s{$i}", 'company' => $company,
                'title' => $spec['title'] ?? 'Senior Full Stack Engineer', 'description_completeness' => 'complete', 'description' => $text,
                'content_hash' => PostingText::hash($text), 'posted_at' => now()->subDays($i), 'retrieved_at' => now(), 'included' => true, 'decision' => 'included',
            ]);
        }

        app(AggregateMarketRequirements::class)->run($sample);
        $profile->update(['market_sample_id' => $sample->id]);

        return $sample->refresh();
    }
}
