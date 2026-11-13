<?php

use App\Enums\ClarificationSessionStatus;
use App\Enums\DecisionSource;
use App\Enums\DismissalReason;
use App\Enums\JobDisposition;
use App\Models\CareerProfile;
use App\Models\ClarificationSession;
use App\Models\JobAnalysis;
use App\Models\JobMatch;
use App\Models\JobPosting;
use App\Models\JobResumeSelection;
use App\Models\ResumeProfile;
use App\Models\ResumeProfileSynthesis;
use App\Models\ResumeVariant;
use App\Support\JobDecisions\RecordJobDecision;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Home is derived entirely from existing records. Helpers are `home`-prefixed: Pest loads every Feature file into one
 * global namespace.
 */
function homeProfile(): CareerProfile
{
    return CareerProfile::factory()->create();
}

function homePosting(CareerProfile $profile, array $attributes = []): JobPosting
{
    return JobPosting::factory()->create(['career_profile_id' => $profile->id] + $attributes);
}

/** A profile-assisted resume (and the analysis it hangs off) for a posting. */
function homeResume(CareerProfile $profile, JobPosting $posting, ?ResumeVariant $parent = null): ResumeVariant
{
    $analysis = $posting->jobAnalyses()->first() ?? JobAnalysis::factory()->for($posting, 'jobPosting')->create();
    $resumeProfile = ResumeProfile::query()->first() ?? ResumeProfile::query()->create([
        'career_profile_id' => $profile->id, 'slug' => 'home-profile', 'name' => 'Home profile', 'role_family' => 'engineering',
    ]);
    $synthesis = ResumeProfileSynthesis::query()->first() ?? ResumeProfileSynthesis::query()->create([
        'career_profile_id' => $profile->id, 'resume_profile_id' => $resumeProfile->id, 'version' => 1, 'status' => 'approved',
        'evidence_revision' => 0, 'input_hash' => 'x', 'content' => [],
    ]);

    return ResumeVariant::factory()->create([
        'career_profile_id' => $profile->id,
        'job_match_id' => JobMatch::factory()->create(['job_analysis_id' => $analysis->id, 'career_profile_id' => $profile->id])->id,
        'job_analysis_id' => $analysis->id,
        'resume_profile_synthesis_id' => $synthesis->id,
        'parent_variant_id' => $parent?->id,
    ]);
}

it('renders the Home page at the root route', function () {
    homeProfile();

    $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page->component('home'));
    expect(route('home', absolute: false))->toBe('/');
});

it('keeps Career Data reachable at its own route', function () {
    $this->get(route('career-data.index'))->assertOk();
    expect(route('career-data.index', absolute: false))->toBe('/career-data');
});

it('shows a graceful empty state when no career profile exists', function () {
    $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('has_profile', false)
        ->where('overview.jobs', 0)
        ->where('attention.items', [])
        ->where('continue', []));
});

it('shows an empty state when there are no opportunities', function () {
    homeProfile();

    $this->get('/')->assertInertia(fn (Assert $page) => $page
        ->where('has_profile', true)
        ->where('overview.jobs', 0)
        ->where('continue', []));
});

it('counts opportunities by their real triage stage and summarizes un-analyzed ones as a single attention line', function () {
    $profile = homeProfile();
    homePosting($profile);
    homePosting($profile);
    $analyzed = homePosting($profile);
    JobAnalysis::factory()->for($analyzed, 'jobPosting')->create(['generated_at' => now()]);
    homePosting(CareerProfile::factory()->create()); // another profile's job is never counted

    $this->get('/')->assertInertia(fn (Assert $page) => $page
        ->where('overview.jobs', 3)
        ->where('overview.not_analyzed', 2)
        ->where('attention.items.0.kind', 'summary')
        ->where('attention.items.0.filter', 'raw')
        ->has('continue', 1)
        ->where('continue.0.job_id', $analyzed->id)
        ->where('continue.0.status.label', 'Analyzed')
        ->where('continue.0.resume_variant_id', null));
});

it('does not count closed postings', function () {
    $profile = homeProfile();
    homePosting($profile);
    homePosting($profile, ['status' => 'closed']);

    $this->get('/')->assertInertia(fn (Assert $page) => $page->where('overview.jobs', 1));
});

