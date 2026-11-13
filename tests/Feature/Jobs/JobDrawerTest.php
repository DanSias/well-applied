<?php

use App\Enums\DecisionSource;
use App\Enums\DismissalReason;
use App\Enums\JobAnalysisFindingCategory;
use App\Enums\JobDisposition;
use App\Models\CareerProfile;
use App\Models\JobAnalysis;
use App\Models\JobAnalysisFinding;
use App\Models\JobPosting;
use App\Support\JobDecisions\RecordJobDecision;
use Inertia\Testing\AssertableInertia as Assert;

function drawerJob(CareerProfile $profile, array $attributes = []): JobPosting
{
    return JobPosting::factory()->create(['career_profile_id' => $profile->id] + $attributes);
}

it('keeps full descriptions out of the list payload', function () {
    $profile = CareerProfile::factory()->create();
    drawerJob($profile, ['description' => 'SECRET-DESCRIPTION-BODY']);

    $response = $this->get('/jobs');
    $props = $response->viewData('page')['props'];

    expect(json_encode($props['jobs']))->not->toContain('SECRET-DESCRIPTION-BODY')->and($props['selected_job'])->toBeNull()->and($props['filters']['job'])->toBeNull();
});

it('loads the open job on demand: its summary, its verbatim description and a brief of its analysis', function () {
    $profile = CareerProfile::factory()->create();
    $job = drawerJob($profile, ['title' => 'Staff Engineer', 'description' => "Line one.\n\n- A bullet\n- Another"]);
    $analysis = JobAnalysis::factory()->for($job, 'jobPosting')->create(['role_summary' => 'Builds payment tooling.']);
    JobAnalysisFinding::factory()->for($analysis, 'jobAnalysis')->create(['category' => JobAnalysisFindingCategory::RequiredQualification, 'statement' => 'Five years of PHP']);
    JobAnalysisFinding::factory()->for($analysis, 'jobAnalysis')->create(['category' => JobAnalysisFindingCategory::Technology, 'statement' => 'Uses Laravel', 'label' => 'Laravel']);
    JobAnalysisFinding::factory()->for($analysis, 'jobAnalysis')->create(['category' => JobAnalysisFindingCategory::CultureSignal, 'statement' => 'Friendly']);

    $selected = $this->get("/jobs?job={$job->id}")->viewData('page')['props'];

    expect($selected['filters']['job'])->toBe($job->id)
        ->and($selected['selected_job'])->toMatchArray(['id' => $job->id, 'title' => 'Staff Engineer', 'description' => "Line one.\n\n- A bullet\n- Another"])
        ->and($selected['selected_job']['disposition']['value'])->toBe('new')
        ->and($selected['selected_job']['triage']['stage'])->toBe('analyzed')
        ->and($selected['selected_job']['analysis'])->toMatchArray(['role_summary' => 'Builds payment tooling.', 'requirements' => ['Five years of PHP'], 'technologies' => ['Laravel']])
        // Everything the list row needs is there too, so the drawer and the list agree.
        ->and($selected['selected_job'])->toHaveKeys(['inspection', 'discovery', 'source_url', 'location']);
});

it('opens a job that is not in the current list, and keeps the list exactly as the filters make it', function () {
    $profile = CareerProfile::factory()->create();
    $service = app(RecordJobDecision::class);
    $inbox = drawerJob($profile, ['title' => 'Inbox Alpha']);
    $dismissed = drawerJob($profile, ['title' => 'Dismissed Beta']);
    $service->record($dismissed, JobDisposition::Dismissed, DecisionSource::User, DismissalReason::Other);

    $props = $this->get("/jobs?job={$dismissed->id}&q=Alpha&disposition=inbox")->viewData('page')['props'];

    expect($props['selected_job']['id'])->toBe($dismissed->id)->and($props['selected_job']['disposition']['value'])->toBe('dismissed')
        ->and(array_column($props['jobs'], 'id'))->toBe([$inbox->id])
        ->and($props['filters'])->toMatchArray(['q' => 'Alpha', 'disposition' => 'inbox', 'job' => $dismissed->id])
        ->and($props['pagination']['page'])->toBe(1);
});

it('answers an unknown, foreign or malformed job id with no job, never another job', function () {
    $profile = CareerProfile::factory()->create();
    drawerJob($profile);
    $foreign = drawerJob(CareerProfile::factory()->create());

    foreach (["job={$foreign->id}", 'job=999999', 'job=abc', 'job=-3'] as $query) {
        $props = $this->get("/jobs?{$query}")->viewData('page')['props'];
        expect($props['selected_job'])->toBeNull();
    }

    // The foreign id is still echoed as the requested job, so the page can say it is unavailable instead of showing a stranger's.
    expect($this->get("/jobs?job={$foreign->id}")->viewData('page')['props']['filters']['job'])->toBe($foreign->id)
        ->and($this->get('/jobs?job=abc')->viewData('page')['props']['filters']['job'])->toBeNull();
});

it('loads the open job lazily, so a list refresh that does not ask for it never builds it', function () {
    $profile = CareerProfile::factory()->create();
    $job = drawerJob($profile);

    $this->get("/jobs?job={$job->id}")->assertInertia(fn (Assert $page) => $page
        ->reloadOnly(['jobs', 'pagination', 'disposition_counts'], fn (Assert $reload) => $reload->has('jobs', 1)->missing('selected_job'))
        ->reloadOnly('selected_job', fn (Assert $reload) => $reload->where('selected_job.id', $job->id)->missing('jobs')));
});

it('reflects a decision made on the open job when the page is reloaded with the same job open', function () {
    $profile = CareerProfile::factory()->create();
    $job = drawerJob($profile);

    $this->from("/jobs?job={$job->id}")->post("/jobs/{$job->id}/decision", ['disposition' => 'shortlisted'])->assertRedirect("/jobs?job={$job->id}");

    $props = $this->get("/jobs?job={$job->id}")->viewData('page')['props'];

    expect($props['selected_job']['disposition']['value'])->toBe('shortlisted')
        ->and($props['disposition_counts'])->toMatchArray(['inbox' => 0, 'shortlist' => 1]);
});

it('keeps the full job page as it was', function () {
    $job = drawerJob(CareerProfile::factory()->create(), ['description' => 'Whole description.']);

    $this->get("/jobs/{$job->id}")->assertOk()->assertInertia(fn (Assert $page) => $page->component('jobs/show')->where('job.description', 'Whole description.'));
});
