<?php

use App\Enums\DecisionSource;
use App\Enums\DescriptionCompleteness;
use App\Enums\DismissalReason;
use App\Enums\JobCanonicalSource;
use App\Enums\JobDiscoverySource;
use App\Enums\JobDisposition;
use App\Enums\JobPostingLifecycleStatus;
use App\Models\CareerProfile;
use App\Models\JobPosting;
use App\Support\JobDecisions\RecordJobDecision;
use App\Support\JobDiscovery\Canonical\CanonicalJobPosting;
use App\Support\JobDiscovery\DiscoveredJobCandidate;
use App\Support\JobDiscovery\DiscoveryIngestionException;
use App\Support\JobDiscovery\IngestDiscoveredCandidate;
use Carbon\CarbonImmutable;

function discoveryCandidate(array $overrides = []): DiscoveredJobCandidate
{
    return new DiscoveredJobCandidate(
        source: $overrides['source'] ?? JobDiscoverySource::Himalayas,
        sourceJobId: $overrides['sourceJobId'] ?? 'himalayas-1',
        company: $overrides['company'] ?? 'Acme',
        title: $overrides['title'] ?? 'Senior Software Engineer',
        description: array_key_exists('description', $overrides) ? $overrides['description'] : 'Build things.',
        descriptionCompleteness: $overrides['descriptionCompleteness'] ?? DescriptionCompleteness::Complete,
        location: array_key_exists('location', $overrides) ? $overrides['location'] : 'United States',
        remoteStatus: $overrides['remoteStatus'] ?? null,
        employmentType: $overrides['employmentType'] ?? null,
        compensationMin: $overrides['compensationMin'] ?? null,
        compensationMax: $overrides['compensationMax'] ?? null,
        compensationCurrency: $overrides['compensationCurrency'] ?? null,
        compensationInterval: $overrides['compensationInterval'] ?? null,
        applicationUrl: array_key_exists('applicationUrl', $overrides) ? $overrides['applicationUrl'] : 'https://himalayas.app/jobs/1',
        postedAt: $overrides['postedAt'] ?? null,
        sourceUpdatedAt: $overrides['sourceUpdatedAt'] ?? null,
        discoveredAt: $overrides['discoveredAt'] ?? CarbonImmutable::now(),
        sourceMetadata: $overrides['sourceMetadata'] ?? [],
    );
}

function canonicalMatch(array $overrides = []): CanonicalJobPosting
{
    return new CanonicalJobPosting(
        canonicalSource: $overrides['canonicalSource'] ?? JobCanonicalSource::Greenhouse,
        canonicalSourceId: $overrides['canonicalSourceId'] ?? 'gh-1',
        title: $overrides['title'] ?? 'Senior Software Engineer',
        description: array_key_exists('description', $overrides) ? $overrides['description'] : 'Canonical description.',
        descriptionCompleteness: $overrides['descriptionCompleteness'] ?? DescriptionCompleteness::Complete,
        location: $overrides['location'] ?? 'New York, NY',
        remoteStatus: $overrides['remoteStatus'] ?? null,
        employmentType: $overrides['employmentType'] ?? null,
        compensationMin: $overrides['compensationMin'] ?? 150000,
        compensationMax: $overrides['compensationMax'] ?? 190000,
        compensationCurrency: $overrides['compensationCurrency'] ?? 'USD',
        compensationInterval: $overrides['compensationInterval'] ?? 'year',
        applicationUrl: array_key_exists('applicationUrl', $overrides) ? $overrides['applicationUrl'] : 'https://job-boards.greenhouse.io/acme/jobs/1',
        sourceUpdatedAt: $overrides['sourceUpdatedAt'] ?? null,
        sourceMetadata: $overrides['sourceMetadata'] ?? [],
    );
}

it('creates a new JobPosting for a genuinely new candidate', function () {
    $profile = CareerProfile::factory()->create();

    $result = (new IngestDiscoveredCandidate)->ingest(discoveryCandidate(), null, $profile);

    expect($result->wasCreated)->toBeTrue()
        ->and($result->wasCanonicalized)->toBeFalse()
        ->and($result->jobPosting->discovery_source)->toBe(JobDiscoverySource::Himalayas)
        ->and($result->jobPosting->discovery_source_id)->toBe('himalayas-1')
        ->and($result->jobPosting->canonical_source)->toBeNull()
        ->and($result->jobPosting->source_url)->toBe('https://himalayas.app/jobs/1');
});

