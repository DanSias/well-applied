<?php

use App\Contracts\GeneratesResumeWording;
use App\Exceptions\ResumeGenerationProviderException;
use App\Support\GenerationAttempt\GenerationMetrics;
use App\Support\ResumeVariant\CorrectSummary;
use App\Support\ResumeVariant\GenerateResumeVariant;
use App\Support\ResumeVariant\Prompts\ProfileJobWordingPromptV3;
use App\Support\ResumeVariant\RecoverWording;
use App\Support\ResumeVariant\ResumeWordingProviderResponse;
use App\Support\ResumeVariant\ResumeWordingResponseValidator;
use App\Support\ResumeVariant\WordingCheckContext;
use Tests\Support\CompositionScenario;
use Tests\Support\FakeResumeWordingProvider;

/**
 * The summary collapse found in a real first draft: a two-sentence summary lost one sentence because it named a
 * technology and a figure its evidence did not state, and what was left was one generic line of 16 words. These tests
 * use invented facts. Python is a canonical skill no summary fact authorizes, which is what blocked the real sentence.
 */
const CQ_POSITIONING = 'Full-stack software engineer with end-to-end ownership from business requirements through architecture and delivery.';
const CQ_UNSUPPORTED = 'Builds Python automation and cut reporting time by 85% across the company.';
const CQ_STRONG = 'Full-stack engineer who builds internal platforms from requirements to deployment. Built a workflow platform on Laravel and Vue that unifies Jira and GitLab activity and was deployed to the Product Management team, and a content pipeline that converts wiki pages to deterministic Markdown with an auditable change report.';

function cqContext(): WordingCheckContext
{
    return new WordingCheckContext(
        validRoleIds: [1], expectedBulletGroupIndexesByRole: [1 => [0]], validSelectedProjectIds: [], denylistTerms: [],
        careerFactKeysByLocation: ['summary' => ['k1', 'k2'], '1:0' => ['k1']],
        guardrailByFactKey: [], skillIdsByFactKey: ['k1' => [10], 'k2' => [10]], canonicalSkillsById: [10 => 'Laravel', 11 => 'Python'],
        textRecognizedSkillIdsByFactKey: [], directTargetTermsByLocation: [], restrictedFactKeys: [],
        factTextByKey: ['k1' => 'Built a workflow platform on Laravel and Vue that unifies Jira and GitLab activity.', 'k2' => 'Built a pipeline converting wiki pages to deterministic Markdown with an auditable change report.'],
        restrictedTerms: [], genericTerms: [], claimFidelity: true,
    );
}

/**
 * @return array{content: array<string, mixed>, items: array<int, array<string, mixed>>}
 */
function cqRecovered(string $summary): array
{
    $ctx = cqContext();
    $validator = new ResumeWordingResponseValidator;
    $triage = $validator->triage(['summary' => $summary, 'experience' => [['role_id' => 1, 'bullets' => [['bullet_group_index' => 0, 'text' => 'Built a workflow platform on Laravel and Vue that unifies Jira and GitLab activity.']]]], 'selected_projects' => []], $ctx);

    return (new RecoverWording($validator))->recover($triage, $ctx);
}

function cqCorrect(array $recovered, FakeResumeWordingProvider $provider): array
{
    $input = [
        'summary_evidence' => [['key' => 'k1', 'statement' => 'Built a workflow platform on Laravel and Vue that unifies Jira and GitLab activity.']],
        'summary_authorized_skills' => ['Laravel'], 'reference_context' => ['target_identity' => 'Full-Stack Developer'],
        'job_context' => ['role' => 'Software Engineer', 'lead_with' => ['Laravel'], 'not_supported' => ['Ruby on Rails']],
    ];

    return (new CorrectSummary(new ResumeWordingResponseValidator, new RecoverWording(new ResumeWordingResponseValidator)))
        ->apply($recovered, $provider, new ProfileJobWordingPromptV3, $input, cqContext(), new GenerationMetrics);
}

function cqSummaryItem(array $result): array
{
    return collect($result['items'])->firstWhere('location', 'summary');
}

