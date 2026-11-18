<?php

/*
 | LOCAL END-TO-END FIXTURE SEED. Run through tinker against a DISPOSABLE database only
 | (see workers/application-preparer/scripts/e2e-laravel-fixture.sh). It creates synthetic data: a fake
 | profile, a pursued job pointing at a LOCAL fixture form, and a tailored resume whose cached PDF is the
 | worker's synthetic sample. No real applicant data, no real employer site.
 */

use App\Enums\JobDisposition;
use App\Models\CareerProfile;
use App\Models\JobAnalysis;
use App\Models\JobMatch;
use App\Models\JobPosting;
use App\Models\ResumeProfile;
use App\Models\ResumeProfileSynthesis;
use App\Models\ResumeVariant;
use App\Support\ResumeDocument\GenerateResumeDocument;
use App\Support\ResumeDocument\ResumePdfStore;

$origin = rtrim((string) getenv('FIXTURE_ORIGIN'), '/');
$page = (string) (getenv('FIXTURE_PAGE') ?: '/greenhouse-form.html');
$pdfPath = (string) getenv('FIXTURE_PDF');
if (! str_starts_with($origin, 'http://127.0.0.1') || ! is_file($pdfPath)) {
    throw new RuntimeException('Set FIXTURE_ORIGIN (http://127.0.0.1:PORT) and FIXTURE_PDF.');
}
if (CareerProfile::query()->exists()) {
    throw new RuntimeException('Refusing to seed a database that already has data: use a disposable one.');
}

$profile = CareerProfile::factory()->create([
    'name' => 'Alex Example', 'email' => 'alex.example@example.test', 'phone' => '+1 555 010 0199',
    'location' => 'Springfield, Exampleland', 'github_url' => 'https://github.com/alex-example-test', 'portfolio_url' => 'https://alex.example.test',
]);
$job = JobPosting::factory()->create([
    'career_profile_id' => $profile->id, 'company' => 'Example Co', 'title' => 'Fixture Engineer',
    'source_url' => $origin.$page, 'disposition' => JobDisposition::Pursued,
]);
$analysis = JobAnalysis::factory()->for($job, 'jobPosting')->create();
$match = JobMatch::factory()->create(['job_analysis_id' => $analysis->id, 'career_profile_id' => $profile->id]);
$resumeProfile = ResumeProfile::query()->create(['career_profile_id' => $profile->id, 'slug' => 'e2e', 'name' => 'E2E', 'role_family' => 'engineering']);
$synthesis = ResumeProfileSynthesis::query()->create([
    'career_profile_id' => $profile->id, 'resume_profile_id' => $resumeProfile->id, 'version' => 1, 'status' => 'approved',
    'evidence_revision' => 0, 'input_hash' => 'e2e', 'content' => [],
]);
$variant = ResumeVariant::factory()->create([
    'career_profile_id' => $profile->id, 'job_analysis_id' => $analysis->id, 'job_match_id' => $match->id, 'resume_profile_synthesis_id' => $synthesis->id,
]);
app(ResumePdfStore::class)->put(app(GenerateResumeDocument::class)->generate($variant), (string) file_get_contents($pdfPath));

echo "seeded job {$job->id} variant {$variant->id}\n";