it('links a job with resume work straight to its current resume and asks for a review', function () {
    $profile = homeProfile();
    $posting = homePosting($profile);
    $first = homeResume($profile, $posting);
    $revision = homeResume($profile, $posting, $first);
    JobResumeSelection::query()->create(['job_posting_id' => $posting->id, 'current_resume_variant_id' => $revision->id]);

    $this->get('/')->assertInertia(fn (Assert $page) => $page
        // Nobody has pursued this job, so its resume work is not an opportunity's resume: it is counted apart.
        ->where('overview.resumes_in_progress', 0)
        ->where('overview.existing_work_to_review', 1)
        ->where('continue.0.resume_variant_id', $revision->id)
        ->where('continue.0.status.label', 'Resume in progress')
        ->where('continue.0.action', 'Open resume')
        ->where('attention.items.0.kind', 'resume')
        ->where('attention.items.0.resume_variant_id', $revision->id)
        ->where('attention.items.0.message', 'Not reviewed against the job yet.'));
});

it('counts a final resume as ready and stops flagging it', function () {
    $profile = homeProfile();
    $posting = homePosting($profile);
    $resume = homeResume($profile, $posting);
    JobResumeSelection::query()->create(['job_posting_id' => $posting->id, 'current_resume_variant_id' => $resume->id, 'preferred_resume_variant_id' => $resume->id]);
    homePursue($posting);

    $this->get('/')->assertInertia(fn (Assert $page) => $page
        ->where('overview.resumes_final', 1)
        ->where('overview.resumes_in_progress', 0)
        ->where('continue.0.status.label', 'Final resume chosen')
        ->where('attention.items', []));
});

it('surfaces a pending clarification as the most pressing item', function () {
    $profile = homeProfile();
    $posting = homePosting($profile);
    $analysis = JobAnalysis::factory()->for($posting, 'jobPosting')->create();
    $match = JobMatch::factory()->create(['job_analysis_id' => $analysis->id, 'career_profile_id' => $profile->id]);
    $resumeProfile = ResumeProfile::query()->create(['career_profile_id' => $profile->id, 'slug' => 's', 'name' => 'S', 'role_family' => 'x']);
    ClarificationSession::query()->create([
        'career_profile_id' => $profile->id, 'resume_profile_id' => $resumeProfile->id, 'job_posting_id' => $posting->id,
        'job_analysis_id' => $analysis->id, 'job_match_id' => $match->id, 'pipeline_run_id' => 'run', 'mode' => 'quick',
        'status' => ClarificationSessionStatus::Pending,
    ]);

    $this->get('/')->assertInertia(fn (Assert $page) => $page
        ->where('attention.items.0.kind', 'questions')
        ->where('attention.items.0.job_id', $posting->id)
        ->where('continue.0.status.label', 'Questions waiting'));
});

function homePursue(JobPosting $posting): void
{
    app(RecordJobDecision::class)->record($posting, JobDisposition::Pursued, DecisionSource::User);
}

it('counts jobs by decision, and resumes only on the jobs that are pursued', function () {
    $profile = homeProfile();
    $service = app(RecordJobDecision::class);
    $inbox = homePosting($profile);
    $shortlisted = homePosting($profile);
    $dismissed = homePosting($profile);
    $working = homePosting($profile);
    $final = homePosting($profile);
    $service->record($shortlisted, JobDisposition::Shortlisted, DecisionSource::User);
    $service->record($dismissed, JobDisposition::Dismissed, DecisionSource::User, DismissalReason::Other);
    homePursue($working);
    homePursue($final);
    homeResume($profile, $working);
    $finalResume = homeResume($profile, $final);
    JobResumeSelection::query()->create(['job_posting_id' => $final->id, 'current_resume_variant_id' => $finalResume->id, 'preferred_resume_variant_id' => $finalResume->id]);
    // Resume work on a job nobody pursued must not be counted as an opportunity's resume.
    homeResume($profile, $inbox);

    $this->get('/')->assertInertia(fn (Assert $page) => $page
        ->where('overview.jobs', 5)
        ->where('overview.awaiting', 1)
        ->where('overview.shortlisted', 1)
        ->where('overview.pursued', 2)
        ->where('overview.resumes_in_progress', 1)
        ->where('overview.resumes_final', 1)
        ->where('overview.existing_work_to_review', 1));
});

