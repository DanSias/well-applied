<?php

use App\Contracts\GeneratesResumeWording;
use App\Exceptions\ResumeGenerationProviderException;
use App\Support\GenerationAttempt\GenerationMetrics;
use App\Support\ResumeVariant\CorrectBullets;
use App\Support\ResumeVariant\GenerateResumeVariant;
use App\Support\ResumeVariant\Prompts\ProfileJobWordingPromptV4;
use App\Support\ResumeVariant\RecoverWording;
use App\Support\ResumeVariant\ResumeWordingProviderResponse;
use App\Support\ResumeVariant\ResumeWordingResponseValidator;
use App\Support\ResumeVariant\WordingCheckContext;
use Tests\Support\CompositionScenario;
use Tests\Support\FakeResumeWordingProvider;

/**
 * Variant 32's first wording response was valid, but one bullet cited a system-design fact and never said it, so the whole
 * resume was generated again. A bullet that leaves out one of its own facts is now rewritten on its own, from only its
 * facts, and judged by every rule a bullet is judged by.
 */
const TR_BUILD = 'Built a reporting platform that saves analysts twenty hours weekly.';
const TR_DESIGN = 'Designed the database schema and ingestion architecture for the platform.';
const TR_OTHER = 'Implemented webhook handlers for payment events with retry schedules.';
const TR_FIXED = 'Built a reporting platform that saves analysts twenty hours weekly, designing the database schema and ingestion architecture behind it.';

function trContext(): WordingCheckContext
{
    return new WordingCheckContext(
        validRoleIds: [1], expectedBulletGroupIndexesByRole: [1 => [0, 1]], validSelectedProjectIds: [], denylistTerms: [],
        careerFactKeysByLocation: ['summary' => ['k1'], '1:0' => ['k1', 'k2'], '1:1' => ['k3']],
        guardrailByFactKey: [], skillIdsByFactKey: [], canonicalSkillsById: [], textRecognizedSkillIdsByFactKey: [], directTargetTermsByLocation: [],
        restrictedFactKeys: [], factTextByKey: ['k1' => TR_BUILD, 'k2' => TR_DESIGN, 'k3' => TR_OTHER],
        restrictedTerms: [], genericTerms: [], claimFidelity: true,
    );
}

function trFacts(): array
{
    $fact = fn (string $key, string $statement) => ['key' => $key, 'statement' => $statement, 'visibility' => 'public', 'fact_type' => 'bullet'];

    return ['experience' => [['role_id' => 1, 'bullet_groups' => [
        ['bullet_group_index' => 0, 'career_facts' => [$fact('k1', TR_BUILD), $fact('k2', TR_DESIGN)]],
        ['bullet_group_index' => 1, 'career_facts' => [$fact('k3', TR_OTHER)]],
    ]]], 'job_context' => ['role' => 'Software Engineer', 'lead_with' => []]];
}

/** @return array{content: array<string, mixed>, items: array<int, array<string, mixed>>} */
function trRecovered(string $firstBullet = TR_BUILD): array
{
    $ctx = trContext();
    $validator = new ResumeWordingResponseValidator;
    $triage = $validator->triage(['summary' => 'Full-stack engineer who builds reporting platforms for analysts.', 'experience' => [['role_id' => 1, 'bullets' => [
        ['bullet_group_index' => 0, 'text' => $firstBullet], ['bullet_group_index' => 1, 'text' => TR_OTHER],
    ]]], 'selected_projects' => []], $ctx);

    return (new RecoverWording($validator))->recover($triage, $ctx);
}

function trCorrect(array $recovered, FakeResumeWordingProvider $provider): array
{
    return (new CorrectBullets(new ResumeWordingResponseValidator))->apply($recovered, $provider, new ProfileJobWordingPromptV4, trFacts(), trContext(), new GenerationMetrics);
}

function trAnswer(string $text): FakeResumeWordingProvider
{
    return (new FakeResumeWordingProvider)->willReturn(new ResumeWordingProviderResponse('fake', 'm', ['text' => $text]));
}

it('rewrites only the bullet that left out a cited fact, from only its own facts', function () {
    $provider = trAnswer(TR_FIXED);
    $result = trCorrect(trRecovered(), $provider);
    $bullets = $result['content']['experience'][0]['bullets'];

    expect($provider->callCount)->toBe(1)->and($result['resolved'])->toBe(['k2'])
        ->and($bullets[0]['text'])->toBe(TR_FIXED)->and($bullets[1]['text'])->toBe(TR_OTHER)
        // Provenance is untouched: the same group, in the same place.
        ->and(array_column($bullets, 'bullet_group_index'))->toBe([0, 1])
        // The model sees the two facts of this bullet and nothing of the other one.
        ->and($provider->capturedUserPrompt)->toContain('ingestion architecture')->toContain('twenty hours')->not->toContain('webhook')
        ->and($provider->capturedSchema['required'])->toBe(['text']);

    $item = collect($result['items'])->first(fn ($i) => ($i['bullet_group_index'] ?? null) === 0);

    expect($item['status'])->toBe('generalized')->and($item['text'])->toBe(TR_FIXED)->and($item['original'])->toBe(TR_BUILD)
        ->and($item['action'])->toContain('Rewritten once')->toContain('k2');
});

