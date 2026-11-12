<?php

use App\Models\ResumeVariant;
use App\Models\Skill;
use App\Support\JobTailoring\ProfileJobAdapter;
use App\Support\ProfileSynthesis\ReferenceSelection;
use App\Support\ResumeDocument\GenerateResumeDocument;
use App\Support\ResumeDocument\SkillPresentation;
use Tests\Support\CompositionCases as Cases;
use Tests\Support\CompositionScenario;

/**
 * Job-aware Technical Skills. A layout only regroups and orders skills that approved evidence already selected: it never
 * adds a skill, and a skill neither the layout nor the shared map names is never shown.
 */
function skillInputs(string $layout, array $skillsByFact, ?array $factOverrides = null): array
{
    [$effective, $payload, $roles] = Cases::forJob('architect');
    $effective['skills_layout'] = $layout;
    $ids = [];
    $next = 1;

    foreach ($payload['career_facts'] as &$fact) {
        $fact['skills'] = [];

        foreach ($skillsByFact[$fact['key']] ?? [] as $name) {
            $ids[$name] ??= $next++;
            $fact['skills'][] = ['id' => $ids[$name], 'name' => $name, 'category' => 'build_technology'];
        }

        if (isset($factOverrides[$fact['key']])) {
            $fact['attribution']['role_id'] = $factOverrides[$fact['key']];
        }
    }
    unset($fact);

    $result = app(ReferenceSelection::class)->build($effective, $payload);
    $byId = array_flip($ids);

    return array_map(fn (array $s) => $byId[$s['skill_id']], $result['content']['skills']);
}

function groupedSkills(string $layout, array $names): array
{
    $groups = [];

    foreach ($names as $name) {
        $entry = SkillPresentation::for($name, $layout);

        if ($entry !== null) {
            $groups[$entry[0]][] = $entry[1];
        }
    }

    return $groups;
}

it('picks the AI grouping for an AI engineering job and the full-stack grouping for any other', function () {
    $layoutFor = function (string $title, array $capabilities = []) {
        [$effective] = Cases::forJob('architect');

        return app(ProfileJobAdapter::class)->adapt($effective, ['facts' => [], 'findings' => [], 'capabilities' => $capabilities, 'title' => $title])['skills_layout'];
    };

    expect($layoutFor('AI Portfolio Solution Architect'))->toBe('ai')
        ->and($layoutFor('Software Engineer', ['ai_llm' => 0.8]))->toBe('ai')
        ->and($layoutFor('Full Stack Engineer', ['ai_llm' => 0.2]))->toBe('fullstack')
        ->and($layoutFor('Software Engineer Staff - Enterprise IT'))->toBe('fullstack');
});

it('leads an AI resume with demonstrated LLM work and names the grounding the record states', function () {
    $names = skillInputs('ai', [
        'l-what' => ['OpenAI', 'Deterministic AI boundaries'], 'b-what' => ['React', 'TypeScript'], 'n-what' => ['Node.js', 'BigQuery', 'PostgreSQL'],
        'b-unify' => ['API integration & design', 'Jira'], 'c-obs' => ['AWS'],
    ]);
    $groups = groupedSkills('ai', $names);

    expect(array_keys($groups))->toBe(['AI & Retrieval', 'Languages & Frameworks', 'Integration & Architecture', 'Data & Cloud'])
        ->and($groups['AI & Retrieval'])->toContain('LLM integration', 'Grounded retrieval')
        ->and($groups['Data & Cloud'])->toContain('BigQuery', 'PostgreSQL', 'AWS');
});