it('prefers canonical data over discovery data when both are present', function () {
    $profile = CareerProfile::factory()->create();

    $result = (new IngestDiscoveredCandidate)->ingest(discoveryCandidate(), canonicalMatch(), $profile);

    expect($result->wasCanonicalized)->toBeTrue()
        ->and($result->jobPosting->description)->toBe('Canonical description.')
        ->and($result->jobPosting->source_url)->toBe('https://job-boards.greenhouse.io/acme/jobs/1')
        ->and($result->jobPosting->canonical_source)->toBe(JobCanonicalSource::Greenhouse)
        ->and($result->jobPosting->compensation_min)->toBe(150000);
});

it('tier 1: updates in place rather than duplicating on an exact (discovery_source, discovery_source_id) rematch', function () {
    $profile = CareerProfile::factory()->create();
    $first = (new IngestDiscoveredCandidate)->ingest(discoveryCandidate(['title' => 'Old Title']), null, $profile);

    $second = (new IngestDiscoveredCandidate)->ingest(discoveryCandidate(['title' => 'New Title']), null, $profile);

    expect($second->wasCreated)->toBeFalse()
        ->and($second->jobPosting->id)->toBe($first->jobPosting->id)
        ->and($second->jobPosting->title)->toBe('New Title')
        ->and(JobPosting::count())->toBe(1);
});

it('tier 1: bumps last_checked_at on rediscovery', function () {
    $profile = CareerProfile::factory()->create();
    $first = (new IngestDiscoveredCandidate)->ingest(discoveryCandidate(), null, $profile);
    $originalCheckedAt = $first->jobPosting->last_checked_at;

    CarbonImmutable::setTestNow(CarbonImmutable::now()->addHour());
    $second = (new IngestDiscoveredCandidate)->ingest(discoveryCandidate(), null, $profile);
    CarbonImmutable::setTestNow();

    expect($second->jobPosting->last_checked_at->isAfter($originalCheckedAt))->toBeTrue();
});

it('tier 2: updates the existing row (found via canonical identity) when the SAME job is rediscovered through a DIFFERENT provider, preserving original discovery provenance', function () {
    $profile = CareerProfile::factory()->create();
    $viaHimalayas = (new IngestDiscoveredCandidate)->ingest(
        discoveryCandidate(['source' => JobDiscoverySource::Himalayas, 'sourceJobId' => 'himalayas-1']),
        canonicalMatch(['canonicalSourceId' => 'gh-1']),
        $profile,
    );

    $viaAdzuna = (new IngestDiscoveredCandidate)->ingest(
        discoveryCandidate(['source' => JobDiscoverySource::Adzuna, 'sourceJobId' => 'adzuna-1']),
        canonicalMatch(['canonicalSourceId' => 'gh-1']),
        $profile,
    );

    expect($viaAdzuna->wasCreated)->toBeFalse()
        ->and($viaAdzuna->jobPosting->id)->toBe($viaHimalayas->jobPosting->id)
        // Discovery provenance is the ORIGINAL discoverer, never overwritten.
        ->and($viaAdzuna->jobPosting->discovery_source)->toBe(JobDiscoverySource::Himalayas)
        ->and($viaAdzuna->jobPosting->discovery_source_id)->toBe('himalayas-1')
        ->and(JobPosting::count())->toBe(1);
});

it('tier 2: never unsets canonical identity once resolved, even if a later rediscovery has no canonical match', function () {
    $profile = CareerProfile::factory()->create();
    $first = (new IngestDiscoveredCandidate)->ingest(discoveryCandidate(), canonicalMatch(), $profile);

    $second = (new IngestDiscoveredCandidate)->ingest(discoveryCandidate(), null, $profile);

    expect($second->jobPosting->id)->toBe($first->jobPosting->id)
        ->and($second->jobPosting->canonical_source)->toBe(JobCanonicalSource::Greenhouse)
        ->and($second->jobPosting->canonical_source_id)->toBe('gh-1');
});

