<?php

use App\Enums\AgentRunFailureCategory;
use App\Enums\AgentRunStatus;
use App\Enums\ApplicationStatus;
use App\Enums\WorkflowStatus;
use App\Enums\WorkflowType;
use App\Exceptions\InvalidResumePdfException;
use App\Exceptions\ResumePdfPageBudgetExceededException;
use App\Models\AgentRun;
use App\Models\Application;
use App\Models\JobPosting;
use App\Models\Submission;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Support\ApplicationPreparation\ClaimNextPreparationRun;
use App\Support\ApplicationPreparation\DispatchApplicationPreparation;
use App\Support\ApplicationPreparation\PreparationArtifacts;
use App\Support\ApplicationPreparation\PreparationException;
use App\Support\ResumeDocument\GenerateResumePdf;
use Illuminate\Support\Facades\Storage;
use Tests\Support\PreparationFixtures as F;

function prepDispatch(JobPosting $job, $variant = null): array
{
    return app(DispatchApplicationPreparation::class)->dispatch($job, $variant);
}

it('creates the workflow, step and a queued preparer run on the job\'s one Application', function () {
    $s = F::scenario();

    ['workflowRun' => $run, 'created' => $created] = prepDispatch($s['job']);

    $application = Application::where('job_posting_id', $s['job']->id)->sole();
    $agentRun = AgentRun::sole();

    expect($created)->toBeTrue()
        ->and($application->status)->toBe(ApplicationStatus::Draft)
        ->and($run->application_id)->toBe($application->id)
        ->and($run->workflow_type)->toBe(WorkflowType::ApplicationPreparation)
        ->and($run->status)->toBe(WorkflowStatus::Pending)
        ->and($run->workflowSteps)->toHaveCount(1)
        ->and($run->workflowSteps->first()->step_key)->toBe('prepare_application')
        ->and($agentRun->agent_type)->toBe('application_preparer')
        ->and($agentRun->status)->toBe(AgentRunStatus::Queued)
        ->and(Submission::count())->toBe(0);
});

it('reuses the Application that inspection already created', function () {
    $s = F::scenario();
    $existing = Application::factory()->for($s['job'], 'jobPosting')->create();

    prepDispatch($s['job']);

    expect(Application::count())->toBe(1)
        ->and(WorkflowRun::sole()->application_id)->toBe($existing->id);
});

it('prefers the final resume, then the current one, and honours an explicit choice', function () {
    $s = F::scenario();
    $other = F::variant($s['job'], $s['profile'], pdf: F::PDF_OTHER);
    $third = F::variant($s['job'], $s['profile'], pdf: "%PDF-1.7\nthird\n%%EOF");

    // Nothing marked: the current resume (the newest generated).
    prepDispatch($s['job']);
    expect(AgentRun::latest('id')->first()->input_summary['resume_variant_id'])->toBe($third->id);
    AgentRun::query()->update(['status' => AgentRunStatus::Succeeded]);
    WorkflowRun::query()->update(['status' => WorkflowStatus::Succeeded]);

    // Marked final wins over current.
    F::markCurrent($s['job'], $third);
    F::markFinal($s['job'], $other);
    prepDispatch($s['job']);
    expect(AgentRun::latest('id')->first()->input_summary['resume_variant_id'])->toBe($other->id);
    AgentRun::query()->update(['status' => AgentRunStatus::Succeeded]);
    WorkflowRun::query()->update(['status' => WorkflowStatus::Succeeded]);

    // An explicit choice wins over both.
    prepDispatch($s['job'], $s['variant']);
    $latest = AgentRun::latest('id')->first();
    expect($latest->input_summary['resume_variant_id'])->toBe($s['variant']->id)
        ->and($latest->input_summary['resume_sha256'])->toBe(hash('sha256', F::PDF));
});

it('refuses a resume made for a different job or profile', function () {
    $s = F::scenario();
    $stranger = F::scenario(['name' => 'Someone Else']);

    expect(fn () => prepDispatch($s['job'], $stranger['variant']))->toThrow(PreparationException::class);

    try {
        prepDispatch($s['job'], $stranger['variant']);
    } catch (PreparationException $e) {
        expect($e->reason)->toBe('resume_not_for_job');
    }
    expect(AgentRun::count())->toBe(0);
});

