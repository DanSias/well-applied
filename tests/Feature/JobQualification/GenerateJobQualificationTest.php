<?php

use App\Enums\DescriptionCompleteness;
use App\Enums\JobDisposition;
use App\Enums\JobRemoteStatus;
use App\Enums\QualificationEligibility as E;
use App\Enums\QualificationVerdict as V;
use App\Enums\Visibility;
use App\Exceptions\InvalidJobQualificationResponseException;
use App\Exceptions\JobQualificationProviderException;
use App\Models\CareerFact;
use App\Models\CareerProfile;
use App\Models\JobPosting;
use App\Models\JobQualification;
use App\Models\Skill;
use App\Support\JobQualification\CandidateCapabilitySummary;
use App\Support\JobQualification\GenerateJobQualification;
use App\Support\JobQualification\QualificationFreshness;
use App\Support\JobQualification\QualificationPolicy;
use Tests\Support\QualificationFixtures as Q;

/**
 * Representative postings run through the whole screening pipeline with a scripted model: the policy contract is asserted
 * on the stored verdict, not on whatever text a model produced.
 */
function qualify(JobPosting $job, bool $refresh = false): JobQualification
{
    return app(GenerateJobQualification::class)->generate($job, $refresh);
}

it('rates a strong PHP/Laravel application role strong, with source evidence and nothing adjusted', function () {
    $career = Q::candidate();
    $job = Q::job($career, Q::laravel());
    $fake = Q::fake()->willReturn(Q::answer(['technologies' => [Q::tech('Laravel', 'mandatory', 'familiar'), Q::tech('Vue', 'preferred', 'familiar')]]));

    $q = qualify($job);

    expect($q->verdict)->toBe(V::Strong)->and($q->eligibility)->toBe(E::Eligible)->and($q->adjustments)->toBe([])
        ->and($q->generated_by)->toBe('ollama:qwen-test')->and($q->prompt_version)->toBe('job-qualification-v1')->and($q->policy_version)->toBe(QualificationPolicy::VERSION)
        ->and($q->assessment['fit_reasons'][0]['evidence'][0])->toBe(['ref' => 'S001', 'excerpt' => 'We are hiring a Senior Software Engineer to build our customer platform.'])
        ->and($fake->callCount)->toBe(1);
});

it('rates a React-focused frontend role good or strong', function () {
    $career = Q::candidate();
    Q::fake()->willReturn(Q::answer(['role_type' => 'frontend', 'verdict' => 'good', 'technologies' => [Q::tech('React', 'mandatory', 'familiar'), Q::tech('TypeScript', 'mandatory', 'familiar')]]));

    expect(qualify(Q::job($career, Q::react()))->verdict)->toBe(V::Good);
});

it('does not let overlapping keywords make an infrastructure-heavy role a strong match', function () {
    $career = Q::candidate();
    // The model is fooled by TypeScript and Python in the description and says strong; the role is operations.
    Q::fake()->willReturn(Q::answer([
        'verdict' => 'strong', 'role_type' => 'infrastructure_devops', 'primary_role_focus' => 'Run Kubernetes and Terraform infrastructure on call.',
        'technologies' => [Q::tech('Kubernetes', 'mandatory', 'unfamiliar'), Q::tech('TypeScript', 'incidental', 'familiar')],
    ]));

    $q = qualify(Q::job($career, Q::infrastructure()));

    expect($q->verdict)->toBe(V::NeedsReview)->and($q->model_verdict)->toBe(V::Strong)->and($q->adjustments)->toContain('infrastructure_focus_capped');
});

it('rates a Rails role that demands deep production Rails experience poor', function () {
    $career = Q::candidate();
    Q::fake()->willReturn(Q::answer([
        'verdict' => 'needs_review', 'primary_role_focus' => 'Own a Ruby on Rails monolith.',
        'technologies' => [Q::tech('Ruby on Rails', 'mandatory', 'unfamiliar', 'deep_production', ref: 'S002')],
    ]));

    $q = qualify(Q::job($career, Q::railsDeep()));

    expect($q->verdict)->toBe(V::Poor)->and($q->adjustments)->toContain('deep_unfamiliar_primary_stack');
});