it('a genuinely new canonical ID creates a distinct JobPosting rather than reopening the old one (repost semantics)', function () {
    $profile = CareerProfile::factory()->create();
    $first = (new IngestDiscoveredCandidate)->ingest(
        discoveryCandidate(['source' => JobDiscoverySource::Himalayas, 'sourceJobId' => 'himalayas-1', 'applicationUrl' => 'https://himalayas.app/jobs/original']),
        canonicalMatch(['canonicalSourceId' => 'gh-1', 'applicationUrl' => 'https://boards.greenhouse.io/acme/jobs/1']),
        $profile,
    );

    $repost = (new IngestDiscoveredCandidate)->ingest(
        discoveryCandidate(['source' => JobDiscoverySource::Himalayas, 'sourceJobId' => 'himalayas-2', 'applicationUrl' => 'https://himalayas.app/jobs/reposted']),
        canonicalMatch(['canonicalSourceId' => 'gh-2', 'applicationUrl' => 'https://boards.greenhouse.io/acme/jobs/2']),
        $profile,
    );

    expect($repost->wasCreated)->toBeTrue()
        ->and($repost->jobPosting->id)->not->toBe($first->jobPosting->id)
        ->and(JobPosting::count())->toBe(2);
});

it('tier 3: falls back to normalized application URL matching when neither identity matched', function () {
    $profile = CareerProfile::factory()->create();
    // First ingestion has no canonical match at all — establishes a
    // discovered row keyed only by (Himalayas, himalayas-1) with a
    // real source_url.
    $first = (new IngestDiscoveredCandidate)->ingest(
        discoveryCandidate(['source' => JobDiscoverySource::Himalayas, 'sourceJobId' => 'himalayas-1', 'applicationUrl' => 'https://boards.greenhouse.io/acme/jobs/1/']),
        null,
        $profile,
    );

    // A different provider discovers what happens to be the exact
    // same URL (different casing/trailing slash) with no canonical
    // match either — tier 1/2 can't catch this, tier 3 should.
    $second = (new IngestDiscoveredCandidate)->ingest(
        discoveryCandidate(['source' => JobDiscoverySource::Adzuna, 'sourceJobId' => 'adzuna-1', 'applicationUrl' => 'HTTPS://BOARDS.GREENHOUSE.IO/acme/jobs/1']),
        null,
        $profile,
    );

    expect($second->wasCreated)->toBeFalse()
        ->and($second->jobPosting->id)->toBe($first->jobPosting->id)
        ->and(JobPosting::count())->toBe(1);
});

it('never matches (tier 3 or otherwise) against a manually-created JobPosting, even with an identical URL', function () {
    $profile = CareerProfile::factory()->create();
    JobPosting::factory()->for($profile)->create(['source_url' => 'https://boards.greenhouse.io/acme/jobs/1']);

    $result = (new IngestDiscoveredCandidate)->ingest(
        discoveryCandidate(['applicationUrl' => 'https://boards.greenhouse.io/acme/jobs/1']),
        null,
        $profile,
    );

    expect($result->wasCreated)->toBeTrue()
        ->and(JobPosting::count())->toBe(2);
});

it('accepts a cross-provider duplicate surviving when neither identity nor URL can establish it is the same job (known Phase 1 limitation)', function () {
    $profile = CareerProfile::factory()->create();
    (new IngestDiscoveredCandidate)->ingest(
        discoveryCandidate(['source' => JobDiscoverySource::Himalayas, 'sourceJobId' => 'himalayas-1', 'applicationUrl' => 'https://himalayas.app/jobs/1']),
        null,
        $profile,
    );

    $second = (new IngestDiscoveredCandidate)->ingest(
        discoveryCandidate(['source' => JobDiscoverySource::Adzuna, 'sourceJobId' => 'adzuna-1', 'applicationUrl' => 'https://adzuna.com/jobs/1']),
        null,
        $profile,
    );

    expect($second->wasCreated)->toBeTrue()
        ->and(JobPosting::count())->toBe(2);
});

it('never rejects ingestion for a candidate with no description, defaulting to an empty string', function () {
    $profile = CareerProfile::factory()->create();

    $result = (new IngestDiscoveredCandidate)->ingest(discoveryCandidate(['description' => null]), null, $profile);

    expect($result->jobPosting->description)->toBe('');
});

