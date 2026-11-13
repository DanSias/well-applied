<?php

use App\Enums\ClarificationSessionStatus;
use App\Enums\DecisionSource;
use App\Enums\DismissalReason;
use App\Enums\JobDisposition;
use App\Enums\JobPostingLifecycleStatus;
use App\Models\Application;
use App\Models\CareerProfile;
use App\Models\ClarificationSession;
use App\Models\JobAnalysis;
use App\Models\JobDecision;
use App\Models\JobMatch;
use App\Models\JobPosting;
use App\Models\JobResumeSelection;
use App\Models\ResumeProfile;
use App\Models\ResumeProfileSynthesis;
use App\Models\ResumeVariant;
use App\Support\JobDecisions\JobDecisionException;
use App\Support\JobDecisions\RecordJobDecision;
use Illuminate\Support\Facades\DB;

function oppProfile(): CareerProfile
{
    return CareerProfile::factory()->create();
}

function oppJob(CareerProfile $profile, ?JobDisposition $to = null, array $attributes = []): JobPosting
{
    $job = JobPosting::factory()->create(['career_profile_id' => $profile->id] + $attributes);

    if ($to !== null) {
        app(RecordJobDecision::class)->record($job, $to, DecisionSource::User, $to === JobDisposition::Dismissed ? DismissalReason::Other : null);
    }

    return $job->fresh();
}

/** A profile-assisted resume for a job, like the Home tests build. */
function oppResume(CareerProfile $profile, JobPosting $job, bool $final = false): ResumeVariant
{
    $analysis = $job->jobAnalyses()->first() ?? JobAnalysis::factory()->for($job, 'jobPosting')->create();
    $resumeProfile = ResumeProfile::query()->first() ?? ResumeProfile::query()->create(['career_profile_id' => $profile->id, 'slug' => 'opp', 'name' => 'Opp', 'role_family' => 'engineering']);
    $synthesis = ResumeProfileSynthesis::query()->first() ?? ResumeProfileSynthesis::query()->create([
        'career_profile_id' => $profile->id, 'resume_profile_id' => $resumeProfile->id, 'version' => 1, 'status' => 'approved', 'evidence_revision' => 0, 'input_hash' => 'x', 'content' => [],
    ]);
    $variant = ResumeVariant::factory()->create([
        'career_profile_id' => $profile->id, 'job_analysis_id' => $analysis->id, 'resume_profile_synthesis_id' => $synthesis->id,
        'job_match_id' => JobMatch::factory()->create(['job_analysis_id' => $analysis->id, 'career_profile_id' => $profile->id])->id,
    ]);
    JobResumeSelection::query()->updateOrCreate(['job_posting_id' => $job->id], ['current_resume_variant_id' => $variant->id, 'preferred_resume_variant_id' => $final ? $variant->id : null]);

    return $variant;
}

function oppPage($test, string $query = ''): array
{
    return $test->get('/opportunities'.$query)->viewData('page')['props'];
}

/** Every table that holds work an opportunity's resume or inspection depends on. */
function oppWorkSnapshot(): array
{
    return collect(['job_analyses', 'job_matches', 'resume_variants', 'job_resume_selections', 'applications', 'clarification_sessions'])
        ->mapWithKeys(fn ($t) => [$t => DB::table($t)->orderBy('id')->get()->toJson()])->all();
}

it('lists only pursued jobs, newest pursuit first, and none from another profile', function () {
    $profile = oppProfile();
    $first = oppJob($profile, JobDisposition::Pursued);
    $second = oppJob($profile, JobDisposition::Pursued);
    oppJob($profile);
    oppJob($profile, JobDisposition::Shortlisted);
    oppJob($profile, JobDisposition::Dismissed);
    oppJob(oppProfile(), JobDisposition::Pursued);
    $this->travel(5)->minutes();
    $latest = oppJob($profile, JobDisposition::Pursued);

    $props = oppPage($this);

    expect(array_column($props['opportunities'], 'id'))->toBe([$latest->id, $second->id, $first->id])
        ->and($props['counts'])->toBe(['all' => 3, 'none' => 3, 'in-progress' => 0, 'final' => 0]);
});

it('is reachable at /opportunities', function () {
    oppProfile();

    $this->get('/opportunities')->assertOk()->assertInertia(fn ($page) => $page->component('opportunities/index'));
    expect(route('opportunities.index', absolute: false))->toBe('/opportunities');
});

it('shows a closed posting as closed without dropping the opportunity', function () {
    $profile = oppProfile();
    $job = oppJob($profile, JobDisposition::Pursued, ['status' => JobPostingLifecycleStatus::Closed, 'compensation_min' => 150000, 'compensation_max' => 180000, 'compensation_currency' => 'USD']);

    $row = oppPage($this)['opportunities'][0];

    expect($row['id'])->toBe($job->id)->and($row['posting_status'])->toBe('closed')->and($row['compensation_min'])->toBe(150000)->and($row['source_url'])->toBe($job->source_url);
});

