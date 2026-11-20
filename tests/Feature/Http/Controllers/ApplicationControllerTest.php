<?php

use App\Models\Application;
use App\Models\JobPosting;
use App\Models\WorkflowRun;

it('dispatches an inspection and redirects to the application show page', function () {
    $jobPosting = JobPosting::factory()->create(['source_url' => 'https://job-boards.greenhouse.io/acme/jobs/1']);

    $response = $this->post(route('jobs.applications.store', $jobPosting));

    $application = Application::firstWhere('job_posting_id', $jobPosting->id);
    expect($application)->not->toBeNull();
    $response->assertRedirect(route('applications.show', $application));
    expect(WorkflowRun::count())->toBe(1);
});

it('returns a clean 422 for a job posting with no application URL, creating no state', function () {
    $jobPosting = JobPosting::factory()->create(['source_url' => null]);

    $this->post(route('jobs.applications.store', $jobPosting))->assertStatus(422);

    expect(Application::count())->toBe(0);
});

it('a double dispatch redirects to the same Application without creating a second one', function () {
    $jobPosting = JobPosting::factory()->create(['source_url' => 'https://example.com']);

    $this->post(route('jobs.applications.store', $jobPosting));
    $this->post(route('jobs.applications.store', $jobPosting));

    expect(Application::count())->toBe(1)
        ->and(WorkflowRun::count())->toBe(1);
});

it('shows the application inspection page with initial state', function () {
    $jobPosting = JobPosting::factory()->create(['source_url' => 'https://example.com']);
    $application = Application::factory()->for($jobPosting, 'jobPosting')->create();

    $response = $this->get(route('applications.show', $application));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('applications/show')
        ->where('application.id', $application->id)
        ->where('application.job_posting.id', $jobPosting->id)
        ->where('inspection.application_id', $application->id)
        ->where('inspection.workflow_run', null)
        ->where('inspection.latest_result', null));
});

it('exposes application_id on the JobPosting show page once one exists', function () {
    $jobPosting = JobPosting::factory()->create();
    $application = Application::factory()->for($jobPosting, 'jobPosting')->create();

    $response = $this->get(route('jobs.show', $jobPosting));

    $response->assertInertia(fn ($page) => $page->where('job.application_id', $application->id));
});

it('exposes a null application_id on the JobPosting show page when none exists yet', function () {
    $jobPosting = JobPosting::factory()->create();

    $response = $this->get(route('jobs.show', $jobPosting));

    $response->assertInertia(fn ($page) => $page->where('job.application_id', null));
});

// ---- Phase 1: a person's own application URL ----

use App\Enums\AgentRunStatus;
use App\Models\AgentRun;
use Illuminate\Testing\TestResponse;

function startInspection(JobPosting $job, array $input = []): TestResponse
{
    return test()->post(route('jobs.applications.store', $job), $input);
}

it('saves a URL the person supplied as their override and starts inspecting it', function () {
    $job = JobPosting::factory()->create(['source_url' => 'https://jobicy.com/jobs/1']);

    startInspection($job, ['application_url' => 'https://boards.greenhouse.io/acme/jobs/9?gh_jid=9&utm_source=x#apply'])->assertRedirect();

    $application = Application::firstWhere('job_posting_id', $job->id);
    expect($application->application_url)->toBe('https://boards.greenhouse.io/acme/jobs/9?gh_jid=9')
        ->and($application->application_url_origin)->toBe('user')
        ->and($application->application_url_set_at)->not->toBeNull()
        ->and($job->fresh()->source_url)->toBe('https://jobicy.com/jobs/1', 'the source URL is never touched')
        ->and(WorkflowRun::count())->toBe(1);
});

it('lets a job with no source URL be inspected when a URL is supplied', function () {
    $job = JobPosting::factory()->create(['source_url' => null]);

    startInspection($job, ['application_url' => 'https://jobs.lever.co/acme/1'])->assertRedirect();

    expect(WorkflowRun::count())->toBe(1);
});

it('refuses an unsafe or malformed URL, saving and starting nothing', function (string $url) {
    $job = JobPosting::factory()->create(['source_url' => 'https://jobicy.com/jobs/1']);

    startInspection($job, ['application_url' => $url])->assertSessionHasErrors('application_url');

    expect(Application::count())->toBe(0)->and(WorkflowRun::count())->toBe(0);
})->with([
    'javascript' => 'javascript:alert(1)',
    'file' => 'file:///etc/passwd',
    'metadata address' => 'http://169.254.169.254/latest/meta-data/',
    'localhost' => 'http://localhost:8000/admin',
    'private' => 'https://192.168.1.1/apply',
    'credentials' => 'https://admin:pw@example.com/',
    'no scheme' => 'example.com/apply',
]);

