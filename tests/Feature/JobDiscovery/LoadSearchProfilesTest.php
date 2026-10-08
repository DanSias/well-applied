<?php

use App\Enums\DescriptionCompleteness;
use App\Enums\JobDiscoverySource;
use App\Enums\JobRemoteStatus;
use App\Support\JobDiscovery\DiscoveredJobCandidate;
use App\Support\JobDiscovery\DiscoverySearchCriteria;
use App\Support\JobDiscovery\FilterDiscoveredCandidates;
use App\Support\JobDiscovery\LoadSearchProfiles;
use App\Support\JobDiscovery\Providers\DiscoveryProviderContract;
use App\Support\JobDiscovery\SearchProfile;
use App\Support\JobDiscovery\SearchProfileConfigurationException;
use Carbon\CarbonImmutable;

function discoveredCandidate(array $overrides = []): DiscoveredJobCandidate
{
    return new DiscoveredJobCandidate(
        source: $overrides['source'] ?? JobDiscoverySource::Himalayas,
        sourceJobId: $overrides['sourceJobId'] ?? 'id-1',
        company: $overrides['company'] ?? 'Acme',
        title: $overrides['title'] ?? 'Senior Software Engineer',
        description: $overrides['description'] ?? 'Build backend APIs and data pipelines.',
        descriptionCompleteness: $overrides['descriptionCompleteness'] ?? DescriptionCompleteness::Complete,
        location: array_key_exists('location', $overrides) ? $overrides['location'] : 'United States',
        remoteStatus: array_key_exists('remoteStatus', $overrides) ? $overrides['remoteStatus'] : JobRemoteStatus::Remote,
        employmentType: array_key_exists('employmentType', $overrides) ? $overrides['employmentType'] : 'full_time',
        compensationMin: null,
        compensationMax: null,
        compensationCurrency: null,
        compensationInterval: null,
        applicationUrl: null,
        postedAt: null,
        sourceUpdatedAt: null,
        discoveredAt: CarbonImmutable::now(),
        sourceMetadata: $overrides['sourceMetadata'] ?? [],
    );
}

function profilesBase(array $overrides = []): array
{
    // The smallest VALID profile entry: required fields only, every
    // optional field absent.
    return array_merge([
        'label' => 'Test Profile',
        'include_terms' => ['software engineer', 'backend'],
        'exclude_terms' => ['intern', 'junior'],
        'remote_preference' => 'remote_us',
    ], $overrides);
}

it('loads all configured search profiles in config order', function () {
    $profiles = LoadSearchProfiles::all();

    expect($profiles)->toHaveCount(3);

    foreach ($profiles as $profile) {
        expect($profile)->toBeInstanceOf(SearchProfile::class);
    }

    expect(array_map(static fn (SearchProfile $profile): string => $profile->identifier, $profiles))->toBe([
        'software_engineering', 'data_analytics', 'marketing_technology',
    ]);
});

it('retrieves each seeded profile by identifier', function () {
    expect(LoadSearchProfiles::byId('software_engineering')->label)->toBe('Software Engineering')
        ->and(LoadSearchProfiles::byId('data_analytics')->label)->toBe('Data & Analytics')
        ->and(LoadSearchProfiles::byId('marketing_technology')->label)->toBe('Marketing Technology');
});

it('keeps every seeded profile broad and non-restrictive', function () {
    foreach (['software_engineering', 'data_analytics', 'marketing_technology'] as $identifier) {
        $profile = LoadSearchProfiles::byId($identifier);

        expect($profile->preferredSeniority)->toBe('Senior')
            ->and($profile->remotePreference)->toBe('remote_us')
            ->and($profile->allowedLocations)->toBe(['United States', 'US', 'USA', 'Remote'])
            ->and($profile->employmentType)->toBeNull()
            ->and($profile->includeTerms)->not->toBe([])
            ->and($profile->excludeTerms)->not->toBe([]);

        expect($profile->description)->toBeString();
        expect(trim($profile->description))->not->toBe('');
    }
});