it('keeps a Rails role that welcomes equivalent frameworks in review-to-good range', function () {
    $career = Q::candidate();
    Q::fake()->willReturn(Q::answer([
        'verdict' => 'poor', 'primary_role_focus' => 'Own a Ruby on Rails monolith.',
        'technologies' => [Q::tech('Ruby on Rails', 'mandatory', 'unfamiliar', 'working', transferable: true), Q::tech('Laravel', 'preferred', 'familiar')],
    ]));

    $q = qualify(Q::job($career, Q::railsEquivalent()));

    expect($q->verdict)->toBeIn([V::NeedsReview, V::Good])->and($q->verdict)->toBe(V::NeedsReview);
});

it('does not reject a role because Python is only preferred', function () {
    $career = Q::candidate();
    Q::fake()->willReturn(Q::answer([
        'verdict' => 'poor', // a model that over-weights an unfamiliar keyword
        'technologies' => [Q::tech('Laravel', 'mandatory', 'familiar'), Q::tech('Python', 'preferred', 'unfamiliar', 'deep_production')],
    ]));

    $q = qualify(Q::job($career, Q::pythonPreferred()));

    expect($q->verdict)->not->toBe(V::Poor)->and($q->adjustments)->toContain('poor_without_structural_reason_raised');
});

it('gives an ambiguous-location job no better than good, and tells the model what is unclear', function () {
    $career = Q::candidate();
    $fake = Q::fake()->willReturn(Q::answer());
    $job = Q::job($career, Q::ambiguousLocation(), ['remote_status' => null, 'location' => null]);

    $q = qualify($job);

    expect($q->eligibility)->toBe(E::Unknown)->and($q->verdict)->toBe(V::Good)->and($q->adjustments)->toContain('strong_not_supported')
        ->and(array_column($q->eligibility_reasons, 'code'))->toBe(['remote_status_unknown'])
        ->and($fake->userPrompt)->toContain('does not say whether the role is remote');
});

it('does not call a model for a preview-only description and reports insufficient information', function () {
    $career = Q::candidate();
    $fake = Q::fake()->willReturn(Q::answer());
    $job = Q::job($career, Q::laravel(), ['description_completeness' => DescriptionCompleteness::Preview]);

    $q = qualify($job);

    expect($q->eligibility)->toBe(E::InsufficientInformation)->and($q->verdict)->toBeNull()->and($q->assessment)->toBeNull()
        ->and($q->generated_by)->toBeNull()->and($fake->callCount)->toBe(0);
});

it('does not call a model for an explicitly ineligible job, and keeps the evidence', function () {
    $career = Q::candidate();
    $fake = Q::fake()->willReturn(Q::answer());
    $job = Q::job($career, Q::laravel(), ['remote_status' => JobRemoteStatus::Onsite, 'location' => 'Austin, Texas']);

    $q = qualify($job);

    expect($q->eligibility)->toBe(E::Ineligible)->and($q->verdict)->toBeNull()->and($fake->callCount)->toBe(0)
        ->and($q->eligibility_reasons[0]['evidence'])->toBe('remote_status: onsite; location: Austin, Texas');
});