it('shows the collapse: recovery alone leaves a single short line and says it was cut', function () {
    $recovered = cqRecovered(CQ_POSITIONING.' '.CQ_UNSUPPORTED);

    expect($recovered['content']['summary'])->toBe(CQ_POSITIONING)
        ->and(CorrectSummary::words($recovered['content']['summary']))->toBeLessThan(30)
        ->and(cqSummaryItem($recovered)['status'])->toBe('generalized')
        ->and(collect(cqSummaryItem($recovered)['findings'])->pluck('kind')->all())->toContain('unsupported_technology', 'figure_precision');
});

it('corrects a collapsed summary once, from its own evidence, and keeps every check', function () {
    $provider = (new FakeResumeWordingProvider)->willReturn(new ResumeWordingProviderResponse('fake', 'm', ['summary' => CQ_STRONG]));

    $result = cqCorrect(cqRecovered(CQ_POSITIONING.' '.CQ_UNSUPPORTED), $provider);
    $item = cqSummaryItem($result);

    expect($result['outcome'])->toBe('corrected')
        ->and($provider->callCount)->toBe(1)
        ->and($result['content']['summary'])->toBe(CQ_STRONG)
        ->and(CorrectSummary::words($result['content']['summary']))->toBeGreaterThanOrEqual(45)
        ->and($item['status'])->toBe('generalized')->and($item['action'])->toContain('Rewritten once')
        // The model is shown exactly what it may use and exactly what was rejected, and nothing about the other bullets.
        ->and($provider->capturedUserPrompt)->toContain('summary_authorized_skills')->toContain('Python')->toContain(CQ_POSITIONING)
        ->and($provider->capturedSchema['required'])->toBe(['summary']);
});

it('never lets a correction reintroduce what validation removed', function () {
    // The model answers with a longer summary that again names Python and an 85% figure no fact states.
    $provider = (new FakeResumeWordingProvider)->willReturn(new ResumeWordingProviderResponse('fake', 'm', ['summary' => CQ_POSITIONING.' '.CQ_UNSUPPORTED]));

    $result = cqCorrect(cqRecovered(CQ_POSITIONING.' '.CQ_UNSUPPORTED), $provider);

    expect($result['outcome'])->toBe('rejected')
        ->and($result['content']['summary'])->toBe(CQ_POSITIONING)->not->toContain('Python')->not->toContain('85%')
        // It stays collapsed, so it ships flagged for review, never as a finished summary.
        ->and(cqSummaryItem($result)['status'])->toBe('review')
        ->and(collect(cqSummaryItem($result)['findings'])->pluck('kind')->all())->toContain('summary_collapsed');
});

it('keeps the recovered summary, flagged, when the correction is unavailable, and never retries', function () {
    $provider = (new FakeResumeWordingProvider)->willFail(new ResumeGenerationProviderException('model unavailable'));

    $result = cqCorrect(cqRecovered(CQ_POSITIONING.' '.CQ_UNSUPPORTED), $provider);

    expect($result['outcome'])->toBe('unavailable')->and($provider->callCount)->toBe(1)
        ->and($result['content']['summary'])->toBe(CQ_POSITIONING)
        ->and(cqSummaryItem($result)['status'])->toBe('review')
        ->and(cqSummaryItem($result)['findings'][count(cqSummaryItem($result)['findings']) - 1]['message'])->toContain('too short');
});

it('does not ask the model again for a summary that was not cut, and does not pad a strong short one', function () {
    $provider = (new FakeResumeWordingProvider)->willReturn(new ResumeWordingProviderResponse('fake', 'm', ['summary' => CQ_STRONG]));
    $short = 'Full-stack engineer who built a Laravel and Vue workflow platform that unifies Jira and GitLab activity and was deployed to Product Management, and a pipeline that converts wiki pages to deterministic Markdown with an auditable change report.';

    $result = cqCorrect(cqRecovered($short), $provider);

    expect($provider->callCount)->toBe(0)->and($result['outcome'])->toBe('not_needed')
        ->and($result['content']['summary'])->toBe($short)
        ->and(CorrectSummary::words($short))->toBeLessThan(45)->toBeGreaterThanOrEqual(30)
        ->and(cqSummaryItem($result)['status'])->toBe('passed');
});

it('flags a summary that is simply too short, even when nothing was cut', function () {
    $provider = new FakeResumeWordingProvider;

    $result = cqCorrect(cqRecovered(CQ_POSITIONING), $provider);

    expect($provider->callCount)->toBe(0)->and(cqSummaryItem($result)['status'])->toBe('review');
});

