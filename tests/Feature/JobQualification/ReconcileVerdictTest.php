<?php

use App\Enums\QualificationEligibility as E;
use App\Enums\QualificationVerdict as V;
use App\Support\JobQualification\ReconcileVerdict;
use Tests\Support\QualificationFixtures as Q;

/**
 * The policy as a contract: what a model's structured facts allow the verdict to be, whatever verdict it proposed.
 */
function reconciled(string $modelVerdict, array $technologies = [], array $answer = [], E $eligibility = E::Eligible, array $skills = ['Laravel', 'PHP'], array $segments = []): array
{
    return (new ReconcileVerdict)->reconcile(
        Q::answer(['verdict' => $modelVerdict, 'technologies' => $technologies] + $answer),
        $eligibility,
        $skills,
        $segments,
    );
}

/** Posting sentences that really describe people management, and ones that merely describe a senior engineer. */
const MANAGES_PEOPLE = ['S001' => 'You will manage a team of six engineers, run their performance reviews and own hiring.'];
const LEADS_TECHNICALLY = ['S001' => 'Mentor engineers, lead ambiguous technical design and own the architecture of our backend services.'];

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
    expect(reconciled('poor', [Q::tech('Laravel', 'mandatory', 'familiar')], $answer, segments: MANAGES_PEOPLE)['verdict'])->toBe(V::Poor);
})->with([
    'a non-engineering role' => [['role_type' => 'non_engineering']],
    'primarily management' => [['hands_on' => 'primarily_management', 'management_evidence_refs' => ['S001']]],
    'an infrastructure-focused role' => [['role_type' => 'infrastructure_devops']],
]);

it('forces a non-engineering role to poor', function () {
    expect(reconciled('good', [], ['role_type' => 'non_engineering'])['verdict'])->toBe(V::Poor);
});

