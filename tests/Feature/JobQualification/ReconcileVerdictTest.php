<?php

use App\Enums\QualificationEligibility as E;
use App\Enums\QualificationVerdict as V;
use App\Support\JobQualification\ReconcileVerdict;
use Tests\Support\QualificationFixtures as Q;

/**
 * The policy as a contract: what a model's structured facts allow the verdict to be, whatever verdict it proposed.
 */
function reconciled(string $modelVerdict, array $technologies = [], array $answer = [], E $eligibility = E::Eligible, array $skills = ['Laravel', 'PHP']): array
{
    return (new ReconcileVerdict)->reconcile(
        Q::answer(['verdict' => $modelVerdict, 'technologies' => $technologies] + $answer),
        $eligibility,
        $skills,
    );
}

it('makes an unfamiliar mandatory stack that needs deep production experience a poor match, whatever the model said', function (string $proposed) {
    $result = reconciled($proposed, [Q::tech('Ruby on Rails', 'mandatory', 'unfamiliar', 'deep_production')]);

    expect($result['verdict'])->toBe(V::Poor)->and($result['adjustments'])->toContain('deep_unfamiliar_primary_stack');
})->with(['strong', 'good', 'needs_review', 'poor']);

it('keeps a mandatory unfamiliar stack in review-to-good range when equivalents are explicitly welcome', function (string $proposed, V $expected) {
    $result = reconciled($proposed, [Q::tech('Ruby on Rails', 'mandatory', 'unfamiliar', 'deep_production', transferable: true)]);

    expect($result['verdict'])->toBe($expected);
})->with([
    'strong is capped to good' => ['strong', V::Good],
    'good stays' => ['good', V::Good],
    'needs review stays' => ['needs_review', V::NeedsReview],
    'poor is raised to review' => ['poor', V::NeedsReview],
]);

it('never lets a preferred or incidental unfamiliar technology lower a verdict', function (string $requirement) {
    $result = reconciled('good', [Q::tech('Laravel', 'mandatory', 'familiar'), Q::tech('Python', $requirement, 'unfamiliar', 'deep_production')]);

    expect($result['verdict'])->toBe(V::Good)->and($result['adjustments'])->toBe([]);
})->with(['preferred', 'incidental']);

it('raises a poor verdict that has no structural reason to needs_review', function () {
    $result = reconciled('poor', [Q::tech('Laravel', 'mandatory', 'familiar'), Q::tech('Python', 'preferred', 'unfamiliar')]);

    expect($result['verdict'])->toBe(V::NeedsReview)->and($result['adjustments'])->toBe(['poor_without_structural_reason_raised']);
});

it('keeps a poor verdict that has a structural reason', function (array $answer) {
    expect(reconciled('poor', [Q::tech('Laravel', 'mandatory', 'familiar')], $answer)['verdict'])->toBe(V::Poor);
})->with([
    'a non-engineering role' => [['role_type' => 'non_engineering']],
    'primarily management' => [['hands_on' => 'primarily_management']],
    'an infrastructure-focused role' => [['role_type' => 'infrastructure_devops']],
]);

it('forces a non-engineering role to poor', function () {
    expect(reconciled('good', [], ['role_type' => 'non_engineering'])['verdict'])->toBe(V::Poor);
});

it('caps an infrastructure-focused or management-focused role at needs_review', function (array $answer, string $code) {
    $result = reconciled('strong', [Q::tech('Laravel', 'mandatory', 'familiar')], $answer);

    expect($result['verdict'])->toBe(V::NeedsReview)->and($result['adjustments'])->toContain($code);
})->with([
    'infrastructure' => [['role_type' => 'infrastructure_devops'], 'infrastructure_focus_capped'],
    'management' => [['hands_on' => 'primarily_management'], 'primarily_management_capped'],
    'engineering management' => [['role_type' => 'engineering_management'], 'primarily_management_capped'],
]);

it('caps an unfamiliar mandatory stack that is neither deep nor explicitly accepted at needs_review', function () {
    $result = reconciled('good', [Q::tech('Go', 'mandatory', 'unfamiliar', 'working')]);

    expect($result['verdict'])->toBe(V::NeedsReview)->and($result['adjustments'])->toContain('unfamiliar_mandatory_stack_capped');
});

it('reserves strong for a hands-on product-engineering role with nothing unfamiliar and clear eligibility', function () {
    expect(reconciled('strong', [Q::tech('Laravel', 'mandatory', 'familiar')])['verdict'])->toBe(V::Strong)
        ->and(reconciled('strong', [Q::tech('Laravel', 'mandatory', 'familiar')], eligibility: E::Unknown)['verdict'])->toBe(V::Good)
        ->and(reconciled('strong', [Q::tech('Laravel', 'mandatory', 'familiar')], ['role_type' => 'data_engineering'])['verdict'])->toBe(V::Good)
        ->and(reconciled('strong', [Q::tech('Laravel', 'mandatory', 'familiar')], ['hands_on' => 'unclear'])['verdict'])->toBe(V::Good);
});

it('corrects a technology the model called unfamiliar when it is one of the candidate\'s own skills', function () {
    $result = reconciled('good', [Q::tech('node.js', 'mandatory', 'unfamiliar', 'deep_production')], skills: ['Node.js']);

    expect($result['verdict'])->toBe(V::Good)
        ->and($result['technologies'][0]['familiarity'])->toBe('familiar')
        ->and($result['adjustments'])->toBe(['familiarity_corrected:node.js']);
});

it('does not invent familiarity for a technology that is not among the candidate\'s skills', function () {
    $result = reconciled('good', [Q::tech('Ruby on Rails', 'mandatory', 'unfamiliar', 'deep_production')], skills: ['Laravel']);

    expect($result['technologies'][0]['familiarity'])->toBe('unfamiliar')->and($result['verdict'])->toBe(V::Poor);
});
