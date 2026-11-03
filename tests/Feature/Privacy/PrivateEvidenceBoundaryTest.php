<?php

use App\Contracts\GeneratesJobMatch;
use App\Contracts\GeneratesResumeSelection;
use App\Contracts\GeneratesResumeWording;
use App\Enums\Visibility;
use App\Exceptions\InvalidJobMatchResponseException;
use App\Models\CareerFact;
use App\Models\JobMatch;
use App\Models\Project;
use App\Support\JobMatch\CandidatePayloadBuilder;
use App\Support\JobMatch\GenerateJobMatch;
use App\Support\JobMatch\JobMatchProviderResponse;
use App\Support\ResumeVariant\GenerateResumeVariant;
use App\Support\ResumeVariant\ResumeSelectionProviderResponse;
use App\Support\ResumeVariant\ResumeWordingProviderResponse;
use Tests\Support\FakeJobMatchProvider;
use Tests\Support\FakeResumeSelectionProvider;
use Tests\Support\FakeResumeWordingProvider;
use Tests\Support\JobMatchFixtures;
use Tests\Support\ResumeVariantFixtures;

const PRIVATE_STATEMENT = 'Handled a sealed legal settlement for ZEBRA-CLIENT-X.';

it('never puts a Private fact in the matching payload, prompt or frozen snapshot', function () {
    ['profile' => $profile, 'factOne' => $factOne, 'education' => $education] = JobMatchFixtures::candidate();
    ['analysis' => $analysis, 'findingOne' => $findingOne, 'findingTwo' => $findingTwo] = JobMatchFixtures::job();
    $private = CareerFact::factory()->create([
        'career_profile_id' => $profile->id, 'key' => 'private-secret', 'statement' => PRIVATE_STATEMENT, 'visibility' => Visibility::Private,
    ]);
    $restricted = CareerFact::factory()->create([
        'career_profile_id' => $profile->id, 'key' => 'restricted-ok', 'statement' => 'A restricted but model-eligible statement.', 'visibility' => Visibility::Restricted,
    ]);

    $fake = new FakeJobMatchProvider;
    app()->instance(GeneratesJobMatch::class, $fake);
    $fake->willReturn(new JobMatchProviderResponse('openai', 'm', JobMatchFixtures::validResponse($findingOne, $findingTwo, $factOne, $education)));

    $match = app(GenerateJobMatch::class)->generate($analysis, $profile);
    $snapshotKeys = collect($match->input_snapshot['candidate']['career_facts'])->pluck('key')->all();

    expect($fake->capturedUserPrompt)->not->toContain(PRIVATE_STATEMENT)->not->toContain('private-secret')
        ->and($fake->capturedUserPrompt)->toContain('restricted-ok')
        ->and(json_encode($match->input_snapshot))->not->toContain('ZEBRA-CLIENT-X')
        ->and($snapshotKeys)->not->toContain($private->key)->toContain($restricted->key)
        ->and(json_encode($fake->capturedSchema))->not->toContain('private-secret');
});

it('rejects a model response that cites a Private fact it was never given', function () {
    ['profile' => $profile, 'factOne' => $factOne, 'education' => $education] = JobMatchFixtures::candidate();
    ['analysis' => $analysis, 'findingOne' => $findingOne, 'findingTwo' => $findingTwo] = JobMatchFixtures::job();
    CareerFact::factory()->create(['career_profile_id' => $profile->id, 'key' => 'private-secret', 'statement' => PRIVATE_STATEMENT, 'visibility' => Visibility::Private]);

    $response = JobMatchFixtures::validResponse($findingOne, $findingTwo, $factOne, $education);
    $response['findings'][0]['matches'][0]['career_fact_key'] = 'private-secret';

    $fake = new FakeJobMatchProvider;
    app()->instance(GeneratesJobMatch::class, $fake);
    $fake->willReturn(new JobMatchProviderResponse('openai', 'm', $response));

    expect(fn () => app(GenerateJobMatch::class)->generate($analysis, $profile))->toThrow(InvalidJobMatchResponseException::class)
        ->and(JobMatch::count())->toBe(0);
});

it('excludes a fact under a Private project from matching', function () {
    ['profile' => $profile] = JobMatchFixtures::candidate();
    $project = Project::factory()->independent()->create(['career_profile_id' => $profile->id, 'default_visibility' => Visibility::Private]);
    CareerFact::factory()->create([
        'career_profile_id' => $profile->id, 'key' => 'in-private-project', 'statement' => 'Inside a private project.',
        'visibility' => Visibility::Public, 'attributable_type' => 'project', 'attributable_id' => $project->id,
    ]);

    $keys = collect(app(CandidatePayloadBuilder::class)->build($profile)['career_facts'])->pluck('key')->all();

    expect($keys)->not->toContain('in-private-project');
});

it('keeps Private facts out of Selection and Wording prompts, the resume content and the stored review inputs', function () {
    $candidate = ResumeVariantFixtures::candidate();
    $job = ResumeVariantFixtures::job();
    $match = ResumeVariantFixtures::jobMatch($candidate['profile'], $job['analysis'], $job['azureFinding'], $job['ownershipFinding'], $candidate['factAws'], $candidate['factIndependent']);
    CareerFact::factory()->create([
        'career_profile_id' => $candidate['profile']->id, 'key' => 'private-secret', 'statement' => PRIVATE_STATEMENT,
        'visibility' => Visibility::Private, 'attributable_type' => 'role', 'attributable_id' => $candidate['role']->id,
    ]);

    $selection = new FakeResumeSelectionProvider;
    $wording = new FakeResumeWordingProvider;
    app()->instance(GeneratesResumeSelection::class, $selection);
    app()->instance(GeneratesResumeWording::class, $wording);
    $selection->willReturn(new ResumeSelectionProviderResponse('openai', 'm', [
        'summary_evidence' => [$candidate['factIndependent']->key], 'skills' => [],
        'experience' => [[
            'role_id' => $candidate['role']->id, 'title_choice' => 'full',
            'bullet_groups' => [['project_id' => -1, 'order' => 1, 'career_fact_keys' => [$candidate['factIndependent']->key], 'job_analysis_finding_ids' => [$job['ownershipFinding']->id]]],
        ]],
        'selected_projects' => [], 'target_term_usages' => [],
    ]));
    $wording->willReturn(new ResumeWordingProviderResponse('openai', 'm', [
        'summary' => 'Senior engineer.',
        'experience' => [['role_id' => $candidate['role']->id, 'bullets' => [['bullet_group_index' => 0, 'text' => 'Independently implemented the technical solution from team-provided requirements.']]]],
        'selected_projects' => [],
    ]));

    $variant = app(GenerateResumeVariant::class)->generateFull($match);

    $artifacts = json_encode([
        $selection->capturedUserPrompt, $wording->capturedUserPrompt,
        $variant->fresh()->only(['summary', 'selection_input_snapshot', 'wording_input_snapshot', 'review_report']),
        $variant->experienceBullets->pluck('text')->all(),
    ]);

    expect($artifacts)->not->toContain('ZEBRA-CLIENT-X')->not->toContain('private-secret');
});