it('sends each Overview count to a list that shows exactly those records', function () {
    $profile = homeProfile();
    $service = app(RecordJobDecision::class);
    $inbox = homePosting($profile);
    $shortlisted = homePosting($profile);
    $working = homePosting($profile);
    $final = homePosting($profile);
    $service->record($shortlisted, JobDisposition::Shortlisted, DecisionSource::User);
    homePursue($working);
    homePursue($final);
    homeResume($profile, $working);
    $finalResume = homeResume($profile, $final);
    JobResumeSelection::query()->create(['job_posting_id' => $final->id, 'current_resume_variant_id' => $finalResume->id, 'preferred_resume_variant_id' => $finalResume->id]);

    $overview = $this->get('/')->viewData('page')['props']['overview'];
    $jobs = fn (string $query) => $this->get("/jobs{$query}")->viewData('page')['props'];
    $opportunities = fn (string $query) => $this->get("/opportunities{$query}")->viewData('page')['props'];

    expect($jobs('?disposition=all')['pagination']['total'])->toBe($overview['jobs'])
        ->and($jobs('')['pagination']['total'])->toBe($overview['awaiting'])
        ->and($jobs('?disposition=shortlist')['pagination']['total'])->toBe($overview['shortlisted'])
        ->and(count($opportunities('')['opportunities']))->toBe($overview['pursued'])
        ->and(array_column($opportunities('?resume=in-progress')['opportunities'], 'id'))->toBe([$working->id])
        ->and(count($opportunities('?resume=in-progress')['opportunities']))->toBe($overview['resumes_in_progress'])
        ->and(array_column($opportunities('?resume=final')['opportunities'], 'id'))->toBe([$final->id])
        ->and(count($opportunities('?resume=final')['opportunities']))->toBe($overview['resumes_final'])
        ->and($jobs('?disposition=all&triage=raw')['pagination']['total'])->toBe($overview['not_analyzed']);
});

it('adds an attention item for jobs with existing work that nobody has pursued, linking to Opportunities', function () {
    $profile = homeProfile();
    $posting = homePosting($profile);
    homeResume($profile, $posting);

    $this->get('/')->assertInertia(fn (Assert $page) => $page
        ->where('attention.items', fn ($items) => collect($items)->contains(fn ($i) => $i['kind'] === 'review' && $i['action'] === 'Review existing work')));

    homePursue($posting);

    $this->get('/')->assertInertia(fn (Assert $page) => $page
        ->where('overview.existing_work_to_review', 0)
        ->where('attention.items', fn ($items) => ! collect($items)->contains(fn ($i) => $i['kind'] === 'review')));
});

it('keeps Overview and Opportunities counts in step as a job is pursued and returned to the Shortlist', function () {
    $profile = homeProfile();
    $job = homePosting($profile);
    app(RecordJobDecision::class)->record($job, JobDisposition::Shortlisted, DecisionSource::User);
    homeResume($profile, $job);
    $overview = fn () => $this->get('/')->viewData('page')['props']['overview'];
    $inProgress = fn () => count($this->get('/opportunities?resume=in-progress')->viewData('page')['props']['opportunities']);

    expect($overview())->toMatchArray(['pursued' => 0, 'shortlisted' => 1, 'resumes_in_progress' => 0, 'existing_work_to_review' => 1])->and($inProgress())->toBe(0);

    $this->post("/jobs/{$job->id}/decision", ['disposition' => 'pursued']);

    expect($overview())->toMatchArray(['pursued' => 1, 'shortlisted' => 0, 'resumes_in_progress' => 1, 'existing_work_to_review' => 0])->and($inProgress())->toBe(1);

    $this->post("/jobs/{$job->id}/decision", ['disposition' => 'shortlisted']);

    expect($overview())->toMatchArray(['pursued' => 0, 'shortlisted' => 1, 'resumes_in_progress' => 0, 'existing_work_to_review' => 1])->and($inProgress())->toBe(0);
});