it('presents the resume state the Overview counts, from the existing resume services', function () {
    $profile = oppProfile();
    $none = oppJob($profile, JobDisposition::Pursued);
    $working = oppJob($profile, JobDisposition::Pursued);
    $final = oppJob($profile, JobDisposition::Pursued);
    $workingVariant = oppResume($profile, $working);
    $finalVariant = oppResume($profile, $final, final: true);

    $rows = collect(oppPage($this)['opportunities'])->keyBy('id');

    expect($rows[$none->id]['resume'])->toBe(['state' => 'none', 'versions' => 0, 'current_variant_id' => null, 'final_variant_id' => null])
        ->and($rows[$working->id]['resume'])->toBe(['state' => 'in-progress', 'versions' => 1, 'current_variant_id' => $workingVariant->id, 'final_variant_id' => null])
        ->and($rows[$final->id]['resume'])->toBe(['state' => 'final', 'versions' => 1, 'current_variant_id' => $finalVariant->id, 'final_variant_id' => $finalVariant->id])
        ->and(array_column(oppPage($this, '?resume=in-progress')['opportunities'], 'id'))->toBe([$working->id])
        ->and(array_column(oppPage($this, '?resume=final')['opportunities'], 'id'))->toBe([$final->id])
        ->and(array_column(oppPage($this, '?resume=none')['opportunities'], 'id'))->toBe([$none->id])
        ->and(oppPage($this, '?resume=bogus')['filters']['resume'])->toBe('all')
        ->and(oppPage($this, '?resume=final')['counts'])->toBe(['all' => 3, 'none' => 1, 'in-progress' => 1, 'final' => 1]);
});

it('suggests one next step from what already exists', function () {
    $profile = oppProfile();
    $raw = oppJob($profile, JobDisposition::Pursued);
    $analyzed = oppJob($profile, JobDisposition::Pursued);
    JobAnalysis::factory()->for($analyzed, 'jobPosting')->create(['generated_at' => now()]);
    $working = oppJob($profile, JobDisposition::Pursued);
    oppResume($profile, $working);
    $final = oppJob($profile, JobDisposition::Pursued);
    oppResume($profile, $final, final: true);
    $asking = oppJob($profile, JobDisposition::Pursued);
    $analysis = JobAnalysis::factory()->for($asking, 'jobPosting')->create();
    $match = JobMatch::factory()->create(['job_analysis_id' => $analysis->id, 'career_profile_id' => $profile->id]);
    $resumeProfile = ResumeProfile::query()->first() ?? ResumeProfile::query()->create(['career_profile_id' => $profile->id, 'slug' => 's', 'name' => 'S', 'role_family' => 'x']);
    ClarificationSession::query()->create([
        'career_profile_id' => $profile->id, 'resume_profile_id' => $resumeProfile->id, 'job_posting_id' => $asking->id,
        'job_analysis_id' => $analysis->id, 'job_match_id' => $match->id, 'pipeline_run_id' => 'run', 'mode' => 'quick',
        'status' => ClarificationSessionStatus::Pending,
    ]);

    $next = collect(oppPage($this)['opportunities'])->pluck('next', 'id');

    expect($next[$raw->id])->toBe('analyze')
        ->and($next[$analyzed->id])->toBe('prepare_resume')
        ->and($next[$working->id])->toBe('continue_resume')
        ->and($next[$final->id])->toBe('review_final')
        ->and($next[$asking->id])->toBe('answer_questions');
});

it('carries the inspection state and labels an application as an inspection draft only', function () {
    $profile = oppProfile();
    $job = oppJob($profile, JobDisposition::Pursued);
    Application::factory()->create(['job_posting_id' => $job->id]);

    $row = oppPage($this)['opportunities'][0];

    expect($row['inspection']['application_id'])->not->toBeNull()->and($row['inspection']['state'])->toBe('not_inspected');
});

// ---- Pursuing and stopping, through the same decision endpoint the Jobs page uses.

