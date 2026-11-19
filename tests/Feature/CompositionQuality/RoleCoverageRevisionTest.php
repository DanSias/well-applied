<?php

use App\Contracts\GeneratesResumeWording;
use App\Enums\CareerFactType;
use App\Enums\Verification;
use App\Enums\Visibility;
use App\Models\CareerFact;
use App\Models\ResumeVariant;
use App\Support\ResumeRevision\ReviseResumeVariant;
use App\Support\ResumeVariant\GenerateResumeVariant;
use Tests\Support\CompositionScenario;

/**
 * Role coverage is read from the resume's own bullets, so a resume that went through the revision pathway reports it the same
 * way a generated one does: a long role cut back by an edit is flagged, and an unedited revision keeps the resume's findings.
 */
function longRoleResume(): array
{
    $s = CompositionScenario::create();

    foreach ([
        'lead-own' => 'Managed multiple client projects from requirements gathering and solution design through development, launch, and ongoing support.',
        'lead-collab' => 'Worked directly with clients and stakeholders to translate business objectives into technical requirements, coordinate priorities, and deliver practical solutions.',
        'lead-founder' => 'Balanced hands-on development with project planning, client relationships, and delivery responsibilities across a diverse portfolio of client engagements.',
    ] as $key => $statement) {
        CareerFact::factory()->create([
            'career_profile_id' => $s['career']->id, 'key' => $key, 'statement' => $statement, 'visibility' => Visibility::Public, 'verification' => Verification::Verified,
            'fact_type' => CareerFactType::Bullet, 'attributable_type' => 'role', 'attributable_id' => $s['lead']->id,
        ]);
    }

    $synthesis = CompositionScenario::approvedProfile($s);
    app()->instance(GeneratesResumeWording::class, CompositionScenario::wording(new stdClass));

    return [$s, app(GenerateResumeVariant::class)->generateForJob($s['analysis'], $synthesis)];
}

function draftKeepingFirstBullet(ResumeVariant $variant, int $roleId): array
{
    $variant->load('experienceRoles.bullets', 'skillSelections');

    return [
        'summary' => (string) $variant->summary,
        'roles' => $variant->experienceRoles->sortBy('display_order')->map(fn ($r) => ['role_id' => $r->role_id, 'bullets' => $r->bullets->sortBy('display_order')->take($r->role_id === $roleId ? 1 : 99)->map(fn ($b) => ['id' => $b->id, 'text' => $b->text])->values()->all()])->values()->all(),
        'skills' => $variant->skillSelections->sortBy('display_order')->pluck('skill_id')->all(),
    ];
}

it('keeps a long role\'s coverage when the resume is revised, and flags it when an edit cuts the role back', function () {
    [$s, $variant] = longRoleResume();
    $generated = $variant->load('experienceRoles.bullets')->experienceRoles->firstWhere('role_id', $s['lead']->id);

    // As generated, the long role shows its responsibilities and nothing is flagged.
    expect($generated->bullets->count())->toBeGreaterThanOrEqual(3)
        ->and(collect($variant->selection_input_snapshot['tailoring']['composition'])->where('check', 'role_coverage'))->toBeEmpty();

    // An edit that cuts the role to one bullet is reported from the revised content, not carried over or lost.
    $revision = app(ReviseResumeVariant::class)->revise($s['posting'], $variant, draftKeepingFirstBullet($variant, $s['lead']->id));
    $findings = collect($revision->selection_input_snapshot['tailoring']['composition'])->where('check', 'role_coverage');

    expect($findings)->toHaveCount(1)
        ->and($findings->first()['message'])->toContain('Data Lead Developer')
        ->and($findings->first()['severity'])->toBe('review');
});
