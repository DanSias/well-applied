<?php

use App\Enums\JobDiscoverySource;
use App\Enums\JobDisposition;
use App\Models\CareerProfile;
use App\Models\JobPosting;
use App\Support\JobDiscovery\ProviderAttribution;
use Inertia\Testing\AssertableInertia as Assert;

function attributedJob(JobDiscoverySource $source, string $url, array $extra = []): JobPosting
{
    return JobPosting::factory()->create([
        'career_profile_id' => CareerProfile::query()->firstOrCreate([], CareerProfile::factory()->make()->toArray())->id,
        'discovery_source' => $source,
        'discovery_source_id' => $source === JobDiscoverySource::Manual ? null : 'id-'.uniqid(),
        'source_url' => $url,
        'disposition' => JobDisposition::Pursued,
    ] + $extra);
}

it('credits Jobicy and calls its stored link the canonical Jobicy listing, not the employer\'s original posting', function () {
    $job = attributedJob(JobDiscoverySource::Jobicy, 'https://jobicy.com/jobs/12345-staff-engineer');

    expect(ProviderAttribution::for($job))->toBe([
        'provider' => 'jobicy', 'name' => 'Jobicy', 'home_url' => 'https://jobicy.com',
        'link_label' => 'View on Jobicy ↗', 'notice' => 'Listing provided by', 'kind' => 'canonical_listing',
    ]);
});

it('credits Himalayas and calls its stored link the Himalayas apply link: the API\'s applicationLink, to be used unchanged', function () {
    $job = attributedJob(JobDiscoverySource::Himalayas, 'https://himalayas.app/companies/acme/jobs/senior-engineer-1');

    expect(ProviderAttribution::for($job))->toMatchArray(['provider' => 'himalayas', 'link_label' => 'Apply on Himalayas ↗', 'kind' => 'apply_link', 'home_url' => 'https://himalayas.app']);
});

it('gives every other source no attribution, so its link keeps the generic wording', function () {
    foreach ([JobDiscoverySource::Adzuna, JobDiscoverySource::Usajobs, JobDiscoverySource::Eightfold, JobDiscoverySource::Manual] as $source) {
        expect(ProviderAttribution::for(attributedJob($source, 'https://example.com/job/'.$source->value)))->toBeNull($source->value);
    }
});

it('only labels and credits: the stored URL reaches every payload byte for byte, with its query string, and is never swapped for another', function () {
    $url = 'https://himalayas.app/companies/acme/jobs/senior-engineer-1?ref=abc&utm_source=feed#x';
    $job = attributedJob(JobDiscoverySource::Himalayas, $url);

    $row = collect($this->get('/opportunities')->viewData('page')['props']['opportunities'])->firstWhere('id', $job->id);
    $show = $this->get(route('opportunities.show', $job))->viewData('page')['props'];
    $drawer = $this->get('/opportunities?inspect='.$job->id)->viewData('page')['props']['inspection_job'];
    $selected = $this->get('/jobs?job='.$job->id)->viewData('page')['props']['selected_job'];

    foreach ([$row, $show['opportunity'], $drawer, $selected] as $payload) {
        expect($payload['source_url'])->toBe($url)
            ->and($payload['attribution']['provider'])->toBe('himalayas')
            ->and($payload['attribution']['link_label'])->toBe('Apply on Himalayas ↗');
    }
});

it('carries the Jobicy credit in every payload a provider link appears in', function () {
    $job = attributedJob(JobDiscoverySource::Jobicy, 'https://jobicy.com/jobs/1-engineer');

    $drawer = $this->get('/opportunities?inspect='.$job->id)->viewData('page')['props']['inspection_job'];
    $selected = $this->get('/jobs?job='.$job->id)->viewData('page')['props']['selected_job'];

    expect($drawer['attribution']['name'])->toBe('Jobicy')->and($selected['attribution']['notice'])->toBe('Listing provided by');
});

it('sends null attribution for an unattributed source', function () {
    $job = attributedJob(JobDiscoverySource::Manual, 'https://example.com/apply');

    $this->get(route('opportunities.show', $job))->assertInertia(fn (Assert $page) => $page->where('opportunity.attribution', null));
});

it('does not change what discovery stores: the provider normalisers still put the provider\'s own link in source_url', function () {
    // The provider tests (HimalayasDiscoveryProviderTest, JobicyDiscoveryProvider...) assert the mapping; this guards the contract this attribution relies on.
    $source = file_get_contents(base_path('app/Support/JobDiscovery/Providers/HimalayasDiscoveryProvider.php'));
    expect($source)->toContain("applicationUrl: is_string(\$job['applicationLink'] ?? null) ? \$job['applicationLink'] : null");
});