it('preserves uncertain URL identity while normalizing only harmless differences', function (string $firstUrl, string $secondUrl, bool $same) {
    $profile = CareerProfile::factory()->create();
    $first = (new IngestDiscoveredCandidate)->ingest(discoveryCandidate(['applicationUrl' => $firstUrl]), null, $profile);
    $second = (new IngestDiscoveredCandidate)->ingest(discoveryCandidate(['sourceJobId' => 'other', 'applicationUrl' => $secondUrl]), null, $profile);
    expect($second->jobPosting->id === $first->jobPosting->id)->toBe($same)
        ->and(JobPosting::count())->toBe($same ? 1 : 2);
})->with([
    'host scheme port slash' => ['https://EXAMPLE.com:443/apply/', 'HTTPS://example.com/apply', true],
    'query IDs' => ['https://example.com/apply?job=1', 'https://example.com/apply?job=2', false],
    'path case' => ['https://example.com/Jobs/ABC', 'https://example.com/jobs/abc', false],
    'uncertain tracking' => ['https://example.com/apply?ref=1', 'https://example.com/apply?ref=2', false],
    'fragment routing' => ['https://example.com/#/jobs/1', 'https://example.com/#/jobs/2', false],
]);

it('rejects convergence between existing discovery and canonical owners without changing either', function () {
    $profile = CareerProfile::factory()->create();
    (new IngestDiscoveredCandidate)->ingest(discoveryCandidate(), null, $profile);
    (new IngestDiscoveredCandidate)->ingest(discoveryCandidate(['sourceJobId' => 'other']), canonicalMatch(), $profile);
    $before = JobPosting::orderBy('id')->get()->toArray();
    expect(fn () => (new IngestDiscoveredCandidate)->ingest(discoveryCandidate(), canonicalMatch(), $profile))
        ->toThrow(DiscoveryIngestionException::class);
    expect(JobPosting::orderBy('id')->get()->toArray())->toBe($before);
});

it('rejects a reused discovery ID with a different canonical identity without changing history', function () {
    $profile = CareerProfile::factory()->create();
    (new IngestDiscoveredCandidate)->ingest(discoveryCandidate(), canonicalMatch(), $profile);
    $before = JobPosting::first()->toArray();
    expect(fn () => (new IngestDiscoveredCandidate)->ingest(discoveryCandidate(), canonicalMatch(['canonicalSourceId' => 'gh-new']), $profile))
        ->toThrow(DiscoveryIngestionException::class);
    expect(JobPosting::first()->toArray())->toBe($before)->and(JobPosting::count())->toBe(1);
});

it('creates a new canonical repost despite a shared URL and leaves the closed old posting historical', function () {
    $profile = CareerProfile::factory()->create();
    $old = (new IngestDiscoveredCandidate)->ingest(discoveryCandidate(), canonicalMatch(), $profile)->jobPosting;
    $old->update(['status' => JobPostingLifecycleStatus::Closed]);
    $before = $old->fresh()->toArray();
    $new = (new IngestDiscoveredCandidate)->ingest(discoveryCandidate(['sourceJobId' => 'new']), canonicalMatch(['canonicalSourceId' => 'gh-new']), $profile);
    expect($new->wasCreated)->toBeTrue()->and($new->jobPosting->canonical_source_id)->toBe('gh-new')
        ->and($old->fresh()->toArray())->toBe($before)->and(JobPosting::count())->toBe(2);
});

it('preserves canonical content through missing enrichment and refreshes the same identity on later success', function () {
    $profile = CareerProfile::factory()->create();
    $candidate = discoveryCandidate(['sourceMetadata' => ['category' => 'original']]);
    $first = (new IngestDiscoveredCandidate)->ingest($candidate, canonicalMatch(['sourceMetadata' => ['department' => 'Engineering']]), $profile)->jobPosting;
    $authoritative = $first->only(['title', 'description', 'location', 'source_url', 'canonical_source', 'canonical_source_id', 'compensation_min', 'compensation_max']);
    $this->travel(1)->hours();
    $during = (new IngestDiscoveredCandidate)->ingest(discoveryCandidate(['title' => 'Aggregator title', 'description' => 'Truncated snippet', 'sourceMetadata' => ['category' => 'updated']]), null, $profile)->jobPosting;
    expect($during->only(array_keys($authoritative)))->toBe($authoritative)
        ->and($during->discovery_metadata['canonical'])->toBe(['department' => 'Engineering'])
        ->and($during->last_checked_at->isAfter($first->last_checked_at))->toBeTrue();
    $after = (new IngestDiscoveredCandidate)->ingest($candidate, canonicalMatch(['title' => 'Fresh canonical title', 'description' => 'Fresh full content', 'sourceMetadata' => ['department' => 'Platform']]), $profile)->jobPosting;
    expect($after->id)->toBe($first->id)->and($after->description)->toBe('Fresh full content')
        ->and($after->title)->toBe('Fresh canonical title')
        ->and($after->discovery_metadata['canonical'])->toBe(['department' => 'Platform'])
        ->and(JobPosting::count())->toBe(1);
});