it('does not leak role-identity terms into profile exclusion lists', function () {
    foreach (['software_engineering', 'data_analytics', 'marketing_technology'] as $identifier) {
        $profile = LoadSearchProfiles::byId($identifier);

        expect($profile->excludeTerms)->not->toContain('devops')
            ->and($profile->excludeTerms)->not->toContain('sre');
    }
});

it('anchors each family with its characteristic include and exclude terms', function () {
    $softwareEngineering = LoadSearchProfiles::byId('software_engineering');

    expect($softwareEngineering->includeTerms)->toContain('software engineer')
        ->and($softwareEngineering->includeTerms)->toContain('backend')
        ->and($softwareEngineering->excludeTerms)->toContain('intern')
        ->and($softwareEngineering->excludeTerms)->toContain('junior');

    $dataAnalytics = LoadSearchProfiles::byId('data_analytics');

    expect($dataAnalytics->includeTerms)->toContain('data engineer')
        ->and($dataAnalytics->includeTerms)->toContain('analytics engineer')
        ->and($dataAnalytics->excludeTerms)->toContain('data entry');

    $marketingTechnology = LoadSearchProfiles::byId('marketing_technology');

    expect($marketingTechnology->includeTerms)->toContain('marketing engineer')
        ->and($marketingTechnology->includeTerms)->toContain('growth engineer')
        ->and($marketingTechnology->excludeTerms)->toContain('content marketer');
});

it('trims whitespace and drops empty terms and strings', function () {
    config(['job_discovery.search_profiles' => ['padded' => profilesBase([
        'label' => '  Padded  ',
        'include_terms' => ['  software engineer  ', ''],
        'preferred_seniority' => ' Senior ',
        'description' => '  Padded prose  ',
    ])]]);

    $profile = LoadSearchProfiles::byId('padded');

    expect($profile->label)->toBe('Padded')
        ->and($profile->includeTerms)->toBe(['software engineer'])
        ->and($profile->preferredSeniority)->toBe('Senior')
        ->and($profile->description)->toBe('Padded prose');
});

it('defaults optional fields when they are absent from a profile entry', function () {
    config(['job_discovery.search_profiles' => ['minimal' => profilesBase()]]);

    $profile = LoadSearchProfiles::byId('minimal');

    expect($profile->preferredSeniority)->toBeNull()
        ->and($profile->allowedLocations)->toBe([])
        ->and($profile->employmentType)->toBeNull()
        ->and($profile->description)->toBeNull();
});

it('treats explicit null optional fields the same as absent ones', function () {
    config(['job_discovery.search_profiles' => ['minimal' => array_merge(profilesBase(), [
        'preferred_seniority' => null,
        'allowed_locations' => null,
        'employment_type' => null,
        'description' => null,
    ])]]);

    $profile = LoadSearchProfiles::byId('minimal');

    expect($profile->preferredSeniority)->toBeNull()
        ->and($profile->allowedLocations)->toBe([])
        ->and($profile->employmentType)->toBeNull()
        ->and($profile->description)->toBeNull();
});

it('names the profile and lists the fields present when a required field is missing', function () {
    $entry = profilesBase();
    unset($entry['label']);
    config(['job_discovery.search_profiles' => ['broken' => $entry]]);

    try {
        LoadSearchProfiles::all();
    } catch (SearchProfileConfigurationException $exception) {
        expect($exception->getMessage())
            ->toContain('broken')
            ->toContain('label')
            ->toContain('Currently defined')
            ->toContain('include_terms');

        return;
    }

    $this->fail('LoadSearchProfiles::all() should have thrown for a missing label.');
});

it('rejects a required label that is empty, whitespace-only, null or not a string', function (mixed $label) {
    config(['job_discovery.search_profiles' => ['broken' => profilesBase(['label' => $label])]]);

    expect(fn () => LoadSearchProfiles::all())->toThrow(SearchProfileConfigurationException::class, 'label');
})->with([
    'empty string' => [''],
    'whitespace only' => ['   '],
    'null' => [null],
    'integer' => [42],
]);