it('rejects a malformed model response, stores nothing, and leaves the job alone', function (array $broken) {
    $career = Q::candidate();
    $job = Q::job($career, Q::laravel());
    Q::fake()->willReturn($broken);

    expect(fn () => qualify($job))->toThrow(InvalidJobQualificationResponseException::class);

    expect(JobQualification::query()->count())->toBe(0)->and($job->fresh()->disposition)->toBe(JobDisposition::New);
})->with([
    'an unknown verdict' => fn () => Q::answer(['verdict' => 'excellent']),
    'a numeric confidence field' => fn () => Q::answer() + ['confidence' => 0.93],
    'an invented evidence id' => fn () => Q::answer(['fit_reasons' => [['text' => 'Fits well.', 'evidence_refs' => ['S999']]]]),
    'a fit reason without evidence' => fn () => Q::answer(['fit_reasons' => [['text' => 'Fits well.', 'evidence_refs' => []]]]),
    'a concern with no evidence' => fn () => Q::answer(['biggest_concern' => 'Might not fit.', 'biggest_concern_evidence_refs' => []]),
    'an unbounded technology list' => fn () => Q::answer(['technologies' => array_fill(0, 13, Q::tech('Laravel', 'mandatory', 'familiar'))]),
    'an overlong focus' => fn () => Q::answer(['primary_role_focus' => str_repeat('x', 201)]),
    'a missing field' => fn () => array_diff_key(Q::answer(), ['seniority' => 1]),
    'a non-poor verdict with no reasons' => fn () => Q::answer(['fit_reasons' => []]),
]);

it('surfaces a provider failure or timeout, stores nothing, and leaves the job alone', function () {
    $career = Q::candidate();
    $job = Q::job($career, Q::laravel());
    Q::fake()->willFail('Ollama request timed out.');

    expect(fn () => qualify($job))->toThrow(JobQualificationProviderException::class, 'timed out');

    expect(JobQualification::query()->count())->toBe(0)->and($job->fresh()->disposition)->toBe(JobDisposition::New);
});

it('reuses the stored assessment for an unchanged posting without asking the model again', function () {
    $career = Q::candidate();
    $job = Q::job($career, Q::laravel());
    $fake = Q::fake()->willReturn(Q::answer());

    $first = qualify($job);
    $second = qualify($job->fresh());

    expect($second->id)->toBe($first->id)->and($fake->callCount)->toBe(1)->and(JobQualification::query()->count())->toBe(1);
});

it('keeps the assessment valid when rediscovery only refreshes timestamps, compensation or the URL', function () {
    $career = Q::candidate();
    $job = Q::job($career, Q::laravel());
    Q::fake()->willReturn(Q::answer());
    $first = qualify($job);

    $job->update(['last_checked_at' => now()->addDay(), 'compensation_min' => 150000, 'source_url' => 'https://example.com/other', 'description' => '  '.strtoupper(Q::laravel()).'  ']);

    expect(app(QualificationFreshness::class)->current($job->fresh())?->id)->toBe($first->id);
});

it('writes a new assessment when relevant content changes and keeps the old one as history', function (array $change) {
    $career = Q::candidate();
    $job = Q::job($career, Q::laravel());
    Q::fake()->willReturn(Q::answer());
    $first = qualify($job);

    $job->update($change);
    expect(app(QualificationFreshness::class)->current($job->fresh()))->toBeNull();

    $second = qualify($job->fresh());

    expect($second->id)->not->toBe($first->id)
        ->and($job->qualifications()->pluck('id')->all())->toBe([$first->id, $second->id])
        ->and(app(QualificationFreshness::class)->current($job->fresh())->id)->toBe($second->id);
})->with([
    'the description' => [['description' => Q::laravel().' You will also lead a small team of four engineers.']],
    'the title' => [['title' => 'Staff Software Engineer']],
    'the location' => [['location' => 'Remote, Canada']],
    'the workplace' => [['remote_status' => JobRemoteStatus::Hybrid]],
]);

it('writes a new assessment when the candidate\'s evidence changes', function () {
    $career = Q::candidate();
    $job = Q::job($career, Q::laravel());
    $fake = Q::fake()->willReturn(Q::answer());
    qualify($job);

    Skill::factory()->create(['career_profile_id' => $career->id, 'name' => 'Vue 3']);
    $fact = CareerFact::query()->where('career_profile_id', $career->id)->first();
    $fact->skills()->attach(Skill::query()->where('name', 'Vue 3')->first());

    qualify($job->fresh());

    expect($fake->callCount)->toBe(2);
});

