<?php

use App\Enums\DecisionSource;
use App\Enums\DescriptionCompleteness;
use App\Enums\DismissalReason;
use App\Enums\JobDisposition;
use App\Models\Application;
use App\Models\CareerProfile;
use App\Models\JobAnalysis;
use App\Models\JobDecision;
use App\Models\JobMatch;
use App\Models\JobPosting;
use App\Models\JobResumeSelection;
use App\Models\ResumeProfile;
use App\Models\ResumeProfileSynthesis;
use App\Models\ResumeVariant;
use App\Support\JobDecisions\RecordJobDecision;
use App\Support\TailoredResume\TailoredResumePipeline;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ClarificationFixtures;

function wsProfile(): CareerProfile
{
    return CareerProfile::query()->oldest('id')->first() ?? CareerProfile::factory()->create();
}

function wsJob(?JobDisposition $to = JobDisposition::Pursued, array $attributes = []): JobPosting
{
    $job = JobPosting::factory()->create(['career_profile_id' => wsProfile()->id, 'description_completeness' => DescriptionCompleteness::Complete, ...$attributes]);

    if ($to !== null && $to !== JobDisposition::New) {
        app(RecordJobDecision::class)->record($job, $to, DecisionSource::User, $to === JobDisposition::Dismissed ? DismissalReason::Other : null);
    }

    return $job->fresh();
}

/** A profile-assisted resume for the job, optionally marked final, as the Opportunities tests build one. */
function wsResume(JobPosting $job, bool $final = false): ResumeVariant
{
    $profile = wsProfile();
    $analysis = $job->jobAnalyses()->first() ?? JobAnalysis::factory()->for($job, 'jobPosting')->create();
    $resumeProfile = ResumeProfile::query()->first() ?? ResumeProfile::query()->create(['career_profile_id' => $profile->id, 'slug' => 'ws', 'name' => 'Workspace', 'role_family' => 'engineering']);
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

it('renders the workspace for a pursued job, with the same next step as its list row', function () {
    $job = wsJob(attributes: ['title' => 'Staff Engineer', 'company' => 'Acme', 'source_url' => 'https://example.com/apply']);

    $row = collect($this->get('/opportunities')->viewData('page')['props']['opportunities'])->firstWhere('id', $job->id);

    $this->get(route('opportunities.show', $job))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('opportunities/show')
        ->where('opportunity.id', $job->id)
        ->where('opportunity.title', 'Staff Engineer')
        ->where('opportunity.next', $row['next'])
        ->where('opportunity.description', $job->description)
        ->where('inspection_job.id', $job->id)
        ->has('tailored_resume.state')
        ->has('resume_profiles'));
});

it('sends a job that is not pursued to its job page', function (JobDisposition $disposition) {
    $job = wsJob($disposition);

    $this->get(route('opportunities.show', $job))->assertRedirect(route('jobs.show', $job));
})->with([JobDisposition::New, JobDisposition::Shortlisted, JobDisposition::Dismissed]);

it('404s for an unknown job, and for a job of another profile', function () {
    $this->get('/opportunities/999999')->assertNotFound();
    $this->get('/opportunities/abc')->assertNotFound();

    wsProfile();
    $stranger = JobPosting::factory()->create(['career_profile_id' => CareerProfile::factory()->create()->id, 'disposition' => JobDisposition::Pursued]);

    $this->get(route('opportunities.show', $stranger))->assertNotFound();
});

it('presents a brand-new opportunity as not started, never as an error', function () {
    $job = wsJob();

    $this->get(route('opportunities.show', $job))->assertInertia(fn (Assert $page) => $page
        ->where('analysis', null)
        ->where('opportunity.resume.state', 'none')
        ->where('opportunity.resume.versions', 0)
        ->where('opportunity.inspection.state', 'not_inspected')
        ->where('inspection_job.application_id', null)
        ->where('inspection_job.inspection', null)
        ->where('tailored_resume.state', 'idle'));
});

it('carries a preview-only description warning through', function () {
    $job = wsJob(attributes: ['description_completeness' => DescriptionCompleteness::Preview]);

    $this->get(route('opportunities.show', $job))->assertInertia(fn (Assert $page) => $page
        ->where('opportunity.description_completeness', 'preview')
        ->where('tailored_resume.needs_confirmation', true));
});

