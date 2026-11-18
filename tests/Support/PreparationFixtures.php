<?php

namespace Tests\Support;

use App\Enums\JobDisposition;
use App\Models\AgentRun;
use App\Models\CareerProfile;
use App\Models\JobAnalysis;
use App\Models\JobMatch;
use App\Models\JobPosting;
use App\Models\ResumeProfile;
use App\Models\ResumeProfileSynthesis;
use App\Models\ResumeVariant;
use App\Support\ApplicationPreparation\DispatchApplicationPreparation;
use App\Support\ResumeDocument\GenerateResumeDocument;
use App\Support\ResumeDocument\ResumePdfStore;
use App\Support\ResumeRevision\JobResumes;

/**
 * Synthetic fixtures for application preparation tests: a profile with obviously fake contact details, a pursued job on
 * a supported ATS host, a tailored resume with a cached PDF, and a valid worker report. No real applicant data, no
 * browser, no network.
 */
final class PreparationFixtures
{
    public const PDF = "%PDF-1.7\nsynthetic resume bytes A\n%%EOF";

    public const PDF_OTHER = "%PDF-1.7\nsynthetic resume bytes B\n%%EOF";

    public const EMAIL = 'alex.example@example.test';

    public const PHONE = '+1 555 010 0199';

    public const PNG = "\x89PNG\r\n\x1a\n".'synthetic screenshot';

    /**
     * @param  array<string, mixed>  $profile
     * @param  array<string, mixed>  $job
     * @return array{profile: CareerProfile, job: JobPosting, variant: ResumeVariant}
     */
    public static function scenario(array $profile = [], array $job = [], bool $cachePdf = true): array
    {
        // Created first, so it is the current (lowest-id) profile the web app resolves.
        $profile = CareerProfile::factory()->create($profile + [
            'name' => 'Alex Example',
            'email' => self::EMAIL,
            'phone' => self::PHONE,
            'location' => 'Springfield, USA',
            'github_url' => 'https://github.com/alex-example-test',
            'portfolio_url' => 'https://alex.example.test',
        ]);

        $job = JobPosting::factory()->create($job + [
            'career_profile_id' => $profile->id,
            'source_url' => 'https://job-boards.greenhouse.io/example/jobs/123456',
            'disposition' => JobDisposition::Pursued,
        ]);

        return ['profile' => $profile, 'job' => $job, 'variant' => self::variant($job, $profile, $cachePdf)];
    }

    public static function variant(JobPosting $job, CareerProfile $profile, bool $cachePdf = true, string $pdf = self::PDF): ResumeVariant
    {
        $analysis = JobAnalysis::factory()->for($job, 'jobPosting')->create();
        $match = JobMatch::factory()->create(['job_analysis_id' => $analysis->id, 'career_profile_id' => $profile->id]);
        // Only profile-assisted resumes count as the job's resumes (JobResumes::variants), so give it a synthesis.
        $resumeProfile = ResumeProfile::query()->first() ?? ResumeProfile::query()->create(['career_profile_id' => $profile->id, 'slug' => 'prep', 'name' => 'Preparation', 'role_family' => 'engineering']);
        $synthesis = ResumeProfileSynthesis::query()->first() ?? ResumeProfileSynthesis::query()->create([
            'career_profile_id' => $profile->id, 'resume_profile_id' => $resumeProfile->id, 'version' => 1, 'status' => 'approved', 'evidence_revision' => 0, 'input_hash' => 'x', 'content' => [],
        ]);
        $variant = ResumeVariant::factory()->create([
            'job_analysis_id' => $analysis->id,
            'job_match_id' => $match->id,
            'career_profile_id' => $profile->id,
            'resume_profile_synthesis_id' => $synthesis->id,
        ]);

        if ($cachePdf) {
            self::cachePdf($variant, $pdf);
        }

        return $variant;
    }

    public static function cachePdf(ResumeVariant $variant, string $bytes = self::PDF): void
    {
        app(ResumePdfStore::class)->put(app(GenerateResumeDocument::class)->generate($variant), $bytes);
    }

    public static function markFinal(JobPosting $job, ResumeVariant $variant): void
    {
        app(JobResumes::class)->setPreferred($job, $variant);
    }

    public static function markCurrent(JobPosting $job, ResumeVariant $variant): void
    {
        app(JobResumes::class)->setCurrent($job, $variant);
    }

    /**
     * @return array{run: AgentRun, job: JobPosting, profile: CareerProfile, variant: ResumeVariant}
     */
    public static function queuedRun(array $profile = [], array $job = []): array
    {
        $s = self::scenario($profile, $job);
        ['workflowRun' => $workflowRun] = app(DispatchApplicationPreparation::class)->dispatch($s['job']);
        $run = AgentRun::query()->where('agent_type', 'application_preparer')->latest('id')->firstOrFail();

        return $s + ['run' => $run, 'workflowRun' => $workflowRun];
    }

