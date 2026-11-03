<?php

use App\Enums\DescriptionCompleteness;
use App\Enums\JobRemoteStatus;
use App\Models\Application;
use App\Models\JobPosting;
use App\Models\MarketSample;
use App\Models\MarketSamplePosting;
use App\Support\JobDiscovery\Providers\DiscoveryProviderException;
use App\Support\ResumeProfile\MarketSampleCollector;
use Carbon\CarbonImmutable;
use Tests\Support\ResumeProfileFixtures as F;

function collectSample(array $f, bool $pilot = true, bool $fetch = true, bool $force = false): MarketSample
{
    return app(MarketSampleCollector::class)->collect($f['profile'], $pilot, $fetch, $force);
}

function decisionOf(MarketSample $sample, string $company, ?string $title = null): MarketSamplePosting
{
    return $sample->postings()->where('company', $company)->when($title, fn ($q) => $q->where('title', $title))->firstOrFail();
}

it('samples stored postings and fresh provider postings without creating a job posting or application', function () {
    $f = F::career();
    F::stored($f['career'], 'Stored Co', 'Senior Full-Stack Engineer');
    F::providers([
        'himalayas' => [F::candidate('himalayas', 'h1', 'Fresh One', 'Full Stack Engineer')],
        'jobicy' => [F::candidate('jobicy', 'j1', 'Fresh Two', 'Senior Product Engineer')],
    ]);
    $postings = JobPosting::count();

    $sample = collectSample($f);

    expect($sample->includedPostings()->count())->toBe(3)
        ->and(JobPosting::count())->toBe($postings)
        ->and(Application::count())->toBe(0)
        ->and($sample->includedPostings()->whereNotNull('job_posting_id')->count())->toBe(1)
        ->and($f['profile']->fresh()->market_sample_id)->toBe($sample->id);
});

it('stores provenance and the reason for every decision', function () {
    $f = F::career();
    F::providers(['himalayas' => [
        F::candidate('himalayas', 'ok', 'Good Co', 'Senior Full-Stack Engineer'),
        F::candidate('himalayas', 'jr', 'Junior Co', 'Junior Full Stack Developer'),
    ], 'jobicy' => []]);

    $sample = collectSample($f);
    $good = decisionOf($sample, 'Good Co');
    $junior = decisionOf($sample, 'Junior Co');

    expect($good->included)->toBeTrue()
        ->and($good->decision)->toBe('included')
        ->and($good->provider)->toBe('himalayas')
        ->and($good->source_url)->toBe('https://jobs.example/himalayas/ok')
        ->and($good->company)->toBe('Good Co')
        ->and($good->title)->toBe('Senior Full-Stack Engineer')
        ->and($good->retrieved_at)->not->toBeNull()
        ->and($good->content_hash)->toHaveLength(64)
        ->and($junior->included)->toBeFalse()
        ->and($junior->decision)->toBe('outside_search')
        ->and($junior->decision_detail)->toContain('Excluded');
});