it('does not call the model when every cited fact is stated', function () {
    $provider = trAnswer(TR_FIXED);
    $result = trCorrect(trRecovered(TR_FIXED), $provider);

    expect($provider->callCount)->toBe(0)->and($result['resolved'])->toBe([]);
});

it('keeps the original, and reports nothing resolved, when the answer still leaves the fact out', function () {
    $provider = trAnswer('Built a reporting platform that saves analysts twenty hours weekly for the business.');
    $result = trCorrect(trRecovered(), $provider);

    expect($provider->callCount)->toBe(1)->and($result['resolved'])->toBe([])
        ->and($result['content']['experience'][0]['bullets'][0]['text'])->toBe(TR_BUILD);
});

it('rejects an answer that adds an ownership upgrade, an unsupported figure or a technology', function (string $answer) {
    $result = trCorrect(trRecovered(), trAnswer($answer));

    expect($result['resolved'])->toBe([])->and($result['content']['experience'][0]['bullets'][0]['text'])->toBe(TR_BUILD);
})->with([
    'ownership upgrade' => ['Led the reporting platform and designed the database schema and ingestion architecture behind it.'],
    'new figure' => ['Built a reporting platform that saves analysts 85% of their time, designing the database schema and ingestion architecture behind it.'],
]);

it('keeps the original when the model is unavailable, and asks only once', function () {
    $provider = (new FakeResumeWordingProvider)->willFail(new ResumeGenerationProviderException('model unavailable'));
    $result = trCorrect(trRecovered(), $provider);

    expect($provider->callCount)->toBe(1)->and($result['resolved'])->toBe([])
        ->and($result['content']['experience'][0]['bullets'][0]['text'])->toBe(TR_BUILD);
});

it('corrects no more bullets than the budget allows', function () {
    config(['resume.composition.retention.max_bullet_corrections' => 0]);
    $provider = trAnswer(TR_FIXED);

    expect(trCorrect(trRecovered(), $provider)['resolved'])->toBe([])->and($provider->callCount)->toBe(0);
});

it('replaces a whole-resume retry with one bullet call in a job-tailored generation', function () {
    $s = CompositionScenario::create();
    $synthesis = CompositionScenario::approvedProfile($s);
    $seen = new stdClass;
    // Every multi-fact bullet states only its first fact.
    $seen->bullet = fn (array $g) => count($g['career_facts']) > 1 ? rtrim($g['career_facts'][0]['statement'], '.').'.' : null;
    app()->instance(GeneratesResumeWording::class, CompositionScenario::wording($seen));

    $variant = app(GenerateResumeVariant::class)->generateForJob($s['analysis'], $synthesis);

    // The wording call, then bullet corrections: never a second full resume (the rejection notice is in no prompt).
    expect($seen->calls)->toBe(1 + ($seen->bulletCorrections ?? 0))->and($seen->bulletCorrections ?? 0)->toBeLessThanOrEqual(2)
        ->and($seen->bulletCorrections ?? 0)->toBeGreaterThan(0)->and($variant->review_report)->toBeArray();
});

it('keeps the original and the repaired text in the stored review report, and reports an omitted bullet clearly', function () {
    $s = CompositionScenario::create();
    $synthesis = CompositionScenario::approvedProfile($s);
    $seen = new stdClass;
    // The first bullet is taken to production (no fact says so) in a phrase that can be removed; the second states it as a bare object, which cannot.
    $seen->bullet = function (array $g) {
        static $n = 0;
        $text = rtrim(implode(' ', array_map(fn ($f) => rtrim($f['statement'], '.').'.', $g['career_facts'])));

        return match ($n++) {
            0 => rtrim($text, '.').' into production software.',
            1 => 'Supported production for the Analytics reporting service.',
            default => null,
        };
    };
    app()->instance(GeneratesResumeWording::class, CompositionScenario::wording($seen));

    $variant = app(GenerateResumeVariant::class)->generateForJob($s['analysis'], $synthesis);
    $items = json_decode(json_encode($variant->fresh()->review_report['items']), true);
    $repaired = collect($items)->first(fn ($i) => str_contains((string) ($i['original'] ?? ''), 'into production software'));
    $omitted = collect($items)->firstWhere('status', 'omitted');

    expect($repaired)->not->toBeNull()
        ->and($repaired['status'])->toBe('generalized')->and($repaired['needs_verification'])->toBeTrue()
        ->and($repaired['text'])->not->toContain('production')->and($repaired['original'])->toContain('into production software')
        ->and($repaired['action'])->toContain('delivery stage')
        ->and($omitted)->not->toBeNull()->and($omitted['text'])->toBeNull()->and($omitted['original'])->toContain('Supported production')
        ->and($omitted['action'])->toContain('Removed')->and($omitted['label'])->toContain('bullet')
        ->and(array_column($omitted['findings'], 'kind'))->toContain('lifecycle_upgrade');
});
