<?php

use App\Enums\JobDisposition;
use App\Models\Application;
use App\Models\CareerProfile;
use App\Models\JobAnalysis;
use App\Models\JobMatch;
use App\Models\JobPosting;
use App\Models\ResumeProfile;
use App\Models\ResumeProfileSynthesis;
use App\Models\ResumeVariant;

function navProfile(): CareerProfile
{
    return CareerProfile::query()->oldest('id')->first() ?? CareerProfile::factory()->create();
}

function navJob(JobDisposition $disposition): JobPosting
{
    return JobPosting::factory()->create([
        'career_profile_id' => navProfile()->id,
        'disposition' => $disposition,
        'pursued_at' => $disposition === JobDisposition::Pursued ? now() : null,
        'source_url' => 'https://example.com/apply',
    ]);
}

/** Every page about one job, with its parts. @return array<string, string> */
function navPagesFor(JobPosting $job): array
{
    $profile = navProfile();
    $analysis = JobAnalysis::factory()->for($job, 'jobPosting')->create();
    $match = JobMatch::factory()->create(['job_analysis_id' => $analysis->id, 'career_profile_id' => $profile->id]);
    $resumeProfile = ResumeProfile::query()->first() ?? ResumeProfile::query()->create(['career_profile_id' => $profile->id, 'slug' => 'nav', 'name' => 'Nav', 'role_family' => 'engineering']);
    $synthesis = ResumeProfileSynthesis::query()->first() ?? ResumeProfileSynthesis::query()->create([
        'career_profile_id' => $profile->id, 'resume_profile_id' => $resumeProfile->id, 'version' => 1, 'status' => 'approved', 'evidence_revision' => 0, 'input_hash' => 'x', 'content' => [],
    ]);
    $variant = ResumeVariant::factory()->create(['career_profile_id' => $profile->id, 'job_analysis_id' => $analysis->id, 'resume_profile_synthesis_id' => $synthesis->id, 'job_match_id' => $match->id]);
    $inspection = Application::factory()->for($job, 'jobPosting')->create();

    return [
        'the job page' => route('jobs.show', $job, false),
        'the analysis' => route('jobs.analyses.show', [$job, $analysis], false),
        'the match' => route('jobs.analyses.matches.show', [$job, $analysis, $match], false),
        'the resume workspace' => route('jobs.profile-resumes.show', [$job, $variant], false),
        'the browser inspection record' => route('applications.show', $inspection, false),
    ];
}

function navSection($test, string $path): ?string
{
    return $test->get($path)->assertOk()->viewData('page')['props']['nav']['section'];
}

it('puts every page about a pursued job under Opportunities', function () {
    $job = navJob(JobDisposition::Pursued);

    foreach (navPagesFor($job) as $page => $path) {
        expect(navSection($this, $path))->toBe('opportunities', "{$page} of a pursued job");
    }
});

it('puts every page about a job that is not pursued under Jobs', function (JobDisposition $disposition) {
    $job = navJob($disposition);

    foreach (navPagesFor($job) as $page => $path) {
        expect(navSection($this, $path))->toBe('jobs', "{$page} of a {$disposition->value} job");
    }
})->with([JobDisposition::New, JobDisposition::Shortlisted, JobDisposition::Dismissed]);

it('puts the opportunity workspace under Opportunities', function () {
    $job = navJob(JobDisposition::Pursued);

    expect(navSection($this, route('opportunities.show', $job, false)))->toBe('opportunities');
});

it('lights the index pages of the workflow', function (string $path, string $section) {
    navProfile();

    expect(navSection($this, $path))->toBe($section);
})->with([
    'home' => ['/', 'home'],
    'sources' => ['/sources', 'sources'],
    'a provider drawer' => ['/sources?provider=jobicy', 'sources'],
    'filtered candidates' => ['/discovery/rejections', 'sources'],
    'jobs' => ['/jobs', 'jobs'],
    'adding a job' => ['/jobs/create', 'jobs'],
    'opportunities' => ['/opportunities', 'opportunities'],
    'applications' => ['/applications', 'applications'],
]);

it('keeps the jobs list under Jobs even with a drawer open on a pursued job', function () {
    $job = navJob(JobDisposition::Pursued);

    expect(navSection($this, '/jobs?disposition=all&job='.$job->id))->toBe('jobs');
});

it('keeps Career Data and Resume Profiles in the profile menu, not the workflow', function (string $path) {
    navProfile();

    expect(navSection($this, $path))->toBe('profile');
})->with(['/career-data', '/resume-profiles']);

it('follows the job when its disposition changes', function () {
    $job = navJob(JobDisposition::Pursued);
    $path = navPagesFor($job)['the analysis'];

    expect(navSection($this, $path))->toBe('opportunities');

    $job->forceFill(['disposition' => JobDisposition::Shortlisted, 'pursued_at' => null])->save();

    expect(navSection($this, $path))->toBe('jobs');

    $job->forceFill(['disposition' => JobDisposition::Pursued, 'pursued_at' => now()])->save();

    expect(navSection($this, $path))->toBe('opportunities');
});

it('does not depend on where the person came from', function () {
    $job = navJob(JobDisposition::Pursued);
    $path = navPagesFor($job)['the resume workspace'];

    foreach (['/jobs', '/opportunities', '/applications', '/career-data', null] as $from) {
        $request = $from === null ? $this->get($path) : $this->from($from)->get($path);

        expect($request->viewData('page')['props']['nav']['section'])->toBe('opportunities', "arriving from {$from}");
    }
});

it('leaves the destinations themselves alone', function () {
    $job = navJob(JobDisposition::Pursued);

    expect(route('jobs.show', $job, false))->toBe("/jobs/{$job->id}")
        ->and(route('opportunities.show', $job, false))->toBe("/opportunities/{$job->id}");
    $this->get("/jobs/{$job->id}")->assertOk();
});
