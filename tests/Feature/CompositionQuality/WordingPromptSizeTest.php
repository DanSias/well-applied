<?php

use App\Contracts\GeneratesResumeWording;
use App\Support\ResumeVariant\GenerateResumeVariant;
use App\Support\ResumeVariant\Prompts\ProfileJobWordingPromptV3;
use App\Support\ResumeVariant\Prompts\ProfileJobWordingPromptV4;
use App\Support\ResumeVariant\SlimWordingFacts;
use Tests\Support\CompositionScenario;

/**
 * The live test sent a 21.6k-token wording prompt for 15 bullets, and the model spent its output budget reasoning over it.
 * Measured, the evidence was pretty-printed JSON (more than twice the characters of compact JSON), every fact carried ids,
 * dates, skill categories and empty fields the model cannot use, and 11 of 12 summary facts were sent twice. These tests
 * hold the size down without letting the evidence or the claim restrictions go.
 */
function candidateFact(int $i): array
{
    return [
        'key' => "project-{$i}-what-it-is", 'fact_type' => 'bullet', 'visibility' => $i % 3 === 0 ? 'restricted' : 'public',
        'statement' => "Built System {$i}, a Laravel and Vue platform that normalizes Jira work items and GitLab merge-request activity into one workflow model so dashboards read the same computed results and every view stays consistent.",
        'attribution' => ['employer' => 'Acme', 'role_id' => 1, 'role' => 'Engineer', 'project_id' => $i, 'project' => "System {$i}"],
        'role_dates' => ['start_year' => 2025, 'start_month' => 8, 'end_year' => 2026, 'end_month' => 10],
        'metric' => null, 'skills' => [['id' => $i, 'name' => 'Laravel', 'category' => 'build_technology'], ['id' => 50 + $i, 'name' => 'Vue.js', 'category' => 'build_technology']],
        'job_match_annotations' => [], 'shared_claim' => ['also_attached_to' => ['Other project (Acme)']],
    ];
}

/**
 * @return array<string, mixed>
 */
function fifteenBulletInput(): array
{
    $facts = array_map('candidateFact', range(1, 26));

    return [
        'summary_evidence' => array_slice($facts, 0, 12), 'summary_authorized_skills' => ['Laravel', 'Vue.js'],
        'experience' => [['role_id' => 1, 'display_title' => 'Engineer', 'bullet_groups' => array_map(fn (int $g) => [
            'bullet_group_index' => $g, 'career_facts' => array_slice($facts, 2 + $g * 2, 2), 'direct_target_terms' => [],
        ], range(0, 11))]],
        'selected_projects' => [],
    ];
}

it('sends the evidence compactly: no indentation, no empty deny list', function () {
    $input = SlimWordingFacts::input(fifteenBulletInput());
    $prompt = (new ProfileJobWordingPromptV4)->userPrompt($input, []);
    preg_match('/--- Approved selection[^\n]*\n(.*?)\n--- End of approved selection ---/s', $prompt, $m);

    expect(json_decode($m[1], true))->toBe($input)->and($m[1])->not->toContain("\n")->and($prompt)->not->toContain('denylist_terms');

    $withTerms = (new ProfileJobWordingPromptV4)->userPrompt($input, ['Secret Client']);

    expect($withTerms)->toContain('denylist_terms')->toContain('Secret Client');
});

it('keeps what the model needs of a fact and leaves out what it cannot use', function () {
    $fact = SlimWordingFacts::fact(candidateFact(3) + ['support_only' => true]);

    expect($fact)->toMatchArray(['key' => 'project-3-what-it-is', 'visibility' => 'restricted', 'project' => 'System 3', 'skills' => ['Laravel', 'Vue.js'], 'support_only' => true])
        ->and($fact['statement'])->toBe(candidateFact(3)['statement'])
        ->and($fact['also_attached_to'])->toBe(['Other project (Acme)'])
        ->and($fact)->not->toHaveKeys(['role_dates', 'metric', 'job_match_annotations', 'attribution', 'fact_type']);

    // A metric's scope note or guardrail, and a fact that is not an ordinary bullet, are kept.
    $metric = SlimWordingFacts::fact([...candidateFact(1), 'fact_type' => 'metric', 'metric' => ['value' => '85%', 'guardrail' => 'Only reporting time.']]);

    expect($metric['metric']['guardrail'])->toBe('Only reporting time.')->and($metric['fact_type'])->toBe('metric');
});