it('shows the existing variants and selection, and points at the analysis and match pages', function () {
    $job = wsJob();
    $variant = wsResume($job, final: true);
    $analysis = $job->jobAnalyses()->first();
    $match = $analysis->jobMatches()->first();

    $this->get(route('opportunities.show', $job))->assertInertia(fn (Assert $page) => $page
        ->where('opportunity.resume.state', 'final')
        ->where('opportunity.resume.final_variant_id', $variant->id)
        ->where('opportunity.resume.current_variant_id', $variant->id)
        ->where('opportunity.next', 'review_final')
        ->where('analysis.id', $analysis->id)
        ->where('analysis.match.id', $match->id));
});

it('hands the inspection drawer the same state the list does, and picks up a new application', function () {
    $job = wsJob(attributes: ['source_url' => 'https://example.com/apply']);
    $application = Application::factory()->create(['job_posting_id' => $job->id]);

    $this->get(route('opportunities.show', $job))->assertInertia(fn (Assert $page) => $page
        ->where('inspection_job.application_id', $application->id)
        ->where('inspection_job.inspection.application_id', $application->id)
        ->where('opportunity.inspection.application_id', $application->id));
});

it('starts an inspection from the workspace and comes back to it, without a second request queueing twice', function () {
    $job = wsJob(attributes: ['source_url' => 'https://example.com/apply']);

    $this->post(route('jobs.applications.store', $job), ['return_to' => 'opportunity'])->assertRedirect(route('opportunities.show', $job));
    $this->post(route('jobs.applications.store', $job), ['return_to' => 'opportunity'])->assertRedirect(route('opportunities.show', $job));

    expect(Application::where('job_posting_id', $job->id)->count())->toBe(1);
    $this->get(route('applications.show', Application::first()))->assertOk();
});

it('keeps /jobs/{id} working for a pursued job', function () {
    $job = wsJob();

    $this->get(route('jobs.show', $job))->assertOk()->assertInertia(fn (Assert $page) => $page->component('jobs/show')->where('job.id', $job->id));
});

describe('return navigation', function () {
    beforeEach(fn () => Queue::fake());

    it('returns resume generation to the workspace only when asked, and otherwise keeps its own default', function () {
        $job = wsJob();

        $this->post(route('jobs.tailored-resume.store', $job))->assertRedirect(route('jobs.show', $job));
        $this->post(route('jobs.tailored-resume.store', $job), ['return_to' => 'opportunity'])->assertRedirect(route('opportunities.show', $job));
        $this->post(route('jobs.tailored-resume.store', $job), ['return_to' => 'job'])->assertRedirect(route('jobs.show', $job));
    });

    it('refuses anything but the known destinations, so there is no open redirect', function (string $value) {
        $job = wsJob();

        $this->post(route('jobs.tailored-resume.store', $job), ['return_to' => $value])->assertSessionHasErrors('return_to');
        $this->post(route('jobs.decision.store', $job), ['disposition' => 'shortlisted', 'return_to' => $value])->assertSessionHasErrors('return_to');
        expect($job->fresh()->disposition)->toBe(JobDisposition::Pursued);
    })->with(['https://evil.example/', '//evil.example', '/jobs', 'opportunities', '../x']);

    it('returns clarification continue to the workspace, and keeps the job page by default', function () {
        $s = ClarificationFixtures::scenario();
        app(TailoredResumePipeline::class)->start($s['posting']);
        $s['posting']->forceFill(['disposition' => JobDisposition::Pursued])->save();

        $this->post(route('jobs.clarification.continue', $s['posting']), ['return_to' => 'opportunity'])->assertRedirect(route('opportunities.show', $s['posting']));
    });

    it('keeps the job page for clarification continue when no destination is named', function () {
        $s = ClarificationFixtures::scenario();
        app(TailoredResumePipeline::class)->start($s['posting']);

        $this->post(route('jobs.clarification.continue', $s['posting']))->assertRedirect(route('jobs.show', $s['posting']));
    });

    it('tells the resume workspace whether the job is pursued, so its back link can follow', function () {
        $pursued = wsJob();
        $variant = wsResume($pursued);
        $shortlisted = wsJob(JobDisposition::Shortlisted);
        $other = wsResume($shortlisted);

        $this->get(route('jobs.profile-resumes.show', [$pursued, $variant]))->assertInertia(fn (Assert $page) => $page->where('job.pursued', true));
        $this->get(route('jobs.profile-resumes.show', [$shortlisted, $other]))->assertInertia(fn (Assert $page) => $page->where('job.pursued', false));
    });
});

