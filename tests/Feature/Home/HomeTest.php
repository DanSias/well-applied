<?php

use App\Enums\ClarificationSessionStatus;
use App\Models\CareerProfile;
use App\Models\ClarificationSession;
use App\Models\JobAnalysis;
use App\Models\JobMatch;
use App\Models\JobPosting;
use App\Models\JobResumeSelection;
use App\Models\ResumeProfile;
use App\Models\ResumeProfileSynthesis;
use App\Models\ResumeVariant;
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
        ->where('overview.opportunities', 0)
        ->where('attention.items', [])
        ->where('continue', []));
});

it('shows an empty state when there are no opportunities', function () {
    homeProfile();

    $this->get('/')->assertInertia(fn (Assert $page) => $page
        ->where('has_profile', true)
        ->where('overview.opportunities', 0)
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
        ->where('overview.opportunities', 3)
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

    $this->get('/')->assertInertia(fn (Assert $page) => $page->where('overview.opportunities', 1));
});

it('links a job with resume work straight to its current resume and asks for a review', function () {
    $profile = homeProfile();
    $posting = homePosting($profile);
    $first = homeResume($profile, $posting);
    $revision = homeResume($profile, $posting, $first);
    JobResumeSelection::query()->create(['job_posting_id' => $posting->id, 'current_resume_variant_id' => $revision->id]);

    $this->get('/')->assertInertia(fn (Assert $page) => $page
        ->where('overview.resumes_in_progress', 1)
        ->where('overview.resumes_final', 0)
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

it('sends each Overview count to a list that shows exactly those opportunities', function () {
    $profile = homeProfile();
    $working = homePosting($profile);
    homeResume($profile, $working);
    $final = homePosting($profile);
    $finalResume = homeResume($profile, $final);
    JobResumeSelection::query()->create(['job_posting_id' => $final->id, 'current_resume_variant_id' => $finalResume->id, 'preferred_resume_variant_id' => $finalResume->id]);
    homePosting($profile); // no resume at all

    $overview = $this->get('/')->viewData('page')['props']['overview'];
    $listed = fn (string $query) => $this->get("/jobs{$query}")->viewData('page')['props'];

    $inProgress = $listed('?resume=in-progress');
    $finals = $listed('?resume=final');
    $raw = $listed('?triage=raw');

    expect($inProgress['pagination']['total'])->toBe($overview['resumes_in_progress'])
        ->and(array_column($inProgress['jobs'], 'id'))->toBe([$working->id])
        ->and($inProgress['filters']['resume'])->toBe('in-progress')
        ->and($finals['pagination']['total'])->toBe($overview['resumes_final'])
        ->and(array_column($finals['jobs'], 'id'))->toBe([$final->id])
        ->and($raw['pagination']['total'])->toBe($overview['not_analyzed'])
        ->and($listed('')['pagination']['total'])->toBe($overview['opportunities'])
        // An unknown value is ignored and echoed as "all", never silently half-applied.
        ->and($listed('?resume=bogus')['filters']['resume'])->toBe('all')
        // Filters combine with search and the other filters.
        ->and($listed('?resume=in-progress&q=zzz-no-such-title')['pagination']['total'])->toBe(0);
});