it('replaces an earlier override, and clearing drops it so the next inspection starts from the listing again', function () {
    $job = JobPosting::factory()->create(['source_url' => 'https://jobicy.com/jobs/1']);
    startInspection($job, ['application_url' => 'https://first.example.com/apply']);
    $application = Application::firstWhere('job_posting_id', $job->id);
    WorkflowRun::query()->update(['status' => 'succeeded']);

    startInspection($job, ['application_url' => 'https://second.example.com/apply']);
    expect($application->fresh()->application_url)->toBe('https://second.example.com/apply');
    WorkflowRun::query()->update(['status' => 'succeeded']);

    startInspection($job, ['clear_application_url' => true])->assertRedirect();
    $application->refresh();
    expect($application->application_url)->toBeNull()
        ->and($application->application_url_origin)->toBeNull()
        ->and($application->application_url_set_at)->toBeNull();
});

it('clears a URL the inspector resolved, too, not only one a person entered', function () {
    $job = JobPosting::factory()->create(['source_url' => 'https://jobicy.com/jobs/1']);
    $application = Application::factory()->for($job, 'jobPosting')->create(['application_url' => 'https://boards.greenhouse.io/a/jobs/1', 'application_url_origin' => 'resolved']);

    startInspection($job, ['clear_application_url' => true])->assertRedirect();

    expect($application->fresh()->application_url)->toBeNull();
});

it('refuses to change the URL while an inspection is queued or running, and leaves everything as it was', function () {
    $job = JobPosting::factory()->create(['source_url' => 'https://jobicy.com/jobs/1']);
    startInspection($job);
    $application = Application::firstWhere('job_posting_id', $job->id);

    startInspection($job, ['application_url' => 'https://other.example.com/apply'])->assertStatus(409);
    startInspection($job, ['clear_application_url' => true])->assertStatus(409);

    expect($application->fresh()->application_url)->toBeNull()
        ->and(WorkflowRun::count())->toBe(1);
});

it('rejects asking for a URL and clearing it in the same request', function () {
    $job = JobPosting::factory()->create(['source_url' => 'https://jobicy.com/jobs/1']);

    startInspection($job, ['application_url' => 'https://a.example.com/x', 'clear_application_url' => true])->assertSessionHasErrors('application_url');
});

it('still treats a plain repeated click as a no-op on the running inspection', function () {
    $job = JobPosting::factory()->create(['source_url' => 'https://jobicy.com/jobs/1']);

    startInspection($job)->assertRedirect();
    startInspection($job)->assertRedirect();
    startInspection($job)->assertRedirect();

    expect(WorkflowRun::count())->toBe(1)->and(AgentRun::where('status', AgentRunStatus::Queued)->count())->toBe(1);
});

it('keeps a person\'s URL through a later inspection that resolves somewhere else, and uses it as the start URL', function () {
    config(['services.browser_worker.token' => 'test-token']);
    $job = JobPosting::factory()->create(['source_url' => 'https://jobicy.com/jobs/1']);
    startInspection($job, ['application_url' => 'https://mine.example.com/apply']);

    $claim = $this->withHeader('Authorization', 'Bearer test-token')->postJson('/api/worker/agent-runs/claim', ['worker_identity' => 'w'])->assertOk();

    expect($claim->json('start_url'))->toBe('https://mine.example.com/apply')
        ->and($claim->json('start_origin'))->toBe('user')
        ->and($claim->json('source_url'))->toBe('https://jobicy.com/jobs/1');

    $run = AgentRun::firstOrFail();
    $this->withHeader('Authorization', 'Bearer test-token')->postJson("/api/worker/agent-runs/{$run->id}/result", [
        'status' => 'succeeded', 'inspection_outcome' => 'unsupported', 'stop_reason' => 'form_without_adapter',
        'fields' => [], 'resolved_url' => 'https://somewhere-else.example.com/form',
    ])->assertOk();

    expect(Application::firstOrFail()->application_url)->toBe('https://mine.example.com/apply');
});

