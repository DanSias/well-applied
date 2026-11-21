<?php

use App\Enums\DescriptionCompleteness;
use App\Enums\JobCanonicalSource;
use App\Enums\JobDiscoverySource;
use App\Models\Application;
use App\Models\CareerProfile;
use App\Models\JobPosting;
use App\Support\ApplicationInspection\ResolveInspectionStartUrl;
use App\Support\JobDiscovery\DestinationMetadata;
use App\Support\JobDiscovery\DiscoveredJobCandidate;
use App\Support\JobDiscovery\IngestDiscoveredCandidate;
use App\Support\JobDiscovery\ProviderAttribution;
use Carbon\CarbonImmutable;

function destCandidate(JobDiscoverySource $source, ?string $url, array $metadata = [], string $id = 'x-1'): DiscoveredJobCandidate
{
    return new DiscoveredJobCandidate(
        source: $source, sourceJobId: $id, company: 'Acme', title: 'Engineer', description: 'Build.', descriptionCompleteness: DescriptionCompleteness::Complete,
        location: 'US', remoteStatus: null, employmentType: null, compensationMin: null, compensationMax: null, compensationCurrency: null, compensationInterval: null,
        applicationUrl: $url, postedAt: null, sourceUpdatedAt: null, discoveredAt: CarbonImmutable::now(), sourceMetadata: $metadata,
    );
}

function destJob(JobDiscoverySource $source, ?string $url, array $discovery = [], array $extra = []): JobPosting
{
    return JobPosting::factory()->create([
        'career_profile_id' => CareerProfile::query()->firstOrCreate([], CareerProfile::factory()->make()->toArray())->id,
        'discovery_source' => $source, 'discovery_source_id' => 'id-'.uniqid(), 'source_url' => $url,
        'discovery_metadata' => $discovery === [] ? null : ['discovery' => $discovery],
    ] + $extra);
}

function usaDest(string $url, array $over = []): array
{
    return array_replace_recursive(DestinationMetadata::destination(JobDiscoverySource::Usajobs, $url, 'ApplyURI'), $over);
}

// ---- the entry and its validation ----------------------------------------------------------------------------------------------------

it('builds a candidate destination that is explicit about provenance, evidence, verification and usage', function () {
    $d = DestinationMetadata::destination(JobDiscoverySource::Usajobs, 'https://apply.example.gov/a/1', 'ApplyURI', 'public', ['kind' => 'api_field', 'index' => 0]);

    expect($d)->toMatchArray([
        'role' => 'candidate_destination', 'url' => 'https://apply.example.gov/a/1', 'source' => 'usajobs',
        'provenance' => ['origin' => 'provider_api', 'field' => 'ApplyURI', 'tier' => 'public'],
        'evidence' => ['kind' => 'api_field', 'index' => 0],
        'verification' => ['status' => 'unverified'],
    ])->and($d['usage']['may_replace_apply_link'])->toBeFalse()->and($d['retrieved_at'])->toBeString();
});

it('keeps only valid, honest, distinct candidates, and drops everything else on ingestion', function () {
    $listing = 'https://www.usajobs.gov/job/1';
    $entries = [
        usaDest('https://apply.usajobs.gov/applicant/1'),                                   // kept
        usaDest('https://apply.usajobs.gov/applicant/1?gclid=zzz'),                          // the same place: once
        usaDest($listing),                                                                  // the listing itself is not a candidate
        usaDest('https://apply.example.gov/ok', ['usage' => ['may_replace_apply_link' => true, 'restrictions' => ['anything']]]),   // kept; its own usage claims are ignored
        usaDest('http://10.0.0.1/apply'),                                                   // fails the URL policy
        usaDest('https://user:pw@apply.example.gov/a'),                                      // credentials
        usaDest('https://apply.example.gov/a/2', ['verification' => ['status' => 'verified']]),   // a provider cannot confirm a destination
        usaDest('https://apply.example.gov/a/3', ['verification' => ['status' => 'user_confirmed']]),
        usaDest('https://apply.example.gov/a/4', ['role' => 'application_url']),             // never the Application URL
        usaDest('https://apply.example.gov/a/5', ['source' => 'jobicy']),                    // another provider's candidate
        usaDest('https://apply.example.gov/a/6', ['provenance' => ['origin' => 'browser']]), // not from an API
        usaDest('https://apply.example.gov/a/7', ['provenance' => ['tier' => 'free']]),
        'not an array',
    ];

    $discovery = DestinationMetadata::sanitize(destCandidate(JobDiscoverySource::Usajobs, $listing, ['destinations' => $entries, 'closingDate' => 'x']));

    expect(array_column($discovery['destinations'], 'url'))->toBe(['https://apply.usajobs.gov/applicant/1', 'https://apply.example.gov/ok'])
        ->and($discovery['closingDate'])->toBe('x', 'the provider\'s other metadata is untouched');
    foreach ($discovery['destinations'] as $d) {
        expect($d['verification'])->toBe(['status' => 'unverified'])
            ->and($d['usage']['may_replace_apply_link'])->toBeFalse()
            ->and($d['usage']['restrictions'])->toBe([], 'restrictions come from the registry, never from the entry');
    }
});