it('refuses a job with no resume, no URL, a plain-http URL or an unsupported host, creating nothing', function (array $job, ?string $reason, bool $resume = true) {
    $s = F::scenario(job: $job, cachePdf: $resume);
    if (! $resume) {
        $s['variant']->delete();
    }

    try {
        prepDispatch($s['job']->fresh());
        $thrown = null;
    } catch (PreparationException $e) {
        $thrown = $e->reason;
    }

    expect($thrown)->toBe($reason)
        ->and(AgentRun::count())->toBe(0)
        ->and(WorkflowRun::count())->toBe(0);
})->with([
    'no URL' => [['source_url' => ''], 'no_application_url'],
    'http' => [['source_url' => 'http://job-boards.greenhouse.io/x/jobs/1'], 'unsupported_application_url'],
    'credentials' => [['source_url' => 'https://user:pw@boards.greenhouse.io/x/jobs/1'], 'unsupported_application_url'],
    'a port' => [['source_url' => 'https://boards.greenhouse.io:8443/x/jobs/1'], 'unsupported_application_url'],
    'another ATS' => [['source_url' => 'https://example.myworkdayjobs.com/en/job/1'], 'unsupported_ats_host'],
    'a lookalike host' => [['source_url' => 'https://boards.greenhouse.io.evil.test/x/jobs/1'], 'unsupported_ats_host'],
]);

it('refuses a job with no tailored resume', function () {
    $s = F::scenario();
    $s['variant']->forceDelete();

    expect(fn () => prepDispatch($s['job']))->toThrow(PreparationException::class, 'no tailored resume');
});

it('returns the run already in flight instead of starting a second', function () {
    $s = F::scenario();

    ['workflowRun' => $first, 'created' => $c1] = prepDispatch($s['job']);
    ['workflowRun' => $second, 'created' => $c2] = prepDispatch($s['job']);

    expect($c1)->toBeTrue()->and($c2)->toBeFalse()
        ->and($second->id)->toBe($first->id)
        ->and(WorkflowRun::count())->toBe(1)
        ->and(AgentRun::count())->toBe(1)
        ->and(Storage::disk('local')->directories(PreparationArtifacts::ROOT))->toHaveCount(1);

    // Also while it is running.
    AgentRun::query()->update(['status' => AgentRunStatus::Running, 'worker_identity' => 'w', 'claim_expires_at' => now()->addMinute()]);
    WorkflowRun::query()->update(['status' => WorkflowStatus::Running]);
    expect(prepDispatch($s['job'])['created'])->toBeFalse();
});

it('starts a brand-new attempt after a finished one, never reopening it', function (AgentRunStatus $ended, WorkflowStatus $workflow) {
    $s = F::scenario();
    ['workflowRun' => $first] = prepDispatch($s['job']);
    $firstAgent = AgentRun::sole();
    $firstAgent->update(['status' => $ended, 'finished_at' => now()]);
    $first->update(['status' => $workflow, 'finished_at' => now()]);

    ['workflowRun' => $second, 'created' => $created] = prepDispatch($s['job']);

    expect($created)->toBeTrue()
        ->and($second->id)->not->toBe($first->id)
        ->and(AgentRun::count())->toBe(2)
        ->and($firstAgent->fresh()->status)->toBe($ended)
        ->and(Application::count())->toBe(1)
        ->and(Storage::disk('local')->directories(PreparationArtifacts::ROOT))->toHaveCount(2);
})->with([
    'succeeded' => [AgentRunStatus::Succeeded, WorkflowStatus::Succeeded],
    'failed' => [AgentRunStatus::Failed, WorkflowStatus::Failed],
]);