it('rejects missing include_terms', function () {
    $entry = profilesBase();
    unset($entry['include_terms']);
    config(['job_discovery.search_profiles' => ['broken' => $entry]]);

    expect(fn () => LoadSearchProfiles::all())->toThrow(SearchProfileConfigurationException::class, 'include_terms');
});

it('rejects include_terms entries that are not strings', function (array $includeTerms) {
    config(['job_discovery.search_profiles' => ['broken' => profilesBase(['include_terms' => $includeTerms])]]);

    expect(fn () => LoadSearchProfiles::all())->toThrow(SearchProfileConfigurationException::class, 'must contain only strings');
})->with([
    'integer element' => [['software engineer', 42]],
    'nested array element' => [['software engineer', ['backend']]],
]);

it('rejects include_terms that are empty or whitespace-only', function (array $includeTerms) {
    config(['job_discovery.search_profiles' => ['broken' => profilesBase(['include_terms' => $includeTerms])]]);

    expect(fn () => LoadSearchProfiles::all())->toThrow(SearchProfileConfigurationException::class, 'at least one non-empty entry');
})->with([
    'no entries at all' => [[]],
    'whitespace only' => [['   ', '']],
]);

it('rejects include_terms that is not a list', function (mixed $includeTerms) {
    config(['job_discovery.search_profiles' => ['broken' => profilesBase(['include_terms' => $includeTerms])]]);

    expect(fn () => LoadSearchProfiles::all())->toThrow(SearchProfileConfigurationException::class, 'must be a list of strings');
})->with([
    'a plain string' => ['backend'],
    'an integer' => [7],
    'null' => [null],
]);

it('rejects missing exclude_terms', function () {
    $entry = profilesBase();
    unset($entry['exclude_terms']);
    config(['job_discovery.search_profiles' => ['broken' => $entry]]);

    expect(fn () => LoadSearchProfiles::all())->toThrow(SearchProfileConfigurationException::class, 'exclude_terms');
});

it('rejects exclude_terms that is not a list', function () {
    config(['job_discovery.search_profiles' => ['broken' => profilesBase(['exclude_terms' => 'intern'])]]);

    expect(fn () => LoadSearchProfiles::all())->toThrow(SearchProfileConfigurationException::class, 'must be a list of strings');
});

it('rejects a missing remote_preference', function () {
    $entry = profilesBase();
    unset($entry['remote_preference']);
    config(['job_discovery.search_profiles' => ['broken' => $entry]]);

    expect(fn () => LoadSearchProfiles::all())->toThrow(SearchProfileConfigurationException::class, 'remote_preference');
});

it('rejects a remote_preference outside the V1 vocabulary', function (mixed $remotePreference) {
    config(['job_discovery.search_profiles' => ['broken' => profilesBase(['remote_preference' => $remotePreference])]]);

    expect(fn () => LoadSearchProfiles::all())->toThrow(SearchProfileConfigurationException::class, 'either "remote_us" or "any"');
})->with([
    'typo: remote_uk' => ['remote_uk'],
    'typo: Remote US' => ['Remote US'],
    'integer' => [42],
]);

it('rejects unknown fields (typos) and lists the allowed ones', function () {
    config(['job_discovery.search_profiles' => ['broken' => profilesBase(['include_term' => ['backend']])]]);

    try {
        LoadSearchProfiles::all();
    } catch (SearchProfileConfigurationException $exception) {
        expect($exception->getMessage())
            ->toContain('broken')
            ->toContain('include_term')
            ->toContain('unknown field')
            ->toContain('Allowed fields');

        return;
    }

    $this->fail('LoadSearchProfiles::all() should have thrown for an unknown field.');
});

it('rejects a profile entry that is not an array of fields', function (mixed $entry) {
    config(['job_discovery.search_profiles' => ['broken' => $entry]]);

    expect(fn () => LoadSearchProfiles::all())->toThrow(SearchProfileConfigurationException::class, 'must define an array of fields');
})->with([
    'a string' => ['software engineering'],
    'an integer' => [7],
]);