it('corrects a collapsed summary inside a job-tailored generation with one extra model call, not a retry of the resume', function () {
    $s = CompositionScenario::create();
    $synthesis = CompositionScenario::approvedProfile($s);
    $seen = new stdClass;
    // The first summary names Ruby on Rails, a confirmed limitation, in its second sentence: recovery removes it.
    $seen->summary = 'Full-Stack Developer who builds internal platforms. Ships Ruby on Rails services and cut reporting time by 85% across the company.';
    $seen->correction = 'Full-Stack Developer who builds internal platforms and payment integrations. Built Nimbus, a full-stack React and Node.js analytics platform unifying BigQuery and Salesforce data that reduced reporting time by 85%, and webhook handlers for payment events with retry schedules and idempotent processing of duplicate deliveries.';
    app()->instance(GeneratesResumeWording::class, CompositionScenario::wording($seen));

    $variant = app(GenerateResumeVariant::class)->generateForJob($s['analysis'], $synthesis);
    $summary = collect($variant->review_report['items'])->firstWhere('location', 'summary');

    expect($seen->calls)->toBe(2)->and($seen->corrections)->toBe(1)
        ->and($variant->summary)->toBe($seen->correction)->not->toContain('Rails')
        ->and($summary['status'])->toBe('generalized')->and($summary['needs_verification'])->toBeTrue()
        ->and($seen->correctionInput['not_supported'])->toContain('Ruby on Rails')
        ->and(json_encode($seen->correctionInput))->not->toContain('SECRET-PROJECT-ZEBRA');
});

it('ships a summary that cannot be corrected flagged for review in a job-tailored generation', function () {
    $s = CompositionScenario::create();
    $synthesis = CompositionScenario::approvedProfile($s);
    $seen = new stdClass;
    $seen->summary = 'Full-Stack Developer who builds internal platforms. Ships Ruby on Rails services and cut reporting time by 85% across the company.';
    app()->instance(GeneratesResumeWording::class, CompositionScenario::wording($seen));

    $variant = app(GenerateResumeVariant::class)->generateForJob($s['analysis'], $synthesis);
    $summary = collect($variant->review_report['items'])->firstWhere('location', 'summary');

    expect($seen->corrections)->toBe(1)->and($seen->calls)->toBe(2)
        ->and($variant->summary)->toBe('Full-Stack Developer who builds internal platforms.')
        ->and($summary['status'])->toBe('review')->and($summary['needs_verification'])->toBeTrue()
        ->and(array_column($summary['findings'], 'kind'))->toContain('summary_collapsed');
});

it('makes a single wording call for a healthy summary', function () {
    $s = CompositionScenario::create();
    $synthesis = CompositionScenario::approvedProfile($s);
    $seen = new stdClass;
    app()->instance(GeneratesResumeWording::class, CompositionScenario::wording($seen));

    $variant = app(GenerateResumeVariant::class)->generateForJob($s['analysis'], $synthesis);

    expect($seen->calls)->toBe(1)->and($seen->corrections ?? 0)->toBe(0)
        ->and(collect($variant->review_report['items'])->firstWhere('location', 'summary')['status'])->toBe('passed');
});

it('gives the summary the whole groups behind the strongest bullets, and the public figure that holds a metric', function () {
    $s = CompositionScenario::create();
    $synthesis = CompositionScenario::approvedProfile($s);
    $seen = new stdClass;
    app()->instance(GeneratesResumeWording::class, CompositionScenario::wording($seen));

    app(GenerateResumeVariant::class)->generateForJob($s['analysis'], $synthesis);
    $summaryKeys = array_column($seen->input['summary_evidence'], 'key');
    $bulletGroups = collect($seen->input['experience'])->flatMap(fn ($r) => $r['bullet_groups'])->map(fn ($g) => array_column($g['career_facts'], 'key'));
    $strongestGroup = $bulletGroups->first(fn ($keys) => in_array('webhooks', $keys, true));

    // A technology the summary may name is stated by a fact the summary cites: the whole group is there, not one fact of it.
    expect($strongestGroup)->not->toBeNull()
        ->and(array_diff($strongestGroup, $summaryKeys))->toBe([])
        ->and(array_intersect($summaryKeys, ['private', 'restricted-figure']))->toBe([]);
});