it('excludes incomplete, short, stale, unrelated and generic non-full-stack postings, each with its own reason', function () {
    $f = F::career();
    F::providers(['himalayas' => [
        F::candidate('himalayas', 'p', 'Preview Co', 'Full Stack Engineer', null, ['completeness' => DescriptionCompleteness::Preview]),
        F::candidate('himalayas', 's', 'Short Co', 'Full Stack Engineer', '<p>Build things. React.</p>'),
        F::candidate('himalayas', 'o', 'Old Co', 'Full Stack Engineer', null, ['posted' => CarbonImmutable::now()->subDays(200)]),
        F::candidate('himalayas', 'm', 'Onsite Co', 'Full Stack Engineer', null, ['remote' => JobRemoteStatus::Onsite, 'location' => 'Berlin, Germany']),
        F::candidate('himalayas', 't', 'Chef Co', 'Pastry Chef'),
        F::candidate('himalayas', 'b', 'Backend Co', 'Software Engineer', F::description(['Python, Django, PostgreSQL experience.', 'Design internal batch pipelines on the server.'], unique: 'backend-only')),
        F::candidate('himalayas', 'g', 'Both Co', 'Software Engineer', F::description(['React and TypeScript on the front end.', 'Build REST APIs in Node on the back end.'], unique: 'both-halves')),
    ], 'jobicy' => []]);

    $sample = collectSample($f);

    expect(decisionOf($sample, 'Preview Co')->decision)->toBe('incomplete_description')
        ->and(decisionOf($sample, 'Short Co')->decision)->toBe('description_too_short')
        ->and(decisionOf($sample, 'Old Co')->decision)->toBe('stale')
        ->and(decisionOf($sample, 'Onsite Co')->decision)->toBe('outside_search')
        ->and(decisionOf($sample, 'Chef Co')->decision)->toBe('outside_search')
        ->and(decisionOf($sample, 'Chef Co')->decision_detail)->toContain('Title is not in the role family')
        ->and(decisionOf($sample, 'Backend Co')->decision)->toBe('not_full_stack')
        ->and(decisionOf($sample, 'Both Co')->included)->toBeTrue();
});

it('removes identical, same-company-and-title and nearly identical postings, pointing at the one kept', function () {
    $f = F::career();
    $text = F::description(['React and Node.js experience.'], unique: 'shared');
    $reposted = $text.'<p>Apply by Friday.</p>';
    F::providers(['himalayas' => [
        F::candidate('himalayas', 'a1', 'Dup Co', 'Full Stack Engineer', $text),
        F::candidate('himalayas', 'a2', 'Other Co', 'Staff Full Stack Engineer', $text),
        F::candidate('himalayas', 'a3', 'Dup Co', 'Full Stack Engineer (Remote)', F::description(['Python and React.'], unique: 'different-text')),
        F::candidate('himalayas', 'a4', 'Third Co', 'Full Stack Developer', $reposted),
    ], 'jobicy' => []]);

    $sample = collectSample($f);
    $kept = decisionOf($sample, 'Dup Co', 'Full Stack Engineer');

    expect($kept->included)->toBeTrue()
        ->and(decisionOf($sample, 'Other Co')->decision)->toBe('duplicate_exact')
        ->and(decisionOf($sample, 'Other Co')->duplicate_of_id)->toBe($kept->id)
        ->and(decisionOf($sample, 'Dup Co', 'Full Stack Engineer (Remote)')->decision)->toBe('duplicate_near')
        ->and(decisionOf($sample, 'Dup Co', 'Full Stack Engineer (Remote)')->decision_detail)->toBe('Same company and title.')
        ->and(decisionOf($sample, 'Third Co')->decision)->toBe('duplicate_near')
        ->and($sample->includedPostings()->count())->toBe(1);
});

it('does not sample a provider posting that is already stored as the same posting', function () {
    $f = F::career();
    $stored = F::stored($f['career'], 'Same Co', 'Full Stack Engineer', null, ['discovery_source_id' => 'h-77']);
    F::providers(['himalayas' => [F::candidate('himalayas', 'h-77', 'Same Co', 'Full Stack Engineer Senior', F::description(['Totally different wording for React.'], unique: 'x'))], 'jobicy' => []]);

    $sample = collectSample($f);

    expect($sample->includedPostings()->count())->toBe(1)
        ->and($sample->includedPostings()->first()->job_posting_id)->toBe($stored->id)
        ->and(decisionOf($sample, 'Same Co', 'Full Stack Engineer Senior')->decision)->toBe('duplicate_exact');
});

