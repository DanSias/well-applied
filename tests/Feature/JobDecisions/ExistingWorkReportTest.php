<?php

use App\Models\Application;
use App\Models\CareerProfile;
use App\Models\JobAnalysis;
use App\Models\JobDecision;
use App\Models\JobPosting;
use App\Models\JobResumeSelection;
use App\Models\ResumeVariant;
use App\Support\JobDecisions\ReportJobsWithWork;
use Illuminate\Support\Facades\DB;

it('reports only jobs that have resume or application work, and changes nothing', function () {
    $profile = CareerProfile::factory()->create();
    $plain = JobPosting::factory()->create(['career_profile_id' => $profile->id]);
    $withApplication = JobPosting::factory()->create(['career_profile_id' => $profile->id]);
    $withResume = JobPosting::factory()->create(['career_profile_id' => $profile->id]);
    Application::factory()->create(['job_posting_id' => $withApplication->id]);
    $analysis = JobAnalysis::factory()->for($withResume, 'jobPosting')->create();
    $variant = ResumeVariant::factory()->create(['career_profile_id' => $profile->id, 'job_analysis_id' => $analysis->id]);
    JobResumeSelection::query()->create(['job_posting_id' => $withResume->id, 'current_resume_variant_id' => $variant->id, 'preferred_resume_variant_id' => $variant->id]);

    $tables = ['job_postings', 'applications', 'job_analyses', 'resume_variants', 'job_resume_selections', 'job_decisions'];
    $snapshot = fn () => collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->orderBy('id')->get()->toJson()])->all();
    $before = $snapshot();

    $rows = collect(app(ReportJobsWithWork::class)->report())->keyBy('job_id');

    expect($rows->keys()->all())->toEqualCanonicalizing([$withApplication->id, $withResume->id])
        ->and($rows[$withResume->id]['final_resume_variant_id'])->toBe($variant->id)
        ->and($rows[$withResume->id]['resume_variants'])->toBe(1)
        ->and($rows[$withApplication->id]['applications'][0]['status'])->toBe('draft')
        ->and($rows[$withResume->id]['disposition'])->toBe('new')
        ->and($snapshot())->toBe($before);

    // Never classifies anything.
    expect(JobDecision::count())->toBe(0)->and(JobPosting::where('disposition', '!=', 'new')->count())->toBe(0);
    $this->artisan('jobs:existing-work')->assertSuccessful();
    expect($snapshot())->toBe($before);
});