it('writes a new assessment when the policy version moves on', function () {
    $career = Q::candidate();
    $job = Q::job($career, Q::laravel());
    $fake = Q::fake()->willReturn(Q::answer());
    qualify($job)->forceFill(['policy_version' => 'balanced-0'])->save();

    qualify($job->fresh());

    expect($fake->callCount)->toBe(2)->and($job->qualifications()->count())->toBe(2);
});

it('runs again on an explicit refresh, even when nothing changed', function () {
    $career = Q::candidate();
    $job = Q::job($career, Q::laravel());
    $fake = Q::fake()->willReturn(Q::answer());

    qualify($job);
    qualify($job->fresh(), refresh: true);

    expect($fake->callCount)->toBe(2)->and($job->qualifications()->count())->toBe(2);
});

it('never changes a job\'s disposition, whatever the verdict', function (string $verdict) {
    $career = Q::candidate();
    $job = Q::job($career, Q::laravel());
    Q::fake()->willReturn(Q::answer(['verdict' => $verdict]));

    qualify($job);

    expect($job->fresh()->disposition)->toBe(JobDisposition::New)->and($job->decisions()->count())->toBe(0);
})->with(['strong', 'poor']);

it('judges a job only against its own profile\'s evidence', function () {
    $mine = Q::candidate();
    $other = CareerProfile::factory()->create();
    $secret = Skill::factory()->create(['career_profile_id' => $other->id, 'name' => 'Zebra-Lang']);
    CareerFact::factory()->create(['career_profile_id' => $other->id, 'visibility' => Visibility::Public, 'attributable_type' => 'career_profile', 'attributable_id' => $other->id])->skills()->sync([$secret->id]);

    $fake = Q::fake()->willReturn(Q::answer());
    $job = Q::job($mine, Q::laravel());
    $q = qualify($job);

    $summary = app(CandidateCapabilitySummary::class)->build($mine);

    expect($fake->userPrompt)->not->toContain('Zebra-Lang')->and($fake->userPrompt)->toContain('Laravel')
        ->and(array_column($summary['skills'], 'name'))->not->toContain('Zebra-Lang')
        ->and($q->career_profile_id)->toBe($mine->id);

    // The other profile's own summary is its own, so its job gets a separate assessment.
    $theirJob = Q::job($other, Q::laravel());
    $theirs = qualify($theirJob);

    expect($theirs->career_profile_id)->toBe($other->id)->and($theirs->profile_fingerprint)->not->toBe($q->profile_fingerprint)
        ->and($fake->userPrompt)->toContain('Zebra-Lang');
});

it('never sends a private fact\'s skills to the model', function () {
    $career = Q::candidate();
    $secret = Skill::factory()->create(['career_profile_id' => $career->id, 'name' => 'Secret-Stack']);
    CareerFact::factory()->create(['career_profile_id' => $career->id, 'visibility' => Visibility::Private, 'attributable_type' => 'career_profile', 'attributable_id' => $career->id])->skills()->sync([$secret->id]);

    $fake = Q::fake()->willReturn(Q::answer());
    qualify(Q::job($career, Q::laravel()));

    expect($fake->userPrompt)->not->toContain('Secret-Stack');
});

it('sends the model a bounded request: the policy, a capability summary and the posting segments, and never a resume', function () {
    $career = Q::candidate();
    $fake = Q::fake()->willReturn(Q::answer());
    qualify(Q::job($career, Q::laravel()));

    expect($fake->systemPrompt)->toContain('NOT writing a resume')->and($fake->systemPrompt)->toContain('Never judge a job poor only because')
        ->and($fake->userPrompt)->toContain('[S001]')->and($fake->userPrompt)->toContain('Candidate capability summary')
        ->and($fake->schema['properties'])->not->toHaveKey('confidence')
        ->and($fake->schema['additionalProperties'])->toBeFalse()
        ->and($fake->schema['properties']['fit_reasons']['items']['properties']['evidence_refs']['items']['enum'])->toContain('S001', 'S002');
});