it('starts the first inspection on a one-click open, and never a second over an existing one', function () {
    $job = JobPosting::factory()->create(['source_url' => 'https://jobicy.com/jobs/1']);

    startInspection($job, ['only_if_none' => true])->assertRedirect();
    expect(WorkflowRun::count())->toBe(1);

    // Finished and failed attempts alike count as "already inspected": a stale list must not start another.
    WorkflowRun::query()->update(['status' => 'failed']);
    startInspection($job, ['only_if_none' => true])->assertRedirect();
    WorkflowRun::query()->update(['status' => 'succeeded']);
    startInspection($job, ['only_if_none' => true])->assertRedirect();

    expect(WorkflowRun::count())->toBe(1);

    // The explicit action still starts a fresh one.
    startInspection($job)->assertRedirect();
    expect(WorkflowRun::count())->toBe(2);
});

// ---- First-inspection setup: nothing exists until the person confirms, and one confirmation is one run ----

it('creates nothing until the person confirms: viewing the opportunity and its drawer starts no run', function () {
    $job = JobPosting::factory()->create(['source_url' => 'https://jobicy.com/jobs/1', 'disposition' => 'pursued']);

    test()->get(route('opportunities.show', $job))->assertOk();
    test()->get(route('opportunities.index', ['inspect' => $job->id]))->assertOk();

    expect(Application::count())->toBe(0)->and(WorkflowRun::count())->toBe(0);
});

it('first inspection from the listing: one draft Application, one run, no link saved', function () {
    $job = JobPosting::factory()->create(['source_url' => 'https://jobicy.com/jobs/1']);

    startInspection($job, ['only_if_none' => true])->assertRedirect();

    $application = Application::firstOrFail();
    expect(Application::count())->toBe(1)
        ->and(WorkflowRun::count())->toBe(1)
        ->and($application->application_url)->toBeNull()
        ->and($application->application_url_origin)->toBeNull();
});

it('first inspection with a supplied link: one draft Application, one run, saved as the person\'s own and started from it', function () {
    $job = JobPosting::factory()->create(['source_url' => 'https://jobicy.com/jobs/1']);
    $url = 'https://jobs.ashbyhq.com/nerdwallet/32c62385-07d5-4d6d-960e-12c05ce0cbef/application';

    startInspection($job, ['only_if_none' => true, 'application_url' => $url])->assertRedirect();

    $application = Application::firstOrFail();
    expect(Application::count())->toBe(1)
        ->and(WorkflowRun::count())->toBe(1)
        ->and($application->application_url)->toBe($url)
        ->and($application->application_url_origin)->toBe('user');
});

it('a repeated confirmation of the same first inspection (double click, second tab) creates no second run', function () {
    $job = JobPosting::factory()->create(['source_url' => 'https://jobicy.com/jobs/1']);
    $url = 'https://jobs.ashbyhq.com/nerdwallet/32c62385-07d5-4d6d-960e-12c05ce0cbef/application';

    startInspection($job, ['only_if_none' => true, 'application_url' => $url])->assertRedirect();
    startInspection($job, ['only_if_none' => true, 'application_url' => $url])->assertRedirect();
    startInspection($job, ['only_if_none' => true])->assertRedirect();

    expect(Application::count())->toBe(1)->and(WorkflowRun::count())->toBe(1);
});

it('a rejected link on the first inspection creates nothing', function () {
    $job = JobPosting::factory()->create(['source_url' => 'https://jobicy.com/jobs/1']);

    startInspection($job, ['only_if_none' => true, 'application_url' => 'http://localhost/x'])->assertSessionHasErrors('application_url');

    expect(Application::count())->toBe(0)->and(WorkflowRun::count())->toBe(0);
});

it('a second first-inspection confirmation with a different link changes nothing, while an explicit change during the run is still a 409', function () {
    $job = JobPosting::factory()->create(['source_url' => 'https://jobicy.com/jobs/1']);

    startInspection($job, ['only_if_none' => true, 'application_url' => 'https://jobs.lever.co/acme/1'])->assertRedirect();

    // Another tab confirms the setup with another link: idempotent, not an error, and it must not overwrite the first.
    startInspection($job, ['only_if_none' => true, 'application_url' => 'https://other.example.com/apply'])->assertRedirect();
    expect(Application::count())->toBe(1)
        ->and(WorkflowRun::count())->toBe(1)
        ->and(Application::firstOrFail()->application_url)->toBe('https://jobs.lever.co/acme/1');

    // Only the explicit "change the link" action (no only_if_none) is refused while the run is active.
    startInspection($job, ['application_url' => 'https://other.example.com/apply'])->assertStatus(409);
    expect(Application::firstOrFail()->application_url)->toBe('https://jobs.lever.co/acme/1')->and(WorkflowRun::count())->toBe(1);
});