describe('return to Shortlist', function () {
    it('shortlists the job, lands on its job page, and keeps every piece of work', function () {
        $job = wsJob(attributes: ['source_url' => 'https://example.com/apply']);
        $variant = wsResume($job, final: true);
        $application = Application::factory()->create(['job_posting_id' => $job->id]);
        $analysisId = $job->jobAnalyses()->first()->id;

        $this->post(route('jobs.decision.store', $job), ['disposition' => 'shortlisted', 'return_to' => 'job'])
            ->assertRedirect(route('jobs.show', $job))
            ->assertSessionHas('decision_result');

        $job->refresh();
        expect($job->disposition)->toBe(JobDisposition::Shortlisted)
            ->and($job->pursued_at)->toBeNull()
            ->and(ResumeVariant::find($variant->id))->not->toBeNull()
            ->and(JobResumeSelection::where('job_posting_id', $job->id)->value('preferred_resume_variant_id'))->toBe($variant->id)
            ->and(JobAnalysis::find($analysisId))->not->toBeNull()
            ->and(Application::find($application->id))->not->toBeNull();

        // The workspace no longer applies, and the job page carries on.
        $this->get(route('opportunities.show', $job))->assertRedirect(route('jobs.show', $job));
        $this->get(route('jobs.show', $job))->assertOk();
    });

    it('records the decision in the history and can be undone back to pursued', function () {
        $job = wsJob();
        $this->post(route('jobs.decision.store', $job), ['disposition' => 'shortlisted', 'return_to' => 'job']);

        $decision = JobDecision::query()->where('job_posting_id', $job->id)->latest('id')->first();
        expect($decision->from_disposition)->toBe(JobDisposition::Pursued)->and($decision->to_disposition)->toBe(JobDisposition::Shortlisted);

        $this->post(route('jobs.decisions.undo'), ['decisions' => [$job->id => $decision->id]]);

        expect($job->fresh()->disposition)->toBe(JobDisposition::Pursued);
        $this->get(route('opportunities.show', $job))->assertOk();
    });

    it('never navigates a bulk decision', function () {
        $job = wsJob();

        $this->post(route('jobs.batch.decision'), ['job_ids' => [$job->id], 'disposition' => 'shortlisted', 'return_to' => 'job'])->assertSessionHasErrors('return_to');
    });
});

describe('the recommended next step', function () {
    it('is the same on the list and in the workspace, for every resume state', function () {
        $none = wsJob();
        $progress = wsJob();
        wsResume($progress);
        $final = wsJob();
        wsResume($final, final: true);

        $rows = collect($this->get('/opportunities')->viewData('page')['props']['opportunities'])->keyBy('id');

        foreach ([$none, $progress, $final] as $job) {
            $this->get(route('opportunities.show', $job))->assertInertia(fn (Assert $page) => $page->where('opportunity.next', $rows[$job->id]['next']));
        }

        expect($rows[$progress->id]['next'])->toBe('continue_resume')->and($rows[$final->id]['next'])->toBe('review_final');
    });

    it('asks for generation, never a manual analysis, for a job with nothing yet', function () {
        $job = wsJob();

        $this->get(route('opportunities.show', $job))->assertInertia(fn (Assert $page) => $page
            ->where('opportunity.next', fn ($next) => in_array($next, ['analyze', 'prepare_resume'], true))
            ->where('tailored_resume.state', 'idle'));
    });

    it('still offers generation, behind a confirmation, when the description is only a preview', function () {
        $job = wsJob(attributes: ['description_completeness' => DescriptionCompleteness::Preview]);

        $this->get(route('opportunities.show', $job))->assertInertia(fn (Assert $page) => $page
            ->where('tailored_resume.state', 'idle')
            ->where('tailored_resume.needs_confirmation', true));
    });
});