it('scans a bounded number of entries, caps candidates, and never accepts them from a source that may not supply them (Himalayas, Adzuna, manual)', function () {
    $many = array_map(fn ($i) => usaDest("https://apply.example.gov/a/$i"), range(1, 9));
    expect(DestinationMetadata::sanitize(destCandidate(JobDiscoverySource::Usajobs, 'https://www.usajobs.gov/j', ['destinations' => $many]))['destinations'])->toHaveCount(5);

    foreach ([JobDiscoverySource::Himalayas, JobDiscoverySource::Adzuna, JobDiscoverySource::Manual] as $source) {
        $entry = array_replace_recursive(DestinationMetadata::destination($source, 'https://employer.example.com/apply', 'applicationLink'), []);
        expect(DestinationMetadata::sanitize(destCandidate($source, 'https://listing.example.com/1', ['destinations' => [$entry]])))->not->toHaveKey('destinations');
    }
});

// ---- attribution ----------------------------------------------------------------------------------------------------------------------

it('builds attribution from the registry and ignores whatever a provider response claims', function () {
    $a = DestinationMetadata::sanitize(destCandidate(JobDiscoverySource::Himalayas, 'https://himalayas.app/companies/acme/jobs/x?ref=a&utm_source=f', ['attribution' => ['required' => false, 'apply_link' => ['mandated' => false]]]))['attribution'];

    expect($a)->toMatchArray(['provider' => 'himalayas', 'name' => 'Himalayas', 'home_url' => 'https://himalayas.app', 'required' => true])
        ->and($a['apply_link'])->toBe(['url' => 'https://himalayas.app/companies/acme/jobs/x?ref=a&utm_source=f', 'mandated' => true, 'policy' => 'use_application_link_unchanged'])
        ->and($a['canonical_url'])->toBe('https://himalayas.app/companies/acme/jobs/x?ref=a&utm_source=f', 'the provider\'s link is recorded byte for byte')
        ->and($a['restrictions'])->toContain('apply_action_must_use_application_link', 'credit_required');
});

it('records Jobicy\'s canonical listing and credit, a null link when the provider link is unusable, and nothing for unattributed sources', function () {
    $j = DestinationMetadata::sanitize(destCandidate(JobDiscoverySource::Jobicy, 'https://jobicy.com/jobs/1-x'))['attribution'];
    expect($j['apply_link']['mandated'])->toBeFalse()->and($j['restrictions'])->toContain('listing_url_must_be_preserved')->and($j['canonical_url'])->toBe('https://jobicy.com/jobs/1-x');

    expect(DestinationMetadata::sanitize(destCandidate(JobDiscoverySource::Himalayas, 'http://localhost/x'))['attribution']['canonical_url'])->toBeNull();
    foreach ([JobDiscoverySource::Adzuna, JobDiscoverySource::Usajobs, JobDiscoverySource::Eightfold, JobDiscoverySource::Manual] as $s) {
        expect(DestinationMetadata::sanitize(destCandidate($s, 'https://x.example/1')))->not->toHaveKey('attribution');
    }
});

// ---- ingestion persists the contract and never touches source_url or the Application URL -------------------------------------------------------------

