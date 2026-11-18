<?php

use App\Enums\JobDisposition;
use App\Models\AgentRun;
use App\Models\Application;
use App\Models\CareerProfile;
use App\Models\JobPosting;
use App\Models\Submission;
use App\Models\WorkflowRun;
use Tests\Support\PreparationFixtures as F;

it('queues a preparation for a pursued job and returns the safe state', function () {
    $s = F::scenario();

    $response = $this->postJson("/jobs/{$s['job']->id}/preparations")->assertStatus(202);

    expect($response->json('created'))->toBeTrue()
        ->and($response->json('preparation.workflow_run.status'))->toBe('pending')
        ->and($response->json('preparation.attempt.status'))->toBe('queued')
        ->and($response->json('preparation.attempt.inputs'))->toBe([
            'resume_variant_id' => $s['variant']->id,
            'resume_sha256' => hash('sha256', F::PDF),
            'applicant_keys' => ['email', 'first_name', 'full_name', 'github_url', 'last_name', 'location', 'phone', 'portfolio_url'],
            'omitted_keys' => ['current_company' => 'no_source', 'linkedin_url' => 'no_source'],
        ])
        ->and($response->getContent())->not->toContain(F::EMAIL)->not->toContain('Alex')->not->toContain('Springfield')
        ->and(Submission::count())->toBe(0);
});

it('returns the attempt already in flight (200) instead of creating another', function () {
    $s = F::scenario();
    $first = $this->postJson("/jobs/{$s['job']->id}/preparations")->assertStatus(202);

    $second = $this->postJson("/jobs/{$s['job']->id}/preparations")->assertOk();

    expect($second->json('created'))->toBeFalse()
        ->and($second->json('workflow_run_id'))->toBe($first->json('workflow_run_id'))
        ->and(WorkflowRun::count())->toBe(1)->and(AgentRun::count())->toBe(1);
});

it('lets a person choose the resume, and refuses a resume that is not for this job', function () {
    $s = F::scenario();
    $other = F::variant($s['job'], $s['profile'], pdf: F::PDF_OTHER);
    $stranger = F::scenario(['name' => 'Someone Else'], ['source_url' => 'https://boards.greenhouse.io/x/jobs/1']);

    $this->postJson("/jobs/{$s['job']->id}/preparations", ['resume_variant_id' => $stranger['variant']->id])
        ->assertStatus(422)->assertJson(['reason' => 'resume_not_for_job']);
    $this->postJson("/jobs/{$s['job']->id}/preparations", ['resume_variant_id' => 99999])
        ->assertStatus(422)->assertJson(['reason' => 'resume_not_found']);
    $this->postJson("/jobs/{$s['job']->id}/preparations", ['resume_variant_id' => 'abc'])->assertStatus(422);
    expect(AgentRun::count())->toBe(0);

    $ok = $this->postJson("/jobs/{$s['job']->id}/preparations", ['resume_variant_id' => $s['variant']->id])->assertStatus(202);
    expect($ok->json('preparation.attempt.inputs.resume_variant_id'))->toBe($s['variant']->id)->not->toBe($other->id);
});

it('refuses a job that is not being pursued, belongs to another profile, or cannot be prepared', function () {
    $s = F::scenario();
    $s['job']->forceFill(['disposition' => JobDisposition::Shortlisted])->save();
    $this->postJson("/jobs/{$s['job']->id}/preparations")->assertStatus(422);

    $s['job']->forceFill(['disposition' => JobDisposition::Pursued])->save();
    $foreign = JobPosting::factory()->create([
        'career_profile_id' => CareerProfile::factory()->create()->id,
        'source_url' => 'https://job-boards.greenhouse.io/x/jobs/2', 'disposition' => JobDisposition::Pursued,
    ]);
    $this->postJson("/jobs/{$foreign->id}/preparations")->assertNotFound();

    $s['job']->update(['source_url' => 'https://www.linkedin.com/jobs/view/1']);
    $this->postJson("/jobs/{$s['job']->id}/preparations")->assertStatus(422)->assertJson(['reason' => 'unsupported_ats_host']);

    expect(AgentRun::count())->toBe(0)->and(Submission::count())->toBe(0);
});

it('shows read-only state, execution apart from outcome, and never applicant values', function () {
    ['run' => $run, 'job' => $job] = F::queuedRun();
    config(['services.preparer_worker.token' => 'preparer-token', 'services.browser_worker.token' => 'x']);
    $application = Application::where('job_posting_id', $job->id)->sole();

    $this->getJson("/applications/{$application->id}/preparation")->assertOk()
        ->assertJsonPath('workflow_run.status', 'pending')->assertJsonPath('attempt.status', 'queued')->assertJsonPath('latest_result', null);

    $h = ['Authorization' => 'Bearer preparer-token', 'X-Worker-Identity' => 'prep-1'];
    $this->withHeaders($h)->postJson('/api/worker/preparer/agent-runs/claim', ['worker_identity' => 'prep-1'])->assertOk();
    $this->withHeaders($h)->postJson("/api/worker/preparer/agent-runs/{$run->id}/heartbeat", ['phase' => 'uploading'])->assertOk();
    $this->getJson("/applications/{$application->id}/preparation")->assertJsonPath('attempt.status', 'running')->assertJsonPath('attempt.phase', 'uploading');

    $this->withHeaders($h)->postJson("/api/worker/preparer/agent-runs/{$run->id}/result", F::delivery(F::report($run->fresh())))->assertOk();
    $view = $this->getJson("/applications/{$application->id}/preparation")->assertOk();

    // Execution succeeded; the report's outcome says a person still has to answer something.
    expect($view->json('workflow_run.status'))->toBe('succeeded')
        ->and($view->json('attempt.status'))->toBe('succeeded')
        ->and($view->json('attempt.phase'))->toBeNull()
        ->and($view->json('latest_result.outcome'))->toBe('input_required')
        ->and($view->json('latest_result.reviewable'))->toBeTrue()
        ->and($view->json('latest_result.upload.http_status'))->toBe(201)
        ->and($view->json('latest_result'))->not->toHaveKeys(['phase', 'phase_at', 'report_sha256x'])
        ->and($view->getContent())->not->toContain(F::EMAIL)->not->toContain('Springfield')->not->toContain('application-preparations/')
        ->and(Submission::count())->toBe(0);
});

it('does not show another profile\'s application', function () {
    $s = F::scenario();
    $foreign = Application::factory()->for(JobPosting::factory()->create(['career_profile_id' => CareerProfile::factory()->create()->id]), 'jobPosting')->create();

    $this->getJson("/applications/{$foreign->id}/preparation")->assertNotFound();
});