it('caps an infrastructure-focused or management-focused role at needs_review', function (array $answer, string $code) {
    $result = reconciled('strong', [Q::tech('Laravel', 'mandatory', 'familiar')], $answer, segments: MANAGES_PEOPLE);

    expect($result['verdict'])->toBe(V::NeedsReview)->and($result['adjustments'])->toContain($code);
})->with([
    'infrastructure' => [['role_type' => 'infrastructure_devops'], 'infrastructure_focus_capped'],
    'management' => [['hands_on' => 'primarily_management', 'management_evidence_refs' => ['S001']], 'primarily_management_capped'],
    'engineering management' => [['role_type' => 'engineering_management', 'management_evidence_refs' => ['S001']], 'primarily_management_capped'],
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

/*
 * Regressions from the first real-model calibration: hands-on engineers labelled "primarily management", and an incidental
 * clause treated as a mandatory unfamiliar stack.
 */
it('does not cap a hands-on senior, staff or principal engineer labelled primarily management without evidence', function (string $verdict, V $expected) {
    // The model's label is wrong and it cited nothing: mentoring and technical leadership are not management.
    $result = reconciled($verdict, [Q::tech('Laravel', 'mandatory', 'familiar')], ['hands_on' => 'primarily_management', 'management_evidence_refs' => []], segments: LEADS_TECHNICALLY);

    expect($result['verdict'])->toBe($expected)->and($result['adjustments'])->toContain('management_claim_unsupported')->and($result['adjustments'])->not->toContain('primarily_management_capped');
})->with([
    'good stays good' => ['good', V::Good],
    'strong is held at good, since hands-on level is now unclear' => ['strong', V::Good],
]);

it('sets aside a management claim whose cited sentence only describes technical leadership', function (string $roleType, string $handsOn) {
    $result = reconciled('good', [Q::tech('Laravel', 'mandatory', 'familiar')], ['role_type' => $roleType, 'hands_on' => $handsOn, 'management_evidence_refs' => ['S001']], segments: LEADS_TECHNICALLY);

    expect($result['verdict'])->toBe(V::Good)->and($result['adjustments'])->toBe(['management_claim_unsupported']);
})->with([
    'a hands-on label from a titled-senior engineer' => ['product_engineering', 'primarily_management'],
    'an engineering-management type with no managing in the posting' => ['engineering_management', 'hands_on'],
]);

it('still caps genuine engineering management when the posting states people-management duties', function () {
    $result = reconciled('good', [Q::tech('Python', 'mandatory', 'familiar')], ['role_type' => 'engineering_management', 'hands_on' => 'hands_on', 'management_evidence_refs' => ['S001']], segments: MANAGES_PEOPLE);

    expect($result['verdict'])->toBe(V::NeedsReview)->and($result['adjustments'])->toBe(['primarily_management_capped']);
});

it('recognises concrete people-management sentences and not mere mentoring or leading projects', function (string $sentence, bool $supports) {
    $result = reconciled('good', [], ['hands_on' => 'primarily_management', 'management_evidence_refs' => ['S001']], segments: ['S001' => $sentence]);

    expect(in_array('management_claim_unsupported', $result['adjustments'], true))->toBe(! $supports);
})->with([
    ['Build and lead a team of engineers in your region.', true],
    ['You will have five direct reports and own their career growth.', true],
    ['Lead and grow a team responsible for developer tooling.', true],
    ['As an engineering manager you must run an effective team.', true],
    ['Own hiring, headcount and performance reviews for the group.', true],
    ['Mentor and grow web engineers across the organization.', false],
    ['Lead projects end-to-end, balancing hands-on development with guiding others.', false],
    ['Demonstrated ability to lead through influence and mentor engineers.', false],
    ['Provide technical leadership as a senior individual contributor.', false],
    ['We are hiring a Staff Backend Engineer who can lead technical design.', false],
]);

it('treats a mixed engineering and management role as neither capped nor strong-blocked by management', function () {
    $result = reconciled('good', [Q::tech('Go', 'mandatory', 'familiar')], ['hands_on' => 'mixed', 'management_evidence_refs' => ['S001']], segments: MANAGES_PEOPLE);

    expect($result['verdict'])->toBe(V::Good)->and($result['adjustments'])->toBe([]);
});

it('ignores a mandatory unfamiliar technology that is not central to the role', function (string $depth) {
    // "Implement caching strategies at the edge" inside a list of duties, tagged mandatory by the model.
    $result = reconciled('strong', [Q::tech('React', 'mandatory', 'familiar'), Q::tech('Edge caching', 'mandatory', 'unfamiliar', $depth, central: false)]);

    expect($result['verdict'])->toBe(V::Strong)->and($result['adjustments'])->toBe([]);
})->with(['working', 'deep_production', 'unstated']);

it('still rejects a central, mandatory, deep unfamiliar stack, and caps a central working-level one', function () {
    $deep = reconciled('strong', [Q::tech('Ruby on Rails', 'mandatory', 'unfamiliar', 'deep_production', central: true)]);
    $working = reconciled('strong', [Q::tech('Go', 'mandatory', 'unfamiliar', 'working', central: true)]);

    expect($deep['verdict'])->toBe(V::Poor)->and($working['verdict'])->toBe(V::NeedsReview);
});

it('keeps a flexible unfamiliar central stack in review-to-good range, and a preferred one irrelevant', function () {
    $flexible = reconciled('poor', [Q::tech('Ruby on Rails', 'mandatory', 'unfamiliar', 'deep_production', transferable: true, central: true)]);
    $preferred = reconciled('good', [Q::tech('Laravel', 'mandatory', 'familiar'), Q::tech('Python', 'preferred', 'unfamiliar', 'deep_production', central: true)]);

    expect($flexible['verdict'])->toBe(V::NeedsReview)->and($preferred['verdict'])->toBe(V::Good)->and($preferred['adjustments'])->toBe([]);
});

/*
 * Downstream decisions read the reconciled role, not the raw label. An engineering_management label that was set aside as
 * unsupported must not keep blocking Strong, and being set aside is not itself a promotion.
 */
const STACK = ['S001' => 'You will own our React and TypeScript application end to end.'];

it('lets a hands-on application role reach Strong after an unsupported management role label is set aside', function (array $segments) {
    // The model said engineering_management, cited nothing that describes managing people, and described a hands-on job on a stack the candidate has.
    $result = reconciled('strong', [Q::tech('React', 'mandatory', 'familiar')], ['role_type' => 'engineering_management', 'hands_on' => 'hands_on', 'management_evidence_refs' => ['S001']], skills: ['React'], segments: $segments);

    expect($result['verdict'])->toBe(V::Strong)->and($result['adjustments'])->toBe(['management_claim_unsupported']);
})->with([
    'nothing cited that exists' => [[]],
    'a sentence about technical leadership' => [LEADS_TECHNICALLY],
    'a sentence about the stack' => [STACK],
]);

it('keeps supported engineering management capped, even when the model proposed Strong', function () {
    $result = reconciled('strong', [Q::tech('React', 'mandatory', 'familiar')], ['role_type' => 'engineering_management', 'hands_on' => 'hands_on', 'management_evidence_refs' => ['S001']], segments: MANAGES_PEOPLE);

    expect($result['verdict'])->toBe(V::NeedsReview)->and($result['adjustments'])->toBe(['primarily_management_capped']);
});

it('does not promote an unclear role to Strong just because the management claim was unsupported', function (array $answer, array $technologies, string $why) {
    $result = reconciled('strong', $technologies, ['role_type' => 'engineering_management', 'management_evidence_refs' => []] + $answer);

    expect($result['verdict'])->toBe(V::Good, $why)->and($result['adjustments'])->toBe(['management_claim_unsupported', 'strong_not_supported']);
})->with([
    'hands-on level unclear' => [['hands_on' => 'unclear'], [Q::tech('React', 'mandatory', 'familiar')], 'unclear hands-on level'],
    'mixed hands-on and management' => [['hands_on' => 'mixed'], [Q::tech('React', 'mandatory', 'familiar')], 'mixed role'],
    'nothing central that the candidate has' => [['hands_on' => 'hands_on'], [], 'no evidence of an application stack'],
    'only an incidental familiar technology' => [['hands_on' => 'hands_on'], [Q::tech('React', 'incidental', 'familiar')], 'incidental stack only'],
    'a central stack that is only preferred' => [['hands_on' => 'hands_on'], [Q::tech('React', 'preferred', 'familiar')], 'preferred stack only'],
]);

it('still applies every other Strong condition after an unsupported management label is set aside', function (E $eligibility, array $technologies) {
    $result = reconciled('strong', $technologies, ['role_type' => 'engineering_management', 'hands_on' => 'hands_on', 'management_evidence_refs' => []], $eligibility);

    expect($result['verdict'])->not->toBe(V::Strong);
})->with([
    'unclear eligibility' => [E::Unknown, [Q::tech('React', 'mandatory', 'familiar')]],
    'a central mandatory unfamiliar stack' => [E::Eligible, [Q::tech('React', 'mandatory', 'familiar'), Q::tech('Go', 'mandatory', 'unfamiliar', 'working')]],
]);

it('keeps a hands-on engineer with technical leadership Strong, whatever the mentoring language', function () {
    $result = reconciled('strong', [Q::tech('Laravel', 'mandatory', 'familiar')], ['role_type' => 'backend', 'hands_on' => 'hands_on', 'management_evidence_refs' => []], segments: LEADS_TECHNICALLY);

    expect($result['verdict'])->toBe(V::Strong)->and($result['adjustments'])->toBe([]);
});

it('does not let a raw role label that was set aside influence anything else', function () {
    // Set aside: no cap, no poor floor effects, and the unsupported-claim record is the only trace.
    $result = reconciled('good', [Q::tech('Laravel', 'mandatory', 'familiar')], ['role_type' => 'engineering_management', 'hands_on' => 'hands_on', 'management_evidence_refs' => []]);

    expect($result['verdict'])->toBe(V::Good)->and($result['adjustments'])->toBe(['management_claim_unsupported']);
});