it('persists attribution and candidates, leaves source_url byte for byte, and creates no Application', function () {
    $profile = CareerProfile::factory()->create();
    $himalayasUrl = 'https://himalayas.app/companies/acme/jobs/senior-1?ref=abc&utm_source=feed';

    $h = (new IngestDiscoveredCandidate)->ingest(destCandidate(JobDiscoverySource::Himalayas, $himalayasUrl, ['companySlug' => 'acme'], 'h-1'), null, $profile)->jobPosting->fresh();
    $u = (new IngestDiscoveredCandidate)->ingest(destCandidate(JobDiscoverySource::Usajobs, 'https://www.usajobs.gov/job/9', ['destinations' => [usaDest('https://apply.usajobs.gov/a/9')]], 'u-9'), null, $profile)->jobPosting->fresh();

    expect($h->source_url)->toBe($himalayasUrl)
        ->and($h->discovery_metadata['discovery']['companySlug'])->toBe('acme')
        ->and($h->discovery_metadata['discovery']['attribution']['apply_link']['url'])->toBe($himalayasUrl)
        ->and($h->discovery_metadata['discovery'])->not->toHaveKey('destinations')
        ->and($u->source_url)->toBe('https://www.usajobs.gov/job/9')
        ->and($u->discovery_metadata['discovery']['destinations'][0]['verification'])->toBe(['status' => 'unverified'])
        ->and(Application::count())->toBe(0);
});

it('refreshes the contract on rediscovery, so a corrected response replaces a stale one', function () {
    $profile = CareerProfile::factory()->create();
    $ingest = fn (array $dest) => (new IngestDiscoveredCandidate)->ingest(destCandidate(JobDiscoverySource::Usajobs, 'https://www.usajobs.gov/job/3', ['destinations' => $dest], 'u-3'), null, $profile)->jobPosting->fresh();

    expect($ingest([usaDest('https://apply.usajobs.gov/a/old')])->discovery_metadata['discovery']['destinations'][0]['url'])->toBe('https://apply.usajobs.gov/a/old');
    $job = $ingest([usaDest('https://apply.usajobs.gov/a/new')]);
    expect(array_column($job->discovery_metadata['discovery']['destinations'], 'url'))->toBe(['https://apply.usajobs.gov/a/new']);
    expect($ingest([])->discovery_metadata['discovery'])->not->toHaveKey('destinations');
});

// ---- reading is tolerant and re-validates -----------------------------------------------------------------------------------------------------

it('re-validates what is stored: malformed, over-claiming and foreign entries never reach a consumer, and the attribution is rebuilt, not trusted', function () {
    $job = destJob(JobDiscoverySource::Usajobs, 'https://www.usajobs.gov/job/1', [
        'destinations' => [
            usaDest('https://apply.usajobs.gov/ok'),
            usaDest('https://apply.usajobs.gov/claims', ['verification' => ['status' => 'verified']]),
            usaDest('https://apply.usajobs.gov/foreign', ['source' => 'himalayas']),
            ['url' => 'https://apply.usajobs.gov/bare'],
            'garbage', 7, null,
        ],
    ]);

    expect(array_column(DestinationMetadata::read($job)['destinations'], 'url'))->toBe(['https://apply.usajobs.gov/ok']);

    $weird = destJob(JobDiscoverySource::Himalayas, 'https://himalayas.app/c/a/jobs/b', ['attribution' => ['apply_link' => ['mandated' => false], 'canonical_url' => 'https://evil.example/x'], 'destinations' => 'nope']);
    expect(DestinationMetadata::read($weird)['attribution']['apply_link']['mandated'])->toBeTrue()
        ->and(DestinationMetadata::read($weird)['destinations'])->toBe([]);

    $none = destJob(JobDiscoverySource::Manual, null);
    expect(DestinationMetadata::read($none))->toBe(['destinations' => [], 'attribution' => null]);
});

it('presents legacy USAJOBS applyUris as unverified candidates, so there is one reader', function () {
    $job = destJob(JobDiscoverySource::Usajobs, 'https://www.usajobs.gov/job/1', ['applyUris' => ['https://apply.usajobs.gov/legacy', 'http://10.0.0.1/x']]);
    $read = DestinationMetadata::read($job)['destinations'];

    expect(array_column($read, 'url'))->toBe(['https://apply.usajobs.gov/legacy'])->and($read[0]['evidence']['legacy'])->toBeTrue()->and($read[0]['verification'])->toBe(['status' => 'unverified']);
    // ...but only for USAJOBS: another source's applyUris are not destinations.
    expect(DestinationMetadata::read(destJob(JobDiscoverySource::Jobicy, 'https://jobicy.com/jobs/1-x', ['applyUris' => ['https://evil.example/x']]))['destinations'])->toBe([]);
});