it('requires every profile to be keyed by a non-empty string identifier', function () {
    config(['job_discovery.search_profiles' => [0 => profilesBase()]]);

    expect(fn () => LoadSearchProfiles::all())->toThrow(SearchProfileConfigurationException::class, 'non-empty string identifier');
});

it('rejects a search_profiles section that is not an array', function () {
    config(['job_discovery.search_profiles' => 'not an array']);

    expect(fn () => LoadSearchProfiles::all())->toThrow(SearchProfileConfigurationException::class, 'must be an array');
});

it('returns no profiles when the section is null', function () {
    config(['job_discovery.search_profiles' => null]);

    expect(LoadSearchProfiles::all())->toBe([]);
});

it('reports an unknown identifier alongside the configured ones', function () {
    expect(fn () => LoadSearchProfiles::byId('machine_learning'))
        ->toThrow(SearchProfileConfigurationException::class, 'Configured profiles: software_engineering, data_analytics, marketing_technology');
});

it('reports "(none)" when an identifier is unknown and no profiles are configured', function () {
    config(['job_discovery.search_profiles' => []]);

    expect(fn () => LoadSearchProfiles::byId('anything'))->toThrow(SearchProfileConfigurationException::class, '(none)');
});

it('profiles are immutable: reassigning a property throws', function () {
    $profile = LoadSearchProfiles::byId('software_engineering');

    expect(fn () => $profile->label = 'Renamed')->toThrow(Error::class);
});

it('two loads produce equal-but-distinct profile instances', function () {
    $first = LoadSearchProfiles::byId('software_engineering');
    $second = LoadSearchProfiles::byId('software_engineering');

    expect($first)->toEqual($second);
    expect($first)->not->toBe($second);
});

it('a loaded profile is a snapshot of config: later changes do not alter it', function () {
    $profile = LoadSearchProfiles::byId('software_engineering');

    config(['job_discovery.search_profiles.software_engineering.label' => 'Renamed Profile']);

    expect($profile->label)->toBe('Software Engineering');
    expect(LoadSearchProfiles::byId('software_engineering')->label)->toBe('Renamed Profile');
});

it('does not alter the ACTIVE V1 search criteria', function () {
    $criteria = DiscoverySearchCriteria::fromConfig();

    expect($criteria->keywords)->toContain('software engineer')->toContain('backend')
        ->and($criteria->excludedKeywords)->toContain('intern')->toContain('junior')
        ->and($criteria->excludedTitleKeywords)->toBe(['site reliability', 'sre', 'devops'])
        ->and($criteria->remotePreference)->toBe('remote_us')
        ->and($criteria->allowedLocations)->toBe(['United States', 'US', 'USA', 'Remote'])
        ->and($criteria->employmentType)->toBeNull();
});

it('still rejects the DevOps-titled live false positive under the V1 criteria', function () {
    $job = discoveredCandidate([
        'title' => 'Principal Software Engineer, DevOps',
        'description' => 'Own AWS, Kubernetes, Terraform, and on-call production infrastructure.',
    ]);

    config(['job_discovery.search.remote_preference' => 'any']);

    expect((new FilterDiscoveredCandidates)->accepts($job, DiscoverySearchCriteria::fromConfig()))->toBeFalse();
});

it('still accepts a full-stack title whose description mentions infrastructure, under the V1 criteria', function () {
    $job = discoveredCandidate([
        'title' => 'Senior Software Engineer, Fullstack',
        'description' => 'Work with the DevOps and site reliability teams on Kubernetes, Terraform and AWS infrastructure.',
    ]);

    config(['job_discovery.search.remote_preference' => 'any']);

    expect((new FilterDiscoveredCandidates)->accepts($job, DiscoverySearchCriteria::fromConfig()))->toBeTrue();
});

it('leaves the provider contract signature unchanged', function () {
    expect((new ReflectionMethod(DiscoveryProviderContract::class, 'retrieve'))->getNumberOfRequiredParameters())->toBe(0);
});