it('keeps original provider metadata when another aggregator rediscovers the canonical job', function () {
    $profile = CareerProfile::factory()->create();
    $first = (new IngestDiscoveredCandidate)->ingest(discoveryCandidate(['sourceMetadata' => ['companySlug' => 'original']]), canonicalMatch(), $profile)->jobPosting;
    $second = (new IngestDiscoveredCandidate)->ingest(discoveryCandidate([
        'source' => JobDiscoverySource::Adzuna, 'sourceJobId' => 'adzuna-2', 'sourceMetadata' => ['category' => 'other-provider'],
    ]), canonicalMatch(), $profile)->jobPosting;
    expect($second->id)->toBe($first->id)->and($second->discovery_source)->toBe(JobDiscoverySource::Himalayas)
        ->and($second->discovery_metadata['discovery'])->toBe(['companySlug' => 'original'])
        ->and($second->discovered_at->equalTo($first->discovered_at))->toBeTrue();
});

it('persists the discovery description completeness', function (JobDiscoverySource $source, DescriptionCompleteness $completeness) {
    $profile = CareerProfile::factory()->create();
    $result = (new IngestDiscoveredCandidate)->ingest(discoveryCandidate([
        'source' => $source, 'descriptionCompleteness' => $completeness,
    ]), null, $profile);
    expect($result->jobPosting->fresh()->description_completeness)->toBe($completeness);
})->with([
    [JobDiscoverySource::Jobicy, DescriptionCompleteness::Complete],
    [JobDiscoverySource::Himalayas, DescriptionCompleteness::Complete],
    [JobDiscoverySource::Adzuna, DescriptionCompleteness::Preview],
    [JobDiscoverySource::Himalayas, DescriptionCompleteness::Unknown],
]);

it('uses canonical completeness on creation and on an existing preview upgrade', function (bool $existing) {
    $profile = CareerProfile::factory()->create();
    $ingest = new IngestDiscoveredCandidate;
    $candidate = discoveryCandidate(['source' => JobDiscoverySource::Adzuna, 'descriptionCompleteness' => DescriptionCompleteness::Preview]);
    if ($existing) {
        $first = $ingest->ingest($candidate, null, $profile)->jobPosting;
        expect($first->description_completeness)->toBe(DescriptionCompleteness::Preview);
    }
    $job = $ingest->ingest($candidate, canonicalMatch(), $profile)->jobPosting->fresh();
    expect($job->description)->toBe('Canonical description.')
        ->and($job->description_completeness)->toBe(DescriptionCompleteness::Complete)
        ->and(JobPosting::count())->toBe(1);
    $refreshed = $ingest->ingest($candidate, null, $profile)->jobPosting->fresh();
    expect($refreshed->description)->toBe($job->description)
        ->and($refreshed->description_completeness)->toBe(DescriptionCompleteness::Complete);
})->with([false, true]);

it('does not let a null canonical description claim ownership of preview text', function () {
    $profile = CareerProfile::factory()->create();
    $ingest = new IngestDiscoveredCandidate;
    $candidate = discoveryCandidate(['descriptionCompleteness' => DescriptionCompleteness::Preview]);
    $job = $ingest->ingest($candidate, canonicalMatch(['description' => null]), $profile)->jobPosting;
    expect($job->description)->toBe($candidate->description)
        ->and($job->description_completeness)->toBe(DescriptionCompleteness::Preview);
    $job = $ingest->ingest($candidate, canonicalMatch(), $profile)->jobPosting;
    $job = $ingest->ingest($candidate, canonicalMatch(['description' => null]), $profile)->jobPosting->fresh();
    expect($job->description)->toBe('Canonical description.')
        ->and($job->description_completeness)->toBe(DescriptionCompleteness::Complete);
});