    /**
     * A report in the shape workers/application-preparer emits (schemaVersion 2), reduced to what the contract keeps.
     *
     * @param  array<string, mixed>  $overrides  merged (shallowly) into the report
     * @return array<string, mixed>
     */
    public static function report(AgentRun $run, array $overrides = []): array
    {
        return array_replace([
            'schemaVersion' => 2,
            'runId' => 'wa-'.$run->id,
            'ats' => 'greenhouse',
            'requestedUrl' => 'https://job-boards.greenhouse.io/example/jobs/123456',
            'finalUrl' => 'https://job-boards.greenhouse.io/example/jobs/123456',
            'outcome' => 'input_required',
            'reasons' => ['unresolved_required: Why do you want to join Example?'],
            'warnings' => ['captcha markup present but inactive (hidden/zero-size/off-screen/invisible); nothing was attempted'],
            'summary' => ['total' => 3, 'filledVerified' => 2, 'unansweredOptional' => 0, 'unresolvedRequired' => 1, 'unresolvedUncertain' => 0, 'preselectedByPage' => 0, 'unverified' => 0, 'manualAction' => 0, 'errors' => 0],
            'questionSummary' => ['total' => 3, 'complete' => 2, 'optionalSkipped' => 0, 'needsAnswer' => 1, 'needsPerson' => 0, 'needsCheck' => 0, 'failed' => 0, 'unlabelled' => 0],
            'fields' => [
                ['label' => 'First Name', 'kind' => 'text', 'required' => true, 'status' => 'filled_verified', 'classification' => 'known', 'mappedTo' => 'first_name', 'sensitive' => false, 'expected' => 'Alex', 'actual' => 'Alex'],
                ['label' => 'Email', 'kind' => 'text', 'required' => true, 'status' => 'filled_verified', 'classification' => 'known', 'mappedTo' => 'email', 'sensitive' => false, 'expected' => self::EMAIL, 'actual' => self::EMAIL],
                ['label' => 'Why do you want to join Example?', 'kind' => 'textarea', 'required' => true, 'status' => 'unresolved_required', 'classification' => 'unknown', 'mappedTo' => null, 'sensitive' => false],
            ],
            'questions' => [
                ['label' => 'First Name', 'labelSource' => 'dom_label_for', 'kind' => 'text', 'required' => true, 'status' => 'filled_verified', 'review' => 'complete', 'sensitive' => false, 'provenance' => ['source' => 'applicant_fact']],
                ['label' => 'Why do you want to join Example?', 'labelSource' => 'dom_label_for', 'kind' => 'textarea', 'required' => true, 'status' => 'unresolved_required', 'review' => 'needs_answer', 'sensitive' => false, 'provenance' => ['source' => 'none']],
            ],
            'resume' => [
                'sha256' => $run->input_summary['resume_sha256'] ?? null,
                'bytes' => $run->input_summary['resume_bytes'] ?? null,
                'uploaded' => true,
                'verified' => true,
                'verification' => 'upload_accepted',
                'inputHeldChosenResume' => false,
                'upload' => [
                    'outcome' => 'accepted_filename_visible',
                    'artifactSha256Matches' => true,
                    'filenameVisibleBefore' => false,
                    'filenameVisible' => true,
                    'refusals' => [],
                    'detail' => 'the server accepted the upload (HTTP 201) and the widget shows the file name',
                    'response' => ['exception' => 'greenhouse-resume-upload', 'destination' => 'https://grnhse-prod-jben-us-east-1.s3.amazonaws.com/', 'status' => 201, 'statusText' => 'Created', 'contentLength' => 418, 'failure' => null, 'durationMs' => 262],
                ],
                'parser' => ['status' => 'not_observable'],
            ],
            'blockers' => ['captcha' => 'inactive_markup', 'authentication' => false, 'multistep' => false],
            'submission' => ['assertion' => 'worker_did_not_submit', 'blockedAttempts' => 0, 'suspected' => false],
            'safetyEvents' => [
                ['kind' => 'blocked_telemetry_request', 'detail' => 'greenhouse snowplow analytics beacon: POST https://c.spl.greenhouse.io/com.snowplowanalytics.snowplow/tp2 [fetch, application/json, 1980B]', 'at' => '2026-11-18T13:14:18.000Z', 'phase' => 'preparation'],
                ['kind' => 'allowed_exception_used', 'detail' => 'greenhouse-resume-upload: POST https://grnhse-prod-jben-us-east-1.s3.amazonaws.com/ [xhr, multipart/form-data, 2887B]', 'at' => '2026-11-18T13:14:19.000Z', 'phase' => 'preparation'],
            ],
            'diagnostics' => ['durationMs' => 10713, 'headless' => true, 'held' => false],
            'screenshots' => [
                ['file' => 'before-01of02-y00000.png', 'phase' => 'before', 'index' => 1, 'of' => 2, 'scrollY' => 0, 'viewportWidth' => 1400, 'viewportHeight' => 1000, 'documentHeight' => 1800],
                ['file' => 'after_fill-01of02-y00000.png', 'phase' => 'after_fill', 'index' => 1, 'of' => 2, 'scrollY' => 0, 'viewportWidth' => 1400, 'viewportHeight' => 1000, 'documentHeight' => 1800],
            ],
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  array<int, string>  $screenshotNames  names whose bytes the worker is declaring (with the PNG fixture's hash)
     * @return array<string, mixed>
     */
    public static function delivery(array $report, array $screenshotNames = []): array
    {
        return [
            'status' => 'succeeded',
            'report' => $report,
            'screenshots' => array_map(fn (string $file) => ['file' => $file, 'sha256' => hash('sha256', self::PNG)], $screenshotNames),
        ];
    }
}
