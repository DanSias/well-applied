<?php

use App\Enums\DescriptionCompleteness;
use App\Enums\DiscoveryRejectionReason;
use App\Enums\JobDiscoverySource;
use App\Enums\JobRemoteStatus;
use App\Support\JobDiscovery\DiscoveredJobCandidate;
use App\Support\JobDiscovery\DiscoverySearchCriteria;
use App\Support\JobDiscovery\FilterDiscoveredCandidates;
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
    'Technical Support Engineer', 'Sales Engineer', 'Developer Advocate', 'Product Manager', 'Software Engineering Manager',
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