// Description-quality protection (Complete > Preview > Unknown). A second
// provider rediscovering the same posting reaches the existing row through
// tier 3 (shared application URL), as in the real Himalayas -> Adzuna case.
function rediscoverVia(JobDiscoverySource $source, DescriptionCompleteness $completeness, array $overrides = []): JobPosting
{
    return (new IngestDiscoveredCandidate)->ingest(discoveryCandidate($overrides + [
        'source' => $source,
        'sourceJobId' => "{$source->value}-1",
        'descriptionCompleteness' => $completeness,
    ]), null, CareerProfile::query()->firstOrFail())->jobPosting->fresh();
}

it('applies the Complete > Preview > Unknown hierarchy when another provider rediscovers a posting', function (
    DescriptionCompleteness $existing,
    DescriptionCompleteness $incoming,
    bool $replaced,
) {
    CareerProfile::factory()->create();
    rediscoverVia(JobDiscoverySource::Himalayas, $existing, ['description' => 'Existing text.']);

    $job = rediscoverVia(JobDiscoverySource::Adzuna, $incoming, ['description' => 'Incoming text.']);

    expect(JobPosting::count())->toBe(1)
        ->and($job->description)->toBe($replaced ? 'Incoming text.' : 'Existing text.')
        // Completeness always describes the text actually stored.
        ->and($job->description_completeness)->toBe($replaced ? $incoming : $existing);
})->with([
    'complete then preview is protected' => [DescriptionCompleteness::Complete, DescriptionCompleteness::Preview, false],
    'complete then unknown is protected' => [DescriptionCompleteness::Complete, DescriptionCompleteness::Unknown, false],
    'preview then unknown is protected' => [DescriptionCompleteness::Preview, DescriptionCompleteness::Unknown, false],
    'preview then complete upgrades' => [DescriptionCompleteness::Preview, DescriptionCompleteness::Complete, true],
    'unknown then complete upgrades' => [DescriptionCompleteness::Unknown, DescriptionCompleteness::Complete, true],
    'unknown then preview upgrades' => [DescriptionCompleteness::Unknown, DescriptionCompleteness::Preview, true],
    'complete then complete refreshes' => [DescriptionCompleteness::Complete, DescriptionCompleteness::Complete, true],
    'preview then preview refreshes' => [DescriptionCompleteness::Preview, DescriptionCompleteness::Preview, true],
    'unknown then unknown refreshes' => [DescriptionCompleteness::Unknown, DescriptionCompleteness::Unknown, true],
]);

it('keeps a Complete description analyzable after a Preview rediscovery', function () {
    CareerProfile::factory()->create();
    rediscoverVia(JobDiscoverySource::Himalayas, DescriptionCompleteness::Complete, ['description' => 'The full posting.']);

    $job = rediscoverVia(JobDiscoverySource::Adzuna, DescriptionCompleteness::Preview, ['description' => 'Snippet…']);

    // ProcessBatchOpportunities::decideAnalyze requires Complete.
    expect($job->description_completeness)->toBe(DescriptionCompleteness::Complete)
        ->and($job->description)->toBe('The full posting.');
});

it('never erases useful description content with a null, empty or blank incoming description', function (?string $incoming) {
    CareerProfile::factory()->create();
    rediscoverVia(JobDiscoverySource::Himalayas, DescriptionCompleteness::Complete, ['description' => 'The full posting.']);

    $sameProvider = rediscoverVia(JobDiscoverySource::Himalayas, DescriptionCompleteness::Complete, ['description' => $incoming]);
    $otherProvider = rediscoverVia(JobDiscoverySource::Adzuna, DescriptionCompleteness::Complete, ['description' => $incoming]);

    expect($sameProvider->description)->toBe('The full posting.')
        ->and($otherProvider->description)->toBe('The full posting.')
        ->and($otherProvider->description_completeness)->toBe(DescriptionCompleteness::Complete);
})->with([null, '', "  \n "]);

it('fills an empty stored description from any non-empty incoming description', function () {
    CareerProfile::factory()->create();
    rediscoverVia(JobDiscoverySource::Adzuna, DescriptionCompleteness::Preview, ['description' => null]);

    $job = rediscoverVia(JobDiscoverySource::Himalayas, DescriptionCompleteness::Unknown, ['description' => 'Something.']);

    expect($job->description)->toBe('Something.')
        ->and($job->description_completeness)->toBe(DescriptionCompleteness::Unknown);
});

