<?php

use App\Enums\DescriptionCompleteness;
use App\Enums\JobRemoteStatus;
use App\Enums\QualificationEligibility as E;
use App\Models\JobPosting;
use App\Support\JobQualification\AssessEligibility;
use Tests\Support\QualificationFixtures as Q;

/**
 * The deterministic gate. Only explicit, reliable evidence rejects; anything unclear is "unknown", a thin posting is
 * "insufficient information", and every outcome keeps the field or sentence it rests on.
 */
function eligibilityOf(array $attributes = [], string $description = ''): array
{
    $job = JobPosting::factory()->make(array_merge([
        'location' => 'Remote, United States', 'remote_status' => JobRemoteStatus::Remote,
        'description_completeness' => DescriptionCompleteness::Complete,
        'description' => $description === '' ? Q::laravel() : $description,
    ], $attributes));

    $assessment = (new AssessEligibility)->assess($job);

    return [$assessment->outcome, array_column($assessment->reasons, 'code'), $assessment];
}

it('finds a remote US posting eligible', function () {
    [$outcome, $codes] = eligibilityOf();

    expect($outcome)->toBe(E::Eligible)->and($codes)->toBe([]);
});

it('rejects an on-site role outside Florida, with the field it rests on', function () {
    [$outcome, $codes, $assessment] = eligibilityOf(['remote_status' => JobRemoteStatus::Onsite, 'location' => 'Austin, Texas']);

    expect($outcome)->toBe(E::Ineligible)->and($codes)->toBe(['workplace_outside_home_state'])
        ->and($assessment->reasons[0]['evidence'])->toBe('remote_status: onsite; location: Austin, Texas');
});

it('rejects a hybrid role outside Florida', function () {
    [$outcome] = eligibilityOf(['remote_status' => JobRemoteStatus::Hybrid, 'location' => 'Seattle, WA']);

    expect($outcome)->toBe(E::Ineligible);
});

it('does not reject an on-site role in Florida or one with no location: those are unknown', function () {
    expect(eligibilityOf(['remote_status' => JobRemoteStatus::Onsite, 'location' => 'Miami, FL'])[0])->toBe(E::Unknown)
        ->and(eligibilityOf(['remote_status' => JobRemoteStatus::Hybrid, 'location' => 'Tampa, Florida'])[0])->toBe(E::Unknown)
        ->and(eligibilityOf(['remote_status' => JobRemoteStatus::Onsite, 'location' => null])[0])->toBe(E::Unknown);
});

it('rejects a role the posting itself restricts to a place outside the US, quoting the sentence', function () {
    $description = Q::laravel().' Candidates must be located in Canada to be considered for this position.';
    [$outcome, $codes, $assessment] = eligibilityOf([], $description);

    expect($outcome)->toBe(E::Ineligible)->and($codes)->toBe(['location_restricted_outside_us'])
        ->and($assessment->reasons[0]['evidence'])->toContain('must be located in Canada');
});

it('rejects a role restricted to states that do not include Florida', function () {
    [$outcome, $codes] = eligibilityOf([], Q::laravel().' We are only hiring in California, Texas and New York at this time.');

    expect($outcome)->toBe(E::Ineligible)->and($codes)->toBe(['location_restricted_to_other_states']);
});

it('does not reject on a residency sentence that also welcomes the US or Florida', function () {
    expect(eligibilityOf([], Q::laravel().' Candidates must be located in the United States or Canada.')[0])->toBe(E::Eligible)
        ->and(eligibilityOf([], Q::laravel().' We are hiring in Florida, Texas and Georgia.')[0])->toBe(E::Eligible);
});

it('does not read the pronoun "us" as the country, and does not treat a team description as a restriction', function () {
    expect(eligibilityOf([], Q::laravel().' Candidates must live in Germany to join us.')[0])->toBe(E::Ineligible)
        ->and(eligibilityOf([], Q::laravel().' Our engineers are based in Germany and Canada.')[0])->toBe(E::Eligible);
});

it('treats an unstated or ambiguous remote status as unknown, never a rejection', function () {
    [$outcome, $codes] = eligibilityOf(['remote_status' => null, 'location' => null]);
    [$outcomeWithLocation] = eligibilityOf(['remote_status' => null, 'location' => 'Denver']);
    [$remoteElsewhere, $remoteCodes] = eligibilityOf(['remote_status' => JobRemoteStatus::Remote, 'location' => 'Germany']);

    expect($outcome)->toBe(E::Unknown)->and($codes)->toBe(['remote_status_unknown'])
        ->and($outcomeWithLocation)->toBe(E::Unknown)
        ->and($remoteElsewhere)->toBe(E::Unknown)->and($remoteCodes)->toBe(['remote_region_unclear']);
});

it('accepts an unstated remote status when the location itself says US remote', function () {
    [$outcome] = eligibilityOf(['remote_status' => null, 'location' => 'Remote - US']);

    expect($outcome)->toBe(E::Eligible);
});

it('rejects a required security clearance the profile does not substantiate', function () {
    [$outcome, $codes, $assessment] = eligibilityOf([], Q::laravel().' An active Top Secret security clearance is required.');

    expect($outcome)->toBe(E::Ineligible)->and($codes)->toBe(['clearance_required'])
        ->and($assessment->reasons[0]['evidence'])->toContain('Top Secret');
});

it('does not reject on a clearance the profile mentions, a preferred one, or one that can be obtained', function () {
    $required = Q::laravel().' An active Secret clearance is required.';
    $withEvidence = (new AssessEligibility)->assess(JobPosting::factory()->make(['location' => 'Remote, United States', 'remote_status' => JobRemoteStatus::Remote, 'description_completeness' => DescriptionCompleteness::Complete, 'description' => $required]), true);

    expect($withEvidence->outcome)->toBe(E::Unknown)
        ->and(eligibilityOf([], Q::laravel().' A security clearance is a plus.')[0])->toBe(E::Eligible)
        ->and(eligibilityOf([], Q::laravel().' You must be eligible to obtain a security clearance.')[0])->toBe(E::Unknown);
});

it('reports insufficient information for a preview-only or very short description', function () {
    [$preview, $previewCodes] = eligibilityOf(['description_completeness' => DescriptionCompleteness::Preview]);
    [$short, $shortCodes] = eligibilityOf([], 'Build things in Laravel.');

    expect($preview)->toBe(E::InsufficientInformation)->and($previewCodes)->toBe(['preview_only'])
        ->and($short)->toBe(E::InsufficientInformation)->and($shortCodes)->toBe(['description_too_short']);
});

it('keeps explicit structured evidence even when the description is thin', function () {
    [$outcome, $codes] = eligibilityOf(['remote_status' => JobRemoteStatus::Onsite, 'location' => 'Boston, MA'], 'Short.');

    expect($outcome)->toBe(E::Ineligible)->and($codes)->toContain('workplace_outside_home_state', 'description_too_short');
});

it('only asks a model about eligible or unclear jobs', function () {
    expect(E::Eligible->allowsModel())->toBeTrue()->and(E::Unknown->allowsModel())->toBeTrue()
        ->and(E::Ineligible->allowsModel())->toBeFalse()->and(E::InsufficientInformation->allowsModel())->toBeFalse();
});
