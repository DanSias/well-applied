<?php

use App\Enums\AgentRunStatus;
use App\Enums\WorkflowStatus;
use App\Models\AgentRun;
use App\Models\Submission;
use App\Models\WorkerHeartbeat;
use App\Models\WorkflowRun;
use App\Support\ApplicationPreparation\DispatchApplicationPreparation;
use App\Support\ApplicationPreparation\PreparationException;
use App\Support\ApplicationPreparation\PresentPreparationPanel;
use Tests\Support\PreparationFixtures as F;

function panelFor($job): array
{
    return app(PresentPreparationPanel::class)->present($job->fresh());
}

it('says a preparation can start, where it would go, and which resume it would use', function () {
    $s = F::scenario();

    $panel = panelFor($s['job']);

    expect($panel['can_prepare'])->toBeTrue()
        ->and($panel['problem'])->toBeNull()
        ->and($panel['destination'])->toBe(['host' => 'job-boards.greenhouse.io', 'path' => '/example/jobs/123456'])
        ->and($panel['resumes']['default_id'])->toBe($s['variant']->id)
        ->and($panel['resumes']['default_kind'])->toBe('current')
        ->and(collect($panel['resumes']['options'])->pluck('id')->all())->toBe([$s['variant']->id])
        ->and($panel['state'])->toBeNull();
});

it('defaults to the final resume over the current one, and lists the eligible variants newest first', function () {
    $s = F::scenario();
    $second = F::variant($s['job'], $s['profile'], pdf: F::PDF_OTHER);
    F::markFinal($s['job'], $s['variant']);

    $panel = panelFor($s['job']);

    expect($panel['resumes']['default_id'])->toBe($s['variant']->id)
        ->and($panel['resumes']['default_kind'])->toBe('final')
        ->and(collect($panel['resumes']['options'])->pluck('id')->all())->toBe([$second->id, $s['variant']->id])
        ->and(collect($panel['resumes']['options'])->firstWhere('id', $s['variant']->id)['is_final'])->toBeTrue();
});

it('explains why it cannot start, instead of offering a button that will fail', function (array $job, string $code, bool $noResume = false) {
    $s = F::scenario(job: $job);
    if ($noResume) {
        $s['variant']->forceDelete();
    }

    $panel = panelFor($s['job']);

    expect($panel['can_prepare'])->toBeFalse()
        ->and($panel['problem']['code'])->toBe($code)
        ->and($panel['problem']['message'])->not->toBe('')
        ->and($panel['state'])->toBeNull();
})->with([
    'no URL' => [['source_url' => ''], 'no_application_url'],
    'an unsupported site' => [['source_url' => 'https://www.linkedin.com/jobs/view/1'], 'unsupported_ats_host'],
    'plain http' => [['source_url' => 'http://boards.greenhouse.io/x/jobs/1'], 'unsupported_application_url'],
    'no resume' => [[], 'no_resume', true],
]);

it('never chooses or creates a resume for the person', function () {
    $s = F::scenario();
    $s['variant']->forceDelete();

    $panel = panelFor($s['job']);

    expect($panel['resumes']['default_id'])->toBeNull()->and($panel['resumes']['options'])->toBe([]);
    expect(fn () => app(DispatchApplicationPreparation::class)->dispatch($s['job']))->toThrow(PreparationException::class);
});

it('blocks a second start while an attempt is active, and re-opens once it is terminal or cancelled', function () {
    $s = F::scenario();
    ['workflowRun' => $run] = app(DispatchApplicationPreparation::class)->dispatch($s['job']);

    expect(panelFor($s['job'])['can_prepare'])->toBeFalse();

    $this->postJson("/preparations/{$run->id}/cancel")->assertOk();
    $afterCancel = panelFor($s['job']);
    expect($afterCancel['can_prepare'])->toBeTrue()
        ->and($afterCancel['state']['workflow_run']['status'])->toBe('cancelled')
        ->and($afterCancel['state']['attempt']['status'])->toBe('cancelled');
});

it('keeps each attempt on its own, newest first, scoped to this job', function () {
    $s = F::scenario();
    $other = F::variant($s['job'], $s['profile'], pdf: F::PDF_OTHER);
    $stranger = F::scenario(['name' => 'Someone Else'], ['source_url' => 'https://boards.greenhouse.io/x/jobs/9']);

    app(DispatchApplicationPreparation::class)->dispatch($s['job'], $s['variant']);
    AgentRun::query()->update(['status' => AgentRunStatus::Failed, 'finished_at' => now()]);
    WorkflowRun::query()->update(['status' => WorkflowStatus::Failed]);
    app(DispatchApplicationPreparation::class)->dispatch($s['job'], $other);
    app(DispatchApplicationPreparation::class)->dispatch($stranger['job']);

    $attempts = panelFor($s['job'])['state']['attempts'];

    expect($attempts)->toHaveCount(2)
        ->and(collect($attempts)->pluck('resume_variant_id')->all())->toBe([$other->id, $s['variant']->id])
        ->and(collect($attempts)->pluck('status')->all())->toBe(['queued', 'failed'])
        ->and(collect($attempts)->pluck('resume_sha256')->all())->toBe([hash('sha256', F::PDF_OTHER), hash('sha256', F::PDF)])
        // Earlier attempts do not show another's outcome.
        ->and($attempts[1]['outcome'])->toBeNull();
});

it('carries no applicant value, resume byte or path anywhere in the panel', function () {
    $s = F::scenario();
    app(DispatchApplicationPreparation::class)->dispatch($s['job']);

    $json = json_encode(panelFor($s['job']));

    expect($json)->not->toContain(F::EMAIL)->not->toContain('Alex')->not->toContain('Springfield')
        ->not->toContain('application-preparations/')->not->toContain('%PDF');
    expect(Submission::count())->toBe(0);
});

it('hands the workspace its panel, and keeps the inspection and submission sections', function () {
    $s = F::scenario();
    $s['job']->forceFill(['career_profile_id' => $s['profile']->id])->save();

    $this->get("/opportunities/{$s['job']->id}")->assertOk()->assertInertia(fn ($page) => $page
        ->component('opportunities/show')
        ->has('preparation.resumes.options', 1)
        ->where('preparation.can_prepare', true)
        ->has('inspection_job')->has('application')->has('tailored_resume'));
});

it('reports the preparation worker\'s presence apart from the inspector\'s', function () {
    config(['services.browser_worker.poll_interval_seconds' => 5]);
    WorkerHeartbeat::factory()->create(['identity' => 'prep-1', 'worker_type' => 'application_preparer', 'last_seen_at' => now()]);

    $this->getJson('/browser-worker/status?worker_type=application_preparer')->assertOk()->assertJson(['online' => true, 'identity' => 'prep-1']);
    $this->getJson('/browser-worker/status')->assertOk()->assertJson(['online' => false, 'identity' => null]);
    $this->getJson('/browser-worker/status?worker_type=something-else')->assertOk()->assertJson(['online' => false]);
});