it('still accepts improvements to other fields while protecting a Complete description', function () {
    CareerProfile::factory()->create();
    rediscoverVia(JobDiscoverySource::Himalayas, DescriptionCompleteness::Complete, [
        'description' => 'The full posting.',
        'location' => 'Remote',
        'sourceMetadata' => ['seniority' => 'senior'],
    ]);

    $job = rediscoverVia(JobDiscoverySource::Adzuna, DescriptionCompleteness::Preview, [
        'description' => 'Snippet…',
        'location' => 'Austin, TX',
        'compensationMin' => 140000,
        'compensationMax' => 180000,
        'compensationCurrency' => 'USD',
        'compensationInterval' => 'year',
        'sourceMetadata' => ['category' => 'IT Jobs'],
    ]);

    expect($job->description)->toBe('The full posting.')
        ->and($job->description_completeness)->toBe(DescriptionCompleteness::Complete)
        ->and($job->location)->toBe('Austin, TX')
        ->and($job->compensation_min)->toBe(140000)
        ->and($job->compensation_max)->toBe(180000)
        // Existing metadata merge behavior is unchanged: only the original
        // discovery source's metadata is kept.
        ->and($job->discovery_metadata['discovery'])->toBe(['seniority' => 'senior'])
        ->and($job->discovery_source)->toBe(JobDiscoverySource::Himalayas);
});

it('is idempotent when the same candidate is ingested repeatedly', function () {
    CareerProfile::factory()->create();
    $first = rediscoverVia(JobDiscoverySource::Himalayas, DescriptionCompleteness::Complete, ['description' => 'The full posting.']);
    $before = $first->only(['description', 'description_completeness', 'title', 'location', 'source_url', 'discovery_metadata']);

    foreach (range(1, 3) as $_) {
        $again = rediscoverVia(JobDiscoverySource::Himalayas, DescriptionCompleteness::Complete, ['description' => 'The full posting.']);
    }

    expect($again->only(array_keys($before)))->toBe($before)
        ->and(JobPosting::count())->toBe(1);
});

it('protects a Complete canonical description from a Preview canonical match and a later preview candidate', function () {
    $profile = CareerProfile::factory()->create();
    $ingest = new IngestDiscoveredCandidate;
    $ingest->ingest(discoveryCandidate(), canonicalMatch(['description' => 'Canonical full.']), $profile);

    $viaPreviewCanonical = $ingest->ingest(
        discoveryCandidate(['source' => JobDiscoverySource::Adzuna, 'sourceJobId' => 'adzuna-1', 'descriptionCompleteness' => DescriptionCompleteness::Preview]),
        canonicalMatch(['description' => 'Canonical snippet.', 'descriptionCompleteness' => DescriptionCompleteness::Preview]),
        $profile,
    )->jobPosting->fresh();

    expect($viaPreviewCanonical->description)->toBe('Canonical full.')
        ->and($viaPreviewCanonical->description_completeness)->toBe(DescriptionCompleteness::Complete);
});

it('keeps a user decision, and its history, when the same posting is rediscovered', function () {
    $profile = CareerProfile::factory()->create();
    $ingest = new IngestDiscoveredCandidate;
    $job = $ingest->ingest(discoveryCandidate(), null, $profile)->jobPosting;
    $decision = app(RecordJobDecision::class)->record($job, JobDisposition::Dismissed, DecisionSource::User, DismissalReason::Compensation, 'Pays too little');

    $again = $ingest->ingest(discoveryCandidate(['title' => 'Senior Software Engineer II', 'compensationMax' => 250000]), null, $profile);

    expect($again->wasCreated)->toBeFalse()
        ->and($again->jobPosting->id)->toBe($job->id)
        ->and($again->jobPosting->title)->toBe('Senior Software Engineer II')
        ->and($job->fresh()->disposition)->toBe(JobDisposition::Dismissed)
        ->and($job->fresh()->disposition_reason)->toBe('compensation')
        ->and($job->decisions)->toHaveCount(1)->and($job->decisions->first()->id)->toBe($decision->id);
});

it('creates every discovered job in the inbox', function () {
    $job = (new IngestDiscoveredCandidate)->ingest(discoveryCandidate(), null, CareerProfile::factory()->create())->jobPosting;

    expect($job->fresh()->disposition)->toBe(JobDisposition::New);
});