it('groups a full-stack resume as languages and frameworks, APIs and integration, data, and cloud and workflow', function () {
    $names = skillInputs('fullstack', [
        'b-what' => ['React', 'TypeScript'], 'n-what' => ['Node.js', 'BigQuery', 'PostgreSQL'], 'b-unify' => ['REST APIs', 'Jira', 'Webhooks'], 'c-obs' => ['AWS', 'Git'],
    ]);
    $groups = groupedSkills('fullstack', $names);

    expect(array_keys($groups))->toBe(['Languages & Frameworks', 'APIs & Integration', 'Data & Databases', 'Cloud & Workflow'])
        ->and($groups['APIs & Integration'])->toContain('REST APIs', 'Webhooks', 'Jira')
        ->and($groups['Data & Databases'])->toContain('PostgreSQL', 'BigQuery');
});

it('never shows a skill no fact carries or no layout names', function () {
    $names = skillInputs('fullstack', ['b-what' => ['React'], 'n-what' => ['Kubernetes', 'Terraform', 'Deterministic AI boundaries']]);

    // Kubernetes and Terraform are in no layout; the AI tag is not placed by the full-stack layout; Python/Prisma have no evidence.
    expect($names)->toBe(['React'])->not->toContain('Python', 'Prisma', 'Kubernetes');
});

it('ranks a skill used in a professional role above one only a personal project carries', function () {
    config(['resume.skill_presentation.layouts.fullstack.max_per_group' => 1]);
    // MySQL is carried by an employer's fact; SQLite only by a fact with no employer (a personal project).
    $names = skillInputs('fullstack', ['n-what' => ['SQLite'], 'b-what' => ['MySQL']], ['n-what' => null]);

    expect($names)->toContain('MySQL')->not->toContain('SQLite');
});

it('declares every layout consistently: skills have labels and groups are declared', function () {
    foreach (['tailored', 'fullstack', 'ai'] as $layout) {
        $groups = SkillPresentation::groups($layout);
        $categories = (array) config("resume.skill_presentation.layouts.{$layout}.categories");

        expect($groups)->toHaveCount(4);

        foreach ($categories as $skill => $group) {
            expect(SkillPresentation::for($skill, $layout))->not->toBeNull($skill)->and($groups)->toContain($group);
        }
    }

    // Soft presentation preferences: a handful to eight per group; the reference grouping is untouched.
    expect(SkillPresentation::maxPerGroup('ai'))->toBeBetween(3, 8)->and(SkillPresentation::maxPerGroup('fullstack'))->toBeBetween(3, 8)
        ->and(SkillPresentation::for('Deterministic AI boundaries'))->toBeNull()->and(SkillPresentation::groups())->toHaveCount(6)
        ->and(SkillPresentation::for('Python'))->toBeNull();
});

it('renders a job resume with the layout stored for it, and an older one exactly as before', function () {
    $s = CompositionScenario::create();
    $synthesis = CompositionScenario::approvedProfile($s);
    $made = function (array $tailoring) use ($s, $synthesis): array {
        $variant = ResumeVariant::factory()->create([
            'career_profile_id' => $s['career']->id, 'resume_profile_synthesis_id' => $synthesis->id, 'job_analysis_id' => $s['analysis']->id,
            'selection_input_snapshot' => ['tailoring' => $tailoring],
        ]);

        foreach ([['OpenAI', 1], ['TypeScript', 2], ['BigQuery', 3]] as [$name, $order]) {
            $skill = Skill::query()->firstOrCreate(['career_profile_id' => $s['career']->id, 'slug' => strtolower($name)], ['name' => $name, 'category' => 'build_technology']);
            $variant->skillSelections()->create(['skill_id' => $skill->id, 'name' => $name, 'category' => 'build_technology', 'display_order' => $order]);
        }

        return array_map(fn ($g) => $g->label, (new GenerateResumeDocument)->generate($variant->fresh(['skillSelections']))->skills);
    };

    expect($made(['skills_layout' => 'ai']))->toBe(['AI & Retrieval', 'Languages & Frameworks', 'Data & Cloud'])
        // No stored layout: the grouping a job resume has always had.
        ->and($made([]))->toBe(['Languages & Frameworks', 'AI & Data']);
});