it('keeps the sample diverse: per company, per provider and per primary stack, newest first, up to the target', function () {
    $f = F::career();
    config(['resume_profiles.profiles.full-stack-product.sample.pilot_target' => 6]);

    $himalayas = array_map(fn (int $i) => F::candidate('himalayas', "h{$i}", $i <= 3 ? 'Big Co' : "Co {$i}", "Full Stack Engineer {$i}", F::description(['React and Node.js.'], unique: "h{$i}"), ['posted' => CarbonImmutable::now()->subDays($i)]), range(1, 8));
    $jobicy = array_map(fn (int $i) => F::candidate('jobicy', "j{$i}", "Jobi {$i}", "Product Engineer {$i}", F::description(['React and PHP Laravel.', 'Laravel Laravel PHP services.'], unique: "j{$i}"), ['posted' => CarbonImmutable::now()->subDays(20 + $i)]), range(1, 8));
    F::providers(['himalayas' => $himalayas, 'jobicy' => $jobicy]);

    $sample = collectSample($f);
    $included = $sample->includedPostings()->get();

    expect($included)->toHaveCount(6)
        ->and($included->where('company', 'Big Co')->count())->toBe(2)
        ->and($included->where('provider', 'himalayas')->count())->toBeLessThanOrEqual(4)
        ->and($included->where('primary_stack', 'php_laravel')->count())->toBeLessThanOrEqual(3)
        ->and($sample->postings()->where('decision', 'company_cap')->exists())->toBeTrue()
        ->and($sample->decision_counts)->toHaveKey('included');
    // Newest first: the freshest Himalayas postings were preferred over older ones.
    expect($included->pluck('source_job_id')->all())->toContain('h1')->not->toContain('h8');
});

it('records a provider failure, keeps other providers and stored postings, and never stores an exception message', function () {
    $f = F::career();
    F::stored($f['career'], 'Stored Co', 'Full Stack Engineer');
    F::providers([
        'himalayas' => new DiscoveryProviderException('Himalayas request failed with HTTP status 429.'),
        'jobicy' => new RuntimeException('secret-token-abc123 leaked in message'),
    ]);

    $sample = collectSample($f);
    $log = collect($sample->provider_log)->keyBy('provider');

    expect($sample->status->value)->toBe('ready')
        ->and($sample->includedPostings()->count())->toBe(1)
        ->and($log['himalayas']['status'])->toBe('provider_error')
        ->and($log['himalayas']['error'])->toContain('429')
        ->and($log['jobicy']['status'])->toBe('unexpected_error')
        ->and(json_encode($sample->provider_log))->not->toContain('secret-token');
});

it('asks each provider at most once per refetch window and reuses what it returned', function () {
    $f = F::career();
    $providers = F::providers(['himalayas' => [F::candidate('himalayas', 'h1', 'Fresh One', 'Full Stack Engineer')], 'jobicy' => [F::candidate('jobicy', 'j1', 'Fresh Two', 'Full Stack Engineer')]]);

    collectSample($f);
    $second = collectSample($f);

    expect($providers->providers['himalayas']->calls)->toBe(1)
        ->and($providers->providers['jobicy']->calls)->toBe(1)
        ->and($second->includedPostings()->count())->toBe(2)
        ->and(collect($second->provider_log)->pluck('status')->unique()->all())->toBe(['reused_recent_fetch']);

    collectSample($f, force: true);
    expect($providers->providers['himalayas']->calls)->toBe(2);

    $offline = collectSample($f, fetch: false);
    expect($providers->providers['himalayas']->calls)->toBe(2)
        ->and($offline->includedPostings()->count())->toBe(2);
});

it('asks again once the refetch window has passed', function () {
    $f = F::career();
    $providers = F::providers(['himalayas' => [F::candidate('himalayas', 'h1', 'Fresh One', 'Full Stack Engineer')], 'jobicy' => []]);

    collectSample($f);
    MarketSample::query()->update(['provider_log' => array_map(fn ($e) => [...$e, 'at' => now()->subHours(13)->toIso8601String()], MarketSample::first()->provider_log)]);
    collectSample($f);

    expect($providers->providers['himalayas']->calls)->toBe(2);
});

it('never samples a posting of another career profile', function () {
    $f = F::career();
    $other = F::career();
    F::stored($other['career'], 'Not Mine', 'Full Stack Engineer');
    F::providers(['himalayas' => [], 'jobicy' => []]);

    expect(collectSample($f)->includedPostings()->count())->toBe(0);
});