it('shows destinations redacted: origin and path only, with their provenance and "unverified"', function () {
    $job = destJob(JobDiscoverySource::Usajobs, 'https://www.usajobs.gov/job/1', ['destinations' => [usaDest('https://apply.usajobs.gov/a/1?token=SECRET&id=7')]]);

    expect(DestinationMetadata::view($job))->toBe([['url' => 'https://apply.usajobs.gov/a/1', 'source' => 'usajobs', 'tier' => 'public', 'field' => 'ApplyURI', 'verification' => 'unverified']]);
    expect(json_encode(DestinationMetadata::view($job)))->not->toContain('SECRET');
});

// ---- the inspection start URL ---------------------------------------------------------------------------------------------------------------

function startFor(JobPosting $job, array $application = []): array
{
    return app(ResolveInspectionStartUrl::class)->resolve(Application::factory()->create(['job_posting_id' => $job->id] + $application));
}

it('starts at a candidate destination for a provider that supplies one, as a provider origin: unverified, never the Application URL', function () {
    $job = destJob(JobDiscoverySource::Usajobs, 'https://www.usajobs.gov/job/1', ['destinations' => [usaDest('https://apply.usajobs.gov/a/1')]]);

    $start = startFor($job);
    $application = Application::query()->latest('id')->first();

    expect($start)->toBe(['url' => 'https://apply.usajobs.gov/a/1', 'origin' => 'provider', 'source_url' => 'https://www.usajobs.gov/job/1'])
        ->and($application->application_url)->toBeNull('the candidate is not the Application URL')
        ->and($application->application_url_origin)->toBeNull();
});

it('lets a person\'s Application URL outrank a candidate, and the legacy USAJOBS list still works', function () {
    $job = destJob(JobDiscoverySource::Usajobs, 'https://www.usajobs.gov/job/1', ['destinations' => [usaDest('https://apply.usajobs.gov/a/1')]]);
    expect(startFor($job, ['application_url' => 'https://mine.example.com/apply', 'application_url_origin' => Application::URL_USER])['origin'])->toBe('user');

    $legacy = destJob(JobDiscoverySource::Usajobs, 'https://www.usajobs.gov/job/2', ['applyUris' => ['https://apply.usajobs.gov/a/2']]);
    expect(startFor($legacy))->toMatchArray(['origin' => 'provider', 'url' => 'https://apply.usajobs.gov/a/2']);
});

it('NEVER replaces a provider-mandated Apply link: a Himalayas job starts at its applicationLink even if a candidate is stored', function () {
    $url = 'https://himalayas.app/companies/acme/jobs/x-1';
    $entry = array_replace_recursive(DestinationMetadata::destination(JobDiscoverySource::Himalayas, 'https://boards.greenhouse.io/acme/jobs/1', 'applicationLink'), []);
    $job = destJob(JobDiscoverySource::Himalayas, $url, ['destinations' => [$entry]]);

    expect(startFor($job))->toBe(['url' => $url, 'origin' => 'source', 'source_url' => $url]);
});

it('ignores candidates that fail validation, equal the listing, or claim to be verified, and falls back to the source', function () {
    $listing = 'https://www.usajobs.gov/job/1';
    $job = destJob(JobDiscoverySource::Usajobs, $listing, ['destinations' => [usaDest($listing), usaDest('http://169.254.169.254/x'), usaDest('https://apply.usajobs.gov/claims', ['verification' => ['status' => 'verified']])]]);

    expect(startFor($job)['origin'])->toBe('source');
});

// ---- attribution under canonical enrichment ------------------------------------------------------------------------------------------------------

it('keeps the credit but not the provider link label when canonical enrichment replaced the stored URL with the employer\'s ATS URL', function () {
    $job = destJob(JobDiscoverySource::Jobicy, 'https://boards.greenhouse.io/acme/jobs/1', [], ['canonical_source' => JobCanonicalSource::Greenhouse, 'canonical_source_id' => '1']);

    expect(ProviderAttribution::for($job))->toMatchArray(['name' => 'Jobicy', 'link_label' => 'Original posting ↗', 'kind' => 'credit_only', 'notice' => 'Listing provided by']);
});