it('pursues a job straight from the Inbox or from the Shortlist, and refuses a dismissed one until it is restored', function () {
    $profile = oppProfile();
    $inbox = oppJob($profile);
    $shortlisted = oppJob($profile, JobDisposition::Shortlisted);
    $dismissed = oppJob($profile, JobDisposition::Dismissed);

    $this->post("/jobs/{$inbox->id}/decision", ['disposition' => 'pursued'])->assertSessionHasNoErrors();
    $this->post("/jobs/{$shortlisted->id}/decision", ['disposition' => 'pursued'])->assertSessionHasNoErrors();
    $this->post("/jobs/{$dismissed->id}/decision", ['disposition' => 'pursued']);

    expect($inbox->fresh()->disposition)->toBe(JobDisposition::Pursued)->and($inbox->fresh()->pursued_at)->not->toBeNull()
        ->and($shortlisted->fresh()->disposition)->toBe(JobDisposition::Pursued)
        ->and(session('decision_result')['failed'])->toHaveCount(1)
        ->and($dismissed->fresh()->disposition)->toBe(JobDisposition::Dismissed);

    $this->post("/jobs/{$dismissed->id}/decision", ['disposition' => 'new']);
    $this->post("/jobs/{$dismissed->id}/decision", ['disposition' => 'pursued'])->assertSessionHasNoErrors();

    expect($dismissed->fresh()->disposition)->toBe(JobDisposition::Pursued);
});

it('never lets an automated rule pursue a job, from the Inbox or the Shortlist', function () {
    $profile = oppProfile();

    foreach ([oppJob($profile), oppJob($profile, JobDisposition::Shortlisted)] as $job) {
        expect(fn () => app(RecordJobDecision::class)->record($job, JobDisposition::Pursued, DecisionSource::Rule, rule: 'auto'))
            ->toThrow(JobDecisionException::class, 'Only a person');
        expect($job->fresh()->disposition)->not->toBe(JobDisposition::Pursued);
    }
});

it('stops pursuing a job by returning it to the Shortlist, keeping its history and every piece of resume work', function () {
    $profile = oppProfile();
    $job = oppJob($profile, JobDisposition::Shortlisted);
    $variant = oppResume($profile, $job, final: true);
    Application::factory()->create(['job_posting_id' => $job->id]);
    $this->post("/jobs/{$job->id}/decision", ['disposition' => 'pursued']);
    expect(count(oppPage($this)['opportunities']))->toBe(1);
    $work = oppWorkSnapshot();

    $this->post("/jobs/{$job->id}/decision", ['disposition' => 'shortlisted'])->assertSessionHasNoErrors();

    expect($job->fresh()->disposition)->toBe(JobDisposition::Shortlisted)->and($job->fresh()->pursued_at)->toBeNull()
        ->and(oppPage($this)['opportunities'])->toBe([])
        ->and(oppWorkSnapshot())->toBe($work)
        ->and($job->decisions->pluck('to_disposition')->map->value->all())->toBe(['shortlisted', 'pursued', 'shortlisted'])
        // The resume work is still reachable: it now shows under "existing work to review", with its final resume.
        ->and(oppPage($this)['review'][0])->toMatchArray(['job_id' => $job->id, 'final_resume_variant_id' => $variant->id, 'disposition' => 'shortlisted']);
});

// ---- Existing work: reviewed and pursued deliberately, never promoted for the user.

it('lists jobs with existing work that are not pursued, strongest evidence first, and changes nothing', function () {
    $profile = oppProfile();
    $draftOnly = oppJob($profile);
    Application::factory()->create(['job_posting_id' => $draftOnly->id]);
    $experiment = oppJob($profile, JobDisposition::Shortlisted);
    oppResume($profile, $experiment);
    $finalChosen = oppJob($profile);
    oppResume($profile, $finalChosen, final: true);
    $pursued = oppJob($profile, JobDisposition::Pursued);
    oppResume($profile, $pursued);
    oppJob($profile); // no work at all
    $before = [oppWorkSnapshot(), JobDecision::count(), JobPosting::pluck('disposition', 'id')->all()];

    $review = oppPage($this)['review'];

    expect(array_column($review, 'job_id'))->toBe([$finalChosen->id, $experiment->id, $draftOnly->id])
        ->and($review[2]['applications'][0]['status'])->toBe('draft')
        ->and([oppWorkSnapshot(), JobDecision::count(), JobPosting::pluck('disposition', 'id')->all()])->toBe($before);
});

it('does not promote anything on its own: existing work stays unpursued until a person pursues it', function () {
    $profile = oppProfile();
    $job = oppJob($profile);
    oppResume($profile, $job, final: true);
    Application::factory()->create(['job_posting_id' => $job->id]);

    $this->get('/opportunities')->assertOk();
    $this->get('/')->assertOk();
    $this->artisan('jobs:existing-work')->assertSuccessful();

    expect($job->fresh()->disposition)->toBe(JobDisposition::New)->and(oppPage($this)['opportunities'])->toBe([]);

    $this->post("/jobs/{$job->id}/decision", ['disposition' => 'pursued']);

    expect(array_column(oppPage($this)['opportunities'], 'id'))->toBe([$job->id])->and(oppPage($this)['review'])->toBe([]);
});