it('settles a lapsed claim as failed at dispatch, and only then lets an explicit new attempt start', function () {
    $s = F::scenario();
    prepDispatch($s['job']);
    AgentRun::query()->update(['status' => AgentRunStatus::Running, 'worker_identity' => 'w', 'claim_expires_at' => now()->subMinute()]);
    WorkflowRun::query()->update(['status' => WorkflowStatus::Running]);
    WorkflowStep::query()->update(['status' => WorkflowStatus::Running]);

    ['created' => $created] = prepDispatch($s['job']);

    $old = AgentRun::oldest('id')->first();
    expect($created)->toBeTrue()
        ->and($old->status)->toBe(AgentRunStatus::Failed)
        ->and($old->failure_category)->toBe(AgentRunFailureCategory::ClaimTimeout)
        ->and($old->workflowStep->workflowRun->status)->toBe(WorkflowStatus::Failed)
        ->and(AgentRun::count())->toBe(2);
});

it('never reruns on its own: a lapsed or failed attempt leaves no queued work behind', function () {
    $s = F::scenario();
    prepDispatch($s['job']);
    AgentRun::query()->update(['status' => AgentRunStatus::Running, 'worker_identity' => 'w', 'claim_expires_at' => now()->subMinute()]);

    app(ClaimNextPreparationRun::class)->recoverExpired();

    expect(AgentRun::sole()->status)->toBe(AgentRunStatus::Failed)
        ->and(app(ClaimNextPreparationRun::class)->claim('w2'))->toBeNull()
        ->and(AgentRun::count())->toBe(1)
        ->and(WorkflowRun::count())->toBe(1);
});

it('freezes the inputs: private, hashed, and described without values', function () {
    Storage::fake('public');
    $s = F::scenario();
    prepDispatch($s['job']);
    $run = AgentRun::sole();
    $in = $run->input_summary;
    $dir = "application-preparations/{$run->id}";

    expect($in['schema_version'])->toBe(1)
        ->and($in['resume_variant_id'])->toBe($s['variant']->id)
        ->and($in['resume_sha256'])->toBe(hash('sha256', F::PDF))
        ->and($in['resume_bytes'])->toBe(strlen(F::PDF))
        ->and($in['application_url'])->toBe('https://job-boards.greenhouse.io/example/jobs/123456')
        ->and($in['applicant_keys'])->toBe(['email', 'first_name', 'full_name', 'github_url', 'last_name', 'location', 'phone', 'portfolio_url'])
        ->and($in['omitted_keys'])->toBe(['current_company' => 'no_source', 'linkedin_url' => 'no_source']);

    Storage::disk('local')->assertExists("{$dir}/input.json");
    Storage::disk('local')->assertExists("{$dir}/resume-".hash('sha256', F::PDF).'.pdf');
    Storage::disk('public')->assertDirectoryEmpty('');

    // The ordinary summary never carries a value: not in the database row, not anywhere it could be logged from.
    $row = json_encode($run->fresh()->getAttributes());
    expect($row)->not->toContain(F::EMAIL)->not->toContain('Alex')->not->toContain('Springfield')->not->toContain('010 0199');

    $stored = app(PreparationArtifacts::class)->readInput($run);
    expect($stored['applicant']['email'])->toBe(F::EMAIL)
        ->and($stored['applicant']['first_name'])->toBe('Alex')
        ->and($stored['applicant'])->not->toHaveKeys(['linkedin_url', 'current_company'])
        ->and($stored['resume']['sha256'])->toBe(hash('sha256', F::PDF))
        ->and($stored['answers'])->toBe([]);
});

it('keeps the frozen inputs even when the profile and resume change afterwards', function () {
    $s = F::scenario();
    prepDispatch($s['job']);
    $run = AgentRun::sole();

    $s['profile']->update(['email' => 'changed@example.test', 'name' => 'Changed Name']);
    F::cachePdf($s['variant'], F::PDF_OTHER);

    $artifacts = app(PreparationArtifacts::class);
    expect($artifacts->readInput($run->fresh())['applicant']['email'])->toBe(F::EMAIL)
        ->and($artifacts->readResume($run->fresh()))->toBe(F::PDF);
});

