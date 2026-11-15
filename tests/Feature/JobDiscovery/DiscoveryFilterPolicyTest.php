<?php

use App\Enums\DescriptionCompleteness;
use App\Enums\DiscoveryRejectionReason;
use App\Enums\JobDiscoverySource;
use App\Enums\JobRemoteStatus;
use App\Support\JobDiscovery\DiscoveredJobCandidate;
use App\Support\JobDiscovery\DiscoverySearchCriteria;
use App\Support\JobDiscovery\FilterDiscoveredCandidates;
use App\Support\JobDiscovery\LocationSignals;
use Carbon\CarbonImmutable;

/**
 * The shipped discovery policy (config/job_discovery.php), exercised through realistic titles, descriptions and
 * locations. FilterDiscoveredCandidatesTest covers the filter's mechanics with custom criteria.
 */
function policyCandidate(array $overrides = []): DiscoveredJobCandidate
{
    return new DiscoveredJobCandidate(
        source: $overrides['source'] ?? JobDiscoverySource::Jobicy,
        sourceJobId: 'policy-1',
        company: 'Acme',
        title: $overrides['title'] ?? 'Senior Software Engineer',
        description: $overrides['description'] ?? 'Build and ship product features.',
        descriptionCompleteness: DescriptionCompleteness::Complete,
        location: array_key_exists('location', $overrides) ? $overrides['location'] : 'USA',
        remoteStatus: array_key_exists('remoteStatus', $overrides) ? $overrides['remoteStatus'] : JobRemoteStatus::Remote,
        employmentType: null,
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

function policyDecision(array $overrides = [])
{
    return (new FilterDiscoveredCandidates)->evaluate(policyCandidate($overrides), DiscoverySearchCriteria::fromConfig());
}

it('accepts every relevant title family and stack-prefixed title', function (string $title) {
    expect(policyDecision(['title' => $title])->accepted)->toBeTrue();
})->with([
    'Software Engineer', 'Software Developer', 'Senior Software Developer', 'Web Developer', 'Web Engineer',
    'Application Developer', 'Application Engineer', 'Full Stack Engineer', 'Full-Stack Developer', 'Fullstack Engineer',
    'FULLSTACK DEVELOPER', 'Backend Engineer', 'Back-End Developer', 'Back End Engineer', 'Frontend Developer',
    'Front End Engineer', 'Front-End Developer', 'Product Engineer', 'Product Developer', 'Integration Engineer',
    'Integration Developer', 'API Engineer', 'API Developer', 'PHP Developer', 'Senior PHP Engineer', 'Laravel Developer',
    'Laravel Engineer', 'React Developer', 'React Engineer', 'TypeScript Developer', 'TypeScript Engineer',
    'Node.js Developer', 'NodeJS Engineer', 'Node Developer', 'JavaScript Developer', 'JavaScript Engineer',
    'Staff Software Engineer', 'Principal Software Engineer', 'Lead Backend Developer', 'Senior Staff Frontend Engineer',
    'Software Development Engineer', 'Software Engineer II', 'Senior Software Engineer, Platform (Remote)',
    'PHP/Laravel Developer', 'Staff Engineer, Backend Developer',
]);

it('does not accept generic or unrelated engineer titles', function (string $title) {
    $decision = policyDecision(['title' => $title]);

    expect($decision->accepted)->toBeFalse()
        ->and($decision->reason)->toBe(DiscoveryRejectionReason::TitleNotMatching);
})->with([
    'Engineer', 'Senior Engineer', 'Staff Engineer', 'Principal Engineer', 'Engineering Manager', 'Solutions Architect',
    'Support Specialist', 'Customer Support Manager', 'Technical Support Engineering Manager', 'Sales Engineer', 'Engineer, Sales', 'Developer Advocate', 'Product Manager', 'Software Engineering Manager',
    'Production Engineer', 'Webmaster', 'Reactor Engineer', 'Senior Account Executive',
]);

it('rejects excluded role identities by title', function (string $title, string $term) {
    $decision = policyDecision(['title' => $title]);

    expect($decision->accepted)->toBeFalse()
        ->and($decision->reason)->toBe(DiscoveryRejectionReason::ExcludedTitleRole)
        ->and($decision->matchedTerm)->toBe($term)
        ->and($decision->matchedField)->toBe('title');
})->with([
    ['Principal Software Engineer, DevOps', 'devops'],
    ['DevOps Engineer', 'devops'],
    ['Site Reliability Engineer', 'site reliability'],
    ['Senior SRE', 'sre'],
    ['Firmware Software Engineer', 'firmware'],
    ['Compiler Software Engineer', 'compiler'],
]);

it('rejects junior and internship titles but not senior roles that mention junior staff', function (string $title, string $description, bool $accepted) {
    $decision = policyDecision(['title' => $title, 'description' => $description]);

    expect($decision->accepted)->toBe($accepted);
    if (! $accepted) {
        expect($decision->reason)->toBe(DiscoveryRejectionReason::ExcludedSeniority);
    }
})->with([
    'junior title' => ['Junior Software Engineer', 'Learn on the job.', false],
    'jr title' => ['Jr. Backend Developer', 'Learn on the job.', false],
    'internship title' => ['Software Engineering Internship', 'Summer program.', false],
    'intern title' => ['Software Engineer Intern', 'Summer program.', false],
    'entry level title' => ['Entry-Level Full Stack Developer', 'Learn on the job.', false],
    'new grad title' => ['New Grad Software Engineer', 'Learn on the job.', false],
    'senior mentoring juniors' => ['Senior Software Engineer', 'You will mentor junior developers and review their code. Entry level engineers join quarterly.', true],
    'full stack with interns' => ['Full Stack Developer', 'Work alongside our interns and apprentices.', true],
    'staff who coaches' => ['Staff Backend Engineer', 'Coach junior and mid-level engineers; no entry-level work.', true],
    'internal tools is not intern' => ['Software Engineer, Internal Tools', 'Build internal platforms.', true],
]);

it('keeps senior, staff, principal and lead individual-contributor titles', function (string $title) {
    expect(policyDecision(['title' => $title])->accepted)->toBeTrue();
})->with(['Senior Software Engineer', 'Staff Software Engineer', 'Principal Software Engineer', 'Lead Software Engineer', 'Tech Lead, Backend Developer']);

it('applies the remote and location policy', function (?JobRemoteStatus $status, ?string $location, bool $accepted, ?DiscoveryRejectionReason $reason) {
    $decision = policyDecision(['remoteStatus' => $status, 'location' => $location]);

    expect($decision->accepted)->toBe($accepted)
        ->and($decision->reason)->toBe($reason);
})->with([
    'US remote' => [JobRemoteStatus::Remote, 'USA', true, null],
    'US remote, no location' => [JobRemoteStatus::Remote, null, true, null],
    'remote, anywhere' => [JobRemoteStatus::Remote, 'Anywhere', true, null],
    'remote, listed countries including USA' => [JobRemoteStatus::Remote, 'Canada,  USA', true, null],
    'remote, state-listed' => [JobRemoteStatus::Remote, 'Texas, US', true, null],
    'remote, non-US only' => [JobRemoteStatus::Remote, 'Germany', false, DiscoveryRejectionReason::LocationNotAllowed],
    'remote, EMEA and LATAM only' => [JobRemoteStatus::Remote, 'EMEA, LATAM', false, DiscoveryRejectionReason::LocationNotAllowed],
    'unknown status, no location' => [null, null, true, null],
    'unknown status, bare US' => [null, 'US', true, null],
    'unknown status, Adzuna state and country' => [null, 'West Virginia, US', true, null],
    'unknown status, Florida city' => [null, 'Miami, FL', true, null],
    'unknown status, bare city' => [null, 'Austin', true, null],
    'unknown status, non-US' => [null, 'Berlin, Germany', false, DiscoveryRejectionReason::LocationNotAllowed],
    'unknown status, remote Canada' => [null, 'Remote (Canada)', false, DiscoveryRejectionReason::LocationNotAllowed],
    'hybrid in Florida' => [JobRemoteStatus::Hybrid, 'Miami, Florida', true, null],
    'hybrid in Florida, abbreviated' => [JobRemoteStatus::Hybrid, 'Orlando, FL, US', true, null],
    'hybrid, no location' => [JobRemoteStatus::Hybrid, null, true, null],
    'hybrid outside Florida' => [JobRemoteStatus::Hybrid, 'Austin, TX', false, DiscoveryRejectionReason::WorkArrangementNotAllowed],
    'hybrid, bare US' => [JobRemoteStatus::Hybrid, 'US', false, DiscoveryRejectionReason::WorkArrangementNotAllowed],
    'hybrid in a state that merely contains letters of FL' => [JobRemoteStatus::Hybrid, 'Flagstaff, AZ', false, DiscoveryRejectionReason::WorkArrangementNotAllowed],
    'on-site in Florida' => [JobRemoteStatus::Onsite, 'Tampa, FL', false, DiscoveryRejectionReason::WorkArrangementNotAllowed],
    'on-site elsewhere' => [JobRemoteStatus::Onsite, 'Chicago, IL', false, DiscoveryRejectionReason::WorkArrangementNotAllowed],
    'on-site, no location' => [JobRemoteStatus::Onsite, null, false, DiscoveryRejectionReason::WorkArrangementNotAllowed],
]);

it('does not read a bare US or state location as proof of remote work', function () {
    $job = policyCandidate(['remoteStatus' => null, 'location' => 'West Virginia, US', 'source' => JobDiscoverySource::Adzuna]);

    expect($job->remoteStatus)->toBeNull()
        ->and((new FilterDiscoveredCandidates)->evaluate($job, DiscoverySearchCriteria::fromConfig())->accepted)->toBeTrue();
});

it('records the rule, field and bounded evidence behind a rejection', function () {
    $decision = policyDecision(['remoteStatus' => JobRemoteStatus::Hybrid, 'location' => str_repeat('Chicago, IL; ', 60)]);

    expect($decision->reason)->toBe(DiscoveryRejectionReason::WorkArrangementNotAllowed)
        ->and($decision->matchedTerm)->toBe('hybrid')
        ->and($decision->matchedField)->toBe('remote_status')
        ->and(mb_strlen($decision->evidence))->toBeLessThanOrEqual(240);

    $title = policyDecision(['title' => 'Marketing Manager']);
    expect($title->reason)->toBe(DiscoveryRejectionReason::TitleNotMatching)->and($title->matchedField)->toBe('title')->and($title->evidence)->toBe('Marketing Manager');
});

it('applies one primary reason in a fixed precedence', function () {
    // An excluded role outranks seniority, seniority outranks a title mismatch, and all three outrank workplace.
    expect(policyDecision(['title' => 'Junior Marketing Manager', 'remoteStatus' => JobRemoteStatus::Onsite])->reason)->toBe(DiscoveryRejectionReason::ExcludedSeniority)
        ->and(policyDecision(['title' => 'Marketing Manager', 'remoteStatus' => JobRemoteStatus::Onsite])->reason)->toBe(DiscoveryRejectionReason::TitleNotMatching)
        ->and(policyDecision(['title' => 'Junior DevOps Engineer: Software Engineer', 'remoteStatus' => JobRemoteStatus::Onsite])->reason)->toBe(DiscoveryRejectionReason::ExcludedTitleRole)
        ->and(policyDecision(['title' => 'Junior Software Engineer', 'remoteStatus' => JobRemoteStatus::Onsite])->reason)->toBe(DiscoveryRejectionReason::ExcludedSeniority);
});

it('keeps accepts() consistent with evaluate()', function () {
    $filter = new FilterDiscoveredCandidates;
    $criteria = DiscoverySearchCriteria::fromConfig();

    foreach (['PHP Developer', 'DevOps Engineer', 'Product Manager'] as $title) {
        $job = policyCandidate(['title' => $title]);
        expect($filter->accepts($job, $criteria))->toBe($filter->evaluate($job, $criteria)->accepted);
    }
});

it('recognizes plural specializations and the role-first integrations form', function (string $title) {
    expect(policyDecision(['title' => $title])->accepted)->toBeTrue();
})->with(['Integrations Engineer', 'Senior Integrations Developer', 'APIs Engineer', 'API Engineer', 'Developer, Integrations', 'Engineer, APIs', 'Integration Engineer']);

it('accepts the explicit developer-facing support and solutions roles, and nothing generic around them', function (string $title, bool $accepted) {
    expect(policyDecision(['title' => $title])->accepted)->toBe($accepted);
})->with([
    ['Developer Support Engineer', true],
    ['API Support Engineer', true],
    ['Technical Support Engineer', true],
    ['Senior Technical Support Engineer', true],
    ['Solutions Engineer', true],
    ['Technical-Support Engineer', true],
    ['Support Engineer', false],
    ['Customer Engineer', false],
    ['Support Developer Advocate', false],
    ['Solutions Architect', false],
    ['Technical Support Engineering Manager', false],
]);

it('keeps the exclusions in force for the newly included roles', function (string $title, DiscoveryRejectionReason $reason) {
    expect(policyDecision(['title' => $title])->reason)->toBe($reason);
})->with([
    ['DevOps Solutions Engineer', DiscoveryRejectionReason::ExcludedTitleRole],
    ['Junior Technical Support Engineer', DiscoveryRejectionReason::ExcludedSeniority],
    ['Solutions Engineer Intern', DiscoveryRejectionReason::ExcludedSeniority],
    ['Firmware Support Engineer', DiscoveryRejectionReason::ExcludedTitleRole],
]);

it('reads New Mexico as a US state and still rejects Mexico', function (string $location, bool $accepted) {
    expect(policyDecision(['location' => $location])->accepted)->toBe($accepted);
})->with([
    ['Remote, New Mexico', true],
    ['Albuquerque, New Mexico', true],
    ['New  Mexico', true],
    ['Mexico', false],
    ['Remote - Mexico', false],
    ['Mexico City, Mexico', false],
    ['New Mexico or Mexico', false],
]);

it('keeps LocationSignals consistent for qualification: New Mexico is not a non-US place', function () {
    expect(LocationSignals::mentionsNonUsPlace('Santa Fe, New Mexico'))->toBeFalse()
        ->and(LocationSignals::mentionsNonUsPlace('Monterrey, Mexico'))->toBeTrue()
        ->and(LocationSignals::mentionsNonUsPlace('Toronto, Canada'))->toBeTrue();
});

it('accepts Florida hybrid roles with or without a comma and ZIP code, and still rejects other hybrid states', function (string $location, bool $accepted) {
    expect(policyDecision(['location' => $location, 'remoteStatus' => JobRemoteStatus::Hybrid])->accepted)->toBe($accepted);
})->with([
    ['Tampa FL 33602', true],
    ['Orlando, FL 32801', true],
    ['Orlando, FL 32801-1234', true],
    ['Miami, Florida', true],
    ['Tampa, TX 33602', false],
    ['Austin TX 78701', false],
    ['FLX 33602', false],
]);

it('keeps rejecting hybrid roles that list only the United States', function (string $location) {
    $decision = policyDecision(['location' => $location, 'remoteStatus' => JobRemoteStatus::Hybrid]);

    expect($decision->accepted)->toBeFalse()
        ->and($decision->reason)->toBe(DiscoveryRejectionReason::WorkArrangementNotAllowed)
        ->and($decision->matchedTerm)->toBe('hybrid');
})->with(['United States', 'USA', 'US']);

it('lets an Eightfold candidate pass on complete location signals even when the shown text is truncated', function () {
    $shown = 'Toronto, Canada; London, United Kingdom; Berlin, Germany; Paris, France; Madrid, Spain (+2 more)';

    // Hybrid with Florida beyond the fifth listed location.
    expect(policyDecision(['source' => JobDiscoverySource::Eightfold, 'location' => 'Austin, TX, US; Dallas, TX, US; Denver, CO, US; Boston, MA, US; Seattle, WA, US (+2 more)', 'remoteStatus' => JobRemoteStatus::Hybrid, 'sourceMetadata' => ['floridaListed' => true]])->accepted)->toBeTrue()
        ->and(policyDecision(['source' => JobDiscoverySource::Eightfold, 'location' => 'Austin, TX, US; Dallas, TX, US; Denver, CO, US; Boston, MA, US; Seattle, WA, US (+2 more)', 'remoteStatus' => JobRemoteStatus::Hybrid, 'sourceMetadata' => ['floridaListed' => false]])->accepted)->toBeFalse()
        // Remote with a US location listed only after five non-US ones.
        ->and(policyDecision(['source' => JobDiscoverySource::Eightfold, 'location' => $shown, 'sourceMetadata' => ['usLocationListed' => true]])->accepted)->toBeTrue()
        ->and(policyDecision(['source' => JobDiscoverySource::Eightfold, 'location' => $shown, 'sourceMetadata' => ['usLocationListed' => false]])->accepted)->toBeFalse();
});

it('matches plural engineers and developers wherever the singular title qualifies', function (string $title) {
    expect(policyDecision(['title' => $title])->accepted)->toBeTrue();
})->with([
    'Principal + Staff Software Engineers', 'Software Engineers', 'Senior Software Developers', 'Platform Engineers',
    'Data Engineers', 'API Engineers', 'Full-Stack Engineers', 'Fullstack Developers', 'Backend Engineers',
    'PHP Developers', 'Laravel Engineers', 'Integrations Engineers', 'Solutions Engineers', 'Technical Support Engineers',
    // Singular behavior is unchanged.
    'Software Engineer', 'Platform Engineer', 'Solutions Engineer',
]);

it('does not turn plurals into generic engineer or developer matches', function (string $title) {
    $decision = policyDecision(['title' => $title]);

    expect($decision->accepted)->toBeFalse()
        ->and($decision->reason)->toBe(DiscoveryRejectionReason::TitleNotMatching);
})->with(['Engineers', 'Developers', 'Senior Engineers', 'Staff Engineers', 'Sales Engineers', 'Python Developers', 'Software Engineering Managers', 'Support Engineers', 'Engineering Managers']);

it('keeps the exclusions in force for plural titles', function (string $title, DiscoveryRejectionReason $reason) {
    expect(policyDecision(['title' => $title])->reason)->toBe($reason);
})->with([
    ['DevOps Engineers', DiscoveryRejectionReason::ExcludedTitleRole],
    ['Site Reliability Engineers', DiscoveryRejectionReason::ExcludedTitleRole],
    ['Firmware Software Engineers', DiscoveryRejectionReason::ExcludedTitleRole],
    ['Compiler Engineers', DiscoveryRejectionReason::ExcludedTitleRole],
    ['Junior Software Engineers', DiscoveryRejectionReason::ExcludedSeniority],
    ['Junior Backend Developers', DiscoveryRejectionReason::ExcludedSeniority],
]);
