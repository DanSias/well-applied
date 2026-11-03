<?php

use App\Models\CareerFact;
use App\Models\CareerLimitation;
use App\Models\Skill;
use App\Support\Clarification\ActiveLimitations;
use App\Support\ResumeVariant\RecoverWording;
use App\Support\ResumeVariant\ResumeCandidatePayloadBuilder;
use App\Support\ResumeVariant\ResumeWordingResponseValidator;
use App\Support\ResumeVariant\WordingCheckContext;
use Tests\Support\ClarificationFixtures;

function limitationContext(array $terms): WordingCheckContext
{
    return new WordingCheckContext(
        validRoleIds: [1], expectedBulletGroupIndexesByRole: [1 => [0, 1]], validSelectedProjectIds: [],
        denylistTerms: [], careerFactKeysByLocation: ['1:0' => ['a'], '1:1' => ['b'], 'summary' => []], guardrailByFactKey: [],
        skillIdsByFactKey: [], canonicalSkillsById: [], textRecognizedSkillIdsByFactKey: [], directTargetTermsByLocation: [],
        restrictedFactKeys: [], factTextByKey: ['a' => 'Backend work.', 'b' => 'Integration work.'], restrictedTerms: [], genericTerms: [],
        limitationTerms: $terms,
    );
}

function wordingWith(string $first, string $second, string $summary = 'Full-stack engineer.'): array
{
    return [
        'summary' => $summary,
        'experience' => [['role_id' => 1, 'bullets' => [
            ['bullet_group_index' => 0, 'text' => $first],
            ['bullet_group_index' => 1, 'text' => $second],
        ]]],
        'selected_projects' => [],
    ];
}

it('blocks a claim of a confirmed limitation and omits only that bullet, keeping the rest', function () {
    $context = limitationContext(['Ruby on Rails', 'Rails']);
    $content = wordingWith('Built backend services in Ruby on Rails for merchants.', 'Integrated payment APIs for merchant onboarding.');

    $triage = app(ResumeWordingResponseValidator::class)->triage($content, $context);
    $result = app(RecoverWording::class)->recover($triage, $context);

    $texts = collect($result['content']['experience'][0]['bullets'])->pluck('text')->all();
    $omitted = collect($result['items'])->firstWhere('status', 'omitted');

    expect($texts)->toBe(['Integrated payment APIs for merchant onboarding.'])
        ->and($omitted['findings'][0]['kind'])->toBe('known_limitation')
        ->and($omitted['findings'][0]['severity'])->toBe('block')
        ->and(json_encode($result['content']))->not->toContain('Rails');
});

it('also removes a limited claim from the summary without dropping the rest of it', function () {
    $context = limitationContext(['Rails']);
    $content = wordingWith('Integrated payment APIs.', 'Built integration tooling.', 'Senior engineer. Experienced with Rails services. Strong in React and Node.js.');

    $result = app(RecoverWording::class)->recover(app(ResumeWordingResponseValidator::class)->triage($content, $context), $context);

    expect($result['content']['summary'])->not->toContain('Rails')->and($result['content']['summary'])->toContain('Strong in React');
});

it('matches whole words only, so "trails" is not a Rails claim', function () {
    $context = limitationContext(['Rails']);

    $triage = app(ResumeWordingResponseValidator::class)->triage(wordingWith('Mapped hiking trails for a client portal.', 'Integrated payment APIs.'), $context);

    expect($triage->findings)->toBe([]);
    expect(ActiveLimitations::matches('Mapped trails', ['Rails']))->toBeFalse()->and(ActiveLimitations::matches('Built Rails apps', ['Rails']))->toBeTrue();
});

it('does nothing when no limitation is recorded or after it is revoked', function () {
    $s = ClarificationFixtures::scenario();
    $limitation = CareerLimitation::create(['career_profile_id' => $s['profile']->id, 'topic_key' => 'ruby_on_rails', 'topic' => 'Ruby on Rails', 'terms' => ['Rails'], 'status' => 'no_direct_experience', 'source' => 'user_confirmation', 'confirmed_at' => now()]);

    expect(ActiveLimitations::terms($s['profile']))->toBe(['Rails']);

    $limitation->update(['revoked_at' => now()]);

    expect(ActiveLimitations::terms($s['profile']))->toBe([]);
});

it('withholds a limited Skill from the Selection candidates', function () {
    $s = ClarificationFixtures::scenario();
    $rails = Skill::factory()->create(['career_profile_id' => $s['profile']->id, 'name' => 'Rails']);
    $php = Skill::factory()->create(['career_profile_id' => $s['profile']->id, 'name' => 'PHP']);
    $s['publicFact']->skills()->attach([$rails->id, $php->id]);

    $names = fn () => collect(app(ResumeCandidatePayloadBuilder::class)->build($s['match'])['eligible_skills'])->pluck('name')->all();
    expect($names())->toContain('Rails')->toContain('PHP');

    CareerLimitation::create(['career_profile_id' => $s['profile']->id, 'topic_key' => 'ruby_on_rails', 'topic' => 'Ruby on Rails', 'terms' => ['Rails'], 'status' => 'no_direct_experience', 'source' => 'user_confirmation', 'confirmed_at' => now()]);

    expect($names())->not->toContain('Rails')->toContain('PHP');
});

it('never exposes a limitation as resume evidence: it is not a CareerFact and not in any candidate payload', function () {
    $s = ClarificationFixtures::scenario();
    CareerLimitation::create(['career_profile_id' => $s['profile']->id, 'topic_key' => 'ruby_on_rails', 'topic' => 'Ruby on Rails', 'terms' => ['Rails'], 'status' => 'no_direct_experience', 'source' => 'user_confirmation', 'confirmed_at' => now()]);

    $payload = json_encode(app(ResumeCandidatePayloadBuilder::class)->build($s['match']));

    expect($payload)->not->toContain('No direct experience')->and(CareerFact::where('statement', 'like', '%No direct experience%')->count())->toBe(0);
});