it('renders and validates a resume that is not cached, and refuses ones that fail', function () {
    $s = F::scenario(cachePdf: false);

    // Over the page budget: refused with the reason, nothing written.
    $this->mock(GenerateResumePdf::class)->shouldReceive('generate')->andThrow(new ResumePdfPageBudgetExceededException(3, 2));
    try {
        prepDispatch($s['job']);
    } catch (PreparationException $e) {
        expect($e->reason)->toBe('resume_over_length');
    }
    expect(AgentRun::count())->toBe(0)->and(Storage::disk('local')->allFiles(PreparationArtifacts::ROOT))->toBe([]);
});

it('refuses a rendered PDF that fails validation or that cannot be rendered', function (Throwable $failure, string $reason) {
    $s = F::scenario(cachePdf: false);
    $this->mock(GenerateResumePdf::class)->shouldReceive('generate')->andThrow($failure);

    try {
        prepDispatch($s['job']);
        $thrown = null;
    } catch (PreparationException $e) {
        $thrown = $e->reason;
    }

    expect($thrown)->toBe($reason)->and(AgentRun::count())->toBe(0)->and(WorkflowRun::count())->toBe(0);
})->with([
    'unparseable' => [fn () => new InvalidResumePdfException('bad', new Exception), 'resume_pdf_invalid'],
    'chromium failure' => [fn () => new RuntimeException('chrome crashed'), 'resume_pdf_failed'],
]);

it('rejects generated bytes that are not a PDF and never caches or uses them', function () {
    $s = F::scenario(cachePdf: false);
    $this->mock(GenerateResumePdf::class)->shouldReceive('generate')->andReturn('<html>not a pdf</html>');

    expect(fn () => prepDispatch($s['job']))->toThrow(PreparationException::class);
    expect(AgentRun::count())->toBe(0);
});

it('creates no Submission and never changes the job, however many attempts there are', function () {
    $s = F::scenario();
    $before = $s['job']->fresh()->only(['disposition', 'title', 'source_url']);

    prepDispatch($s['job']);
    AgentRun::query()->update(['status' => AgentRunStatus::Failed]);
    WorkflowRun::query()->update(['status' => WorkflowStatus::Failed]);
    prepDispatch($s['job']);

    expect(Submission::count())->toBe(0)
        ->and($s['job']->fresh()->only(['disposition', 'title', 'source_url']))->toBe($before);
});

it('removes what it wrote when the dispatch does not complete', function () {
    $s = F::scenario();
    // Force the failure after the files are written, inside the transaction.
    AgentRun::updating(fn () => throw new RuntimeException('boom'));

    try {
        prepDispatch($s['job']);
    } catch (RuntimeException) {
    }
    AgentRun::flushEventListeners();
    AgentRun::clearBootedModels();

    expect(AgentRun::count())->toBe(0)
        ->and(WorkflowRun::count())->toBe(0)
        ->and(Storage::disk('local')->allFiles(PreparationArtifacts::ROOT))->toBe([]);
});

// ---- Phase 1: an inspection's application URL does not change what preparation may open ----

it('still judges and opens only the job\'s source URL: an inspection\'s application URL neither unlocks nor redirects it', function () {
    $s = F::scenario();
    $application = Application::factory()->for($s['job'], 'jobPosting')->create();

    // A resolved or typed application URL on a supported host does not make an unsupported source URL preparable...
    $s['job']->update(['source_url' => 'https://example.myworkdayjobs.com/en/job/1']);
    $application->update(['application_url' => 'https://job-boards.greenhouse.io/acme/jobs/1', 'application_url_origin' => 'user']);

    expect(fn () => prepDispatch($s['job']->fresh()))->toThrow(PreparationException::class, 'Applications on this site cannot be prepared yet');
    expect(AgentRun::count())->toBe(0);

    // ...and on a supported source URL, the frozen input still names that source URL, not the application URL.
    $s['job']->update(['source_url' => 'https://job-boards.greenhouse.io/acme/jobs/1']);
    $application->update(['application_url' => 'https://elsewhere.example.com/apply']);

    expect(app(DispatchApplicationPreparation::class)->applicationUrl($s['job']->fresh()))->toBe('https://job-boards.greenhouse.io/acme/jobs/1');
});