it('sends a fact once: the summary lists the key of one a bullet already shows', function () {
    $input = SlimWordingFacts::input(fifteenBulletInput());
    $inBullets = collect($input['experience'][0]['bullet_groups'])->flatMap(fn ($g) => array_column($g['career_facts'], 'key'))->all();

    // Facts 0 and 1 are only in the summary; 2 through 11 are in bullet groups too.
    expect($input['summary_evidence_keys'])->toHaveCount(12)->and(array_column($input['summary_evidence'], 'key'))->toBe(['project-1-what-it-is', 'project-2-what-it-is'])
        ->and(array_intersect(array_column($input['summary_evidence'], 'key'), $inBullets))->toBe([]);
});

it('cuts the prompt for a 15-bullet resume to a fraction of the measured one, and keeps it inside a token budget', function () {
    $raw = fifteenBulletInput();
    $slim = SlimWordingFacts::input($raw);
    $before = (new ProfileJobWordingPromptV3)->systemPrompt().(new ProfileJobWordingPromptV3)->userPrompt($raw, []);
    $after = (new ProfileJobWordingPromptV4)->systemPrompt().(new ProfileJobWordingPromptV4)->userPrompt($slim, []);

    // About four characters a token. The live prompt was 21.6k tokens; this one is well under half of what V3 made of the same evidence.
    expect(strlen($after))->toBeLessThan(strlen($before) * 0.5)->and(strlen($after) / 4)->toBeLessThan(10_000);
});

it('drops only what does not apply from the instructions, and keeps every claim restriction', function () {
    $v3 = (new ProfileJobWordingPromptV3)->systemPrompt();
    $v4 = (new ProfileJobWordingPromptV4)->systemPrompt();

    expect(strlen($v4))->toBeLessThan(strlen($v3))
        ->and($v3)->toContain('Direct target-term guidance')->toContain('Reference resume context')
        ->and($v4)->not->toContain('## Direct target-term guidance')->not->toContain('## The target-term deny list')->not->toContain('## Reference resume context')
        // The rules that protect the claims are inherited word for word.
        ->and($v4)->toContain('## Never invent, never compute, never overstate')->toContain('## Restricted evidence')->toContain('## Fidelity to the approved profile and to the evidence')
        ->toContain('## Support-only evidence')->toContain('## Editorial plan')->toContain('## This resume is tailored to one job')
        // The summary is asked for specialization and systems, not ownership, and for no figure.
        ->and($v4)->toContain('specialization, systems and problems')->toContain('Do not state a number or percentage in the summary');

    foreach (['## Never invent, never compute, never overstate', '## Restricted evidence', '## Fidelity to the approved profile and to the evidence'] as $section) {
        $text = fn (string $all) => trim(preg_split('/^## /m', substr($all, strpos($all, $section) + 3))[0] ?? '');
        expect($text($v4))->toBe($text($v3));
    }
});

it('hands the lean payload to the model in a real generation and still stores what it saw', function () {
    $s = CompositionScenario::create();
    $synthesis = CompositionScenario::approvedProfile($s);
    $seen = new stdClass;
    app()->instance(GeneratesResumeWording::class, CompositionScenario::wording($seen));

    $variant = app(GenerateResumeVariant::class)->generateForJob($s['analysis'], $synthesis);
    $facts = collect($seen->input['experience'])->flatMap(fn ($r) => $r['bullet_groups'])->flatMap(fn ($g) => $g['career_facts']);

    expect($variant->wording_prompt_version)->toBe('resume-wording-profile-job-v4')
        ->and($facts->every(fn ($f) => isset($f['key'], $f['statement'], $f['visibility']) && ! array_key_exists('role_dates', $f) && ! array_key_exists('attribution', $f)))->toBeTrue()
        ->and($variant->wording_input_snapshot['summary_evidence_keys'])->toBe($seen->input['summary_evidence_keys'])
        ->and($seen->system)->not->toContain('## Reference resume context');
});
