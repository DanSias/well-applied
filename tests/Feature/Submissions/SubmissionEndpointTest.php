<?php

use App\Enums\JobDisposition;
use App\Enums\SubmissionEventType;
use App\Models\Application;
use App\Models\CareerProfile;
use App\Models\JobAnalysis;
use App\Models\JobMatch;
use App\Models\JobPosting;
use App\Models\JobResumeSelection;
use App\Models\ResumeProfile;
use App\Models\ResumeProfileSynthesis;
use App\Models\ResumeVariant;
use App\Models\Submission;
use App\Models\WorkflowRun;
use App\Support\ResumeDocument\GenerateResumeDocument;
use App\Support\ResumeDocument\ResumePdfStore;
use App\Support\Submissions\RecordSubmissionEvent;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

const EP_PDF = "%PDF-1.7\nsubmitted by hand\n%%EOF";

function epProfile(): CareerProfile
{
    return CareerProfile::query()->oldest('id')->first() ?? CareerProfile::factory()->create();
}

function epJob(array $attributes = []): JobPosting
{
    return JobPosting::factory()->create(['career_profile_id' => epProfile()->id, 'disposition' => JobDisposition::Pursued, 'pursued_at' => now(), ...$attributes]);
}

/** A profile-assisted resume for the job (so it appears in the job's resumes), optionally final. */
function epResume(JobPosting $job, bool $final = false): ResumeVariant
{
    $profile = epProfile();
    $analysis = $job->jobAnalyses()->first() ?? JobAnalysis::factory()->for($job, 'jobPosting')->create();
    $resumeProfile = ResumeProfile::query()->first() ?? ResumeProfile::query()->create(['career_profile_id' => $profile->id, 'slug' => 'ep', 'name' => 'Endpoint', 'role_family' => 'engineering']);
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

function epCachePdf(ResumeVariant $variant, string $bytes = EP_PDF): void
{
    app(ResumePdfStore::class)->put(app(GenerateResumeDocument::class)->generate($variant), $bytes);
}

/** A valid request body, overridable. */
function epBody(array $overrides = []): array
{
    return [
        'idempotency_key' => 'key-'.uniqid(),
        'submitted_on' => now('America/New_York')->subDays(2)->toDateString(),
        'timezone' => 'America/New_York',
        'resume_choice' => 'none',
        ...$overrides,
    ];
}

function epPost($test, JobPosting $job, array $body = [])
{
    return $test->from("/opportunities/{$job->id}")->post(route('jobs.submissions.store', $job), epBody($body));
}

describe('recording', function () {
    it('records a manual application with only a date, and sends the person back', function () {
        $job = epJob();

        epPost($this, $job)->assertRedirect("/opportunities/{$job->id}")->assertSessionHasNoErrors();

        $submission = Submission::sole();
        expect($submission->job_posting_id)->toBe($job->id)
            ->and($submission->method->value)->toBe('manual')
            ->and($submission->resume_variant_id)->toBeNull()
            ->and($submission->application_id)->toBeNull()
            ->and($submission->submitted_pdf_path)->toBeNull();
    });

    it('records a calendar date as the start of that day in the person\'s own timezone, and implies no time', function () {
        $job = epJob();

        epPost($this, $job, ['submitted_on' => '2026-11-10', 'timezone' => 'America/New_York']);
        epPost($this, epJob(), ['submitted_on' => '2026-11-10', 'timezone' => 'Asia/Tokyo']);

        $times = Submission::orderBy('id')->get()->map(fn ($s) => $s->submitted_at->utc()->format('Y-m-d H:i:s'))->all();

        expect($times)->toBe(['2026-11-10 05:00:00', '2026-11-09 15:00:00']);
    });

    it('does not require an inspection record, a final resume or a link', function () {
        $job = epJob(['source_url' => null]);

        epPost($this, $job)->assertSessionHasNoErrors();

        expect(Submission::count())->toBe(1)->and(Application::count())->toBe(0)->and(JobResumeSelection::count())->toBe(0);
    });

    it('keeps the link, trimmed, and the notes', function () {
        $job = epJob();

        epPost($this, $job, ['application_url' => 'https://boards.example.com/apply/9', 'notes' => ' Applied through the careers page. ']);

        expect(Submission::sole())->application_url->toBe('https://boards.example.com/apply/9')->notes->toBe('Applied through the careers page.');
    });

    it('does not change the job\'s disposition or touch any inspection record', function () {
        $job = epJob(['disposition' => JobDisposition::Shortlisted, 'pursued_at' => null]);
        $inspection = Application::factory()->for($job, 'jobPosting')->create();
        $before = [$job->fresh()->getAttributes(), $inspection->fresh()->getAttributes()];

        epPost($this, $job);

        expect([$job->fresh()->getAttributes(), $inspection->fresh()->getAttributes()])->toBe($before)
            ->and(Application::count())->toBe(1)->and(WorkflowRun::count())->toBe(0)
            ->and(Submission::sole()->application_id)->toBeNull();
    });

    it('returns to the workspace for return_to, and stays on the job page for the job key', function () {
        $job = epJob();

        epPost($this, $job, ['return_to' => 'opportunity'])->assertRedirect(route('opportunities.show', $job));
        epPost($this, $job, ['return_to' => 'job', 'allow_another' => true])->assertRedirect(route('jobs.show', $job));
    });
});

describe('validation', function () {
    it('refuses what the service would not accept, and records nothing', function (array $override, string $field) {
        epPost($this, epJob(), $override)->assertSessionHasErrors($field);

        expect(Submission::count())->toBe(0);
    })->with([
        'no key' => [['idempotency_key' => ''], 'idempotency_key'],
        'bad date' => [['submitted_on' => '10/11/2026'], 'submitted_on'],
        'no date' => [['submitted_on' => ''], 'submitted_on'],
        'tomorrow' => [['submitted_on' => '2099-01-01'], 'submitted_on'],
        'bad timezone' => [['timezone' => 'Mars/Olympus'], 'timezone'],
        'no timezone' => [['timezone' => ''], 'timezone'],
        'unknown resume choice' => [['resume_choice' => 'maybe'], 'resume_choice'],
        'script link' => [['application_url' => 'javascript:alert(1)'], 'application_url'],
        'ftp link' => [['application_url' => 'ftp://example.com/x'], 'application_url'],
        'huge notes' => [['notes' => str_repeat('x', 5001)], 'notes'],
        'well applied without a resume' => [['resume_choice' => 'well_applied', 'resume_confirmed' => true], 'resume_variant_id'],
        'external without a file' => [['resume_choice' => 'external'], 'pdf'],
    ]);

    it('refuses a Well Applied resume that was not explicitly confirmed', function () {
        $job = epJob();
        $variant = epResume($job, final: true);

        epPost($this, $job, ['resume_choice' => 'well_applied', 'resume_variant_id' => $variant->id])->assertSessionHasErrors('resume_confirmed');
        epPost($this, $job, ['resume_choice' => 'well_applied', 'resume_variant_id' => $variant->id, 'resume_confirmed' => false])->assertSessionHasErrors('resume_confirmed');

        expect(Submission::count())->toBe(0);
    });

    it('refuses a resume made for a different job', function () {
        $job = epJob();
        $foreign = epResume(epJob());

        epPost($this, $job, ['resume_choice' => 'well_applied', 'resume_variant_id' => $foreign->id, 'resume_confirmed' => true])->assertSessionHasErrors('resume_variant_id');

        expect(Submission::count())->toBe(0);
    });

    it('limits attached files to PDFs of a sensible size', function (UploadedFile $file) {
        epPost($this, epJob(), ['resume_choice' => 'external', 'pdf' => $file])->assertSessionHasErrors('pdf');

        expect(Submission::count())->toBe(0)->and(Storage::disk('local')->allFiles())->toBe([]);
    })->with([
        'text file' => fn () => UploadedFile::fake()->createWithContent('notes.txt', 'hello'),
        'a pdf in name only' => fn () => UploadedFile::fake()->createWithContent('cv.pdf', '<html>not a pdf</html>'),
        'too large' => fn () => UploadedFile::fake()->create('big.pdf', 11 * 1024, 'application/pdf'),
    ]);
});

describe('ownership', function () {
    it('404s for an unknown job and for a job of another profile', function () {
        epProfile();
        $stranger = JobPosting::factory()->create(['career_profile_id' => CareerProfile::factory()->create()->id]);

        $this->post(route('jobs.submissions.store', 999999), epBody())->assertNotFound();
        $this->post(route('jobs.submissions.store', $stranger), epBody())->assertNotFound();
        $this->post('/jobs/abc/submissions', epBody())->assertNotFound();

        expect(Submission::count())->toBe(0);
    });
});

describe('retries and duplicates', function () {
    it('records once for a repeated idempotency key, whatever else differs', function () {
        $job = epJob();

        epPost($this, $job, ['idempotency_key' => 'same']);
        epPost($this, $job, ['idempotency_key' => 'same', 'notes' => 'a retry']);
        epPost($this, $job, ['idempotency_key' => 'same', 'allow_another' => true]);

        expect(Submission::count())->toBe(1)->and(Submission::sole()->notes)->toBeNull();
    });

    it('refuses a key already used for a different job', function () {
        epPost($this, epJob(), ['idempotency_key' => 'shared']);

        epPost($this, epJob(), ['idempotency_key' => 'shared'])->assertSessionHasErrors('submission');

        expect(Submission::count())->toBe(1);
    });

    it('does not silently create a second application while one is active, and says why', function () {
        $job = epJob();
        epPost($this, $job);

        $response = epPost($this, $job);

        $response->assertSessionHasErrors(['submission', 'reason']);
        expect(session('errors')->get('reason'))->toBe(['active_submission_exists'])->and(Submission::count())->toBe(1);
    });

    it('records another only with explicit intent, and then keeps both', function () {
        $job = epJob();
        epPost($this, $job);

        epPost($this, $job, ['allow_another' => true])->assertSessionHasNoErrors();

        expect($job->submissions()->count())->toBe(2);
    });

    it('needs no intent once the earlier application has ended', function () {
        $job = epJob();
        epPost($this, $job);
        app(RecordSubmissionEvent::class)->record(Submission::sole(), SubmissionEventType::Rejected, now());

        epPost($this, $job)->assertSessionHasNoErrors();

        expect($job->submissions()->count())->toBe(2);
    });
});

describe('the resume used', function () {
    it('keeps a copy of the PDF Well Applied holds for a resume the person confirms', function () {
        $job = epJob();
        $variant = epResume($job, final: true);
        epCachePdf($variant);

        epPost($this, $job, ['resume_choice' => 'well_applied', 'resume_variant_id' => $variant->id, 'resume_confirmed' => true])->assertSessionHasNoErrors();

        $submission = Submission::sole();
        expect($submission->resume_variant_id)->toBe($variant->id)
            ->and($submission->submitted_pdf_sha256)->toBe(hash('sha256', EP_PDF))
            ->and(Storage::disk('local')->get($submission->submitted_pdf_path))->toBe(EP_PDF);
    });

    it('records honestly, with no copy, when that PDF is not cached, and never renders one', function () {
        $job = epJob();
        $variant = epResume($job);
        $files = Storage::disk('local')->allFiles();

        epPost($this, $job, ['resume_choice' => 'well_applied', 'resume_variant_id' => $variant->id, 'resume_confirmed' => true])->assertSessionHasNoErrors();

        $submission = Submission::sole();
        expect($submission->resume_variant_id)->toBe($variant->id)->and($submission->submitted_pdf_path)->toBeNull()
            ->and(Storage::disk('local')->allFiles())->toBe($files);
    });

    it('never attaches the final resume the person did not choose', function () {
        $job = epJob();
        $final = epResume($job, final: true);
        epCachePdf($final);

        epPost($this, $job, ['resume_choice' => 'none']);

        expect(Submission::sole())->resume_variant_id->toBeNull()->submitted_pdf_path->toBeNull();
    });

    it('keeps an uploaded PDF privately, with its hash, and no Well Applied resume', function () {
        Storage::fake('public');
        $job = epJob();

        epPost($this, $job, ['resume_choice' => 'external', 'pdf' => UploadedFile::fake()->createWithContent('my-cv.pdf', EP_PDF)])->assertSessionHasNoErrors();

        $submission = Submission::sole();
        expect($submission->resume_variant_id)->toBeNull()
            ->and($submission->submitted_pdf_sha256)->toBe(hash('sha256', EP_PDF))
            ->and(Storage::disk('local')->get($submission->submitted_pdf_path))->toBe(EP_PDF)
            ->and(Storage::disk('public')->allFiles())->toBe([]);
    });

    it('records without any PDF when the person chose no resume', function () {
        epPost($this, epJob(), ['resume_choice' => 'none']);

        expect(Submission::sole()->submitted_pdf_sha256)->toBeNull()->and(Storage::disk('local')->allFiles())->toBe([]);
    });

    it('ignores a resume or file sent along with a choice that does not use it', function () {
        $job = epJob();
        $variant = epResume($job);

        epPost($this, $job, ['resume_choice' => 'none', 'resume_variant_id' => $variant->id, 'pdf' => UploadedFile::fake()->createWithContent('x.pdf', EP_PDF)]);

        expect(Submission::sole())->resume_variant_id->toBeNull()->submitted_pdf_path->toBeNull();
    });
});

describe('what the workspace and list show', function () {
    it('shows nothing recorded, with the job\'s link ready to prefill and the resumes to choose from', function () {
        $job = epJob(['source_url' => 'https://example.com/apply']);
        $variant = epResume($job, final: true);
        epCachePdf($variant);

        $this->get(route('opportunities.show', $job))->assertInertia(fn (Assert $page) => $page
            ->where('application.records', [])
            ->where('application.form.application_url', 'https://example.com/apply')
            ->where('application.form.resumes.0.id', $variant->id)
            ->where('application.form.resumes.0.is_final', true)
            ->where('application.form.resumes.0.pdf_cached', true)
            ->where('opportunity.submission', null));
    });

    it('shows a recorded application with its derived phase, and never picks a resume for the person', function () {
        $job = epJob();
        epResume($job, final: true);
        epPost($this, $job, ['submitted_on' => '2026-11-10', 'application_url' => 'https://example.com/apply', 'notes' => 'Via referral']);
        app(RecordSubmissionEvent::class)->record(Submission::sole(), SubmissionEventType::ConfirmationReceived, now());

        $this->get(route('opportunities.show', $job))->assertInertia(fn (Assert $page) => $page
            ->where('application.records.0.phase', 'confirmed')
            ->where('application.records.0.phase_label', 'Confirmed')
            ->where('application.records.0.method', 'manual')
            ->where('application.records.0.confirmed', true)
            ->where('application.records.0.application_url', 'https://example.com/apply')
            ->where('application.records.0.notes', 'Via referral')
            ->where('application.records.0.resume', null)
            ->where('application.records.0.pdf_kept', false)
            ->where('opportunity.submission.label', 'Confirmed'));
    });

    it('labels the resume used and whether a copy of the PDF was kept', function () {
        $job = epJob();
        $variant = epResume($job);
        epCachePdf($variant);
        epPost($this, $job, ['resume_choice' => 'well_applied', 'resume_variant_id' => $variant->id, 'resume_confirmed' => true]);

        $this->get(route('opportunities.show', $job))->assertInertia(fn (Assert $page) => $page
            ->where('application.records.0.resume.id', $variant->id)
            ->where('application.records.0.resume.label', 'Generated resume 1')
            ->where('application.records.0.pdf_kept', true));
    });

    it('puts the submission on the list row, and nothing for a job that was only inspected', function () {
        $applied = epJob();
        $inspectedOnly = epJob();
        Application::factory()->for($inspectedOnly, 'jobPosting')->create();
        epPost($this, $applied, ['submitted_on' => '2026-11-10']);

        $rows = collect($this->get('/opportunities')->viewData('page')['props']['opportunities'])->keyBy('id');

        expect($rows[$applied->id]['submission']['phase'])->toBe('submitted')
            ->and($rows[$applied->id]['submission']['label'])->toBe('Applied')
            ->and($rows[$applied->id]['submission']['active'])->toBeTrue()
            ->and($rows[$inspectedOnly->id]['submission'])->toBeNull()
            ->and($rows[$inspectedOnly->id]['inspection']['application_id'])->not->toBeNull();
    });

    it('shows the state that matters: an active application over an ended one, and ignores mistakes', function () {
        $job = epJob();
        epPost($this, $job, ['submitted_on' => '2026-10-01']);
        app(RecordSubmissionEvent::class)->record(Submission::sole(), SubmissionEventType::Rejected, now()->subDay());
        epPost($this, $job, ['submitted_on' => '2026-11-10']);
        $mistake = epJob();
        epPost($this, $mistake);
        app(RecordSubmissionEvent::class)->record(Submission::where('job_posting_id', $mistake->id)->sole(), SubmissionEventType::RecordedInError, now());

        $rows = collect($this->get('/opportunities')->viewData('page')['props']['opportunities'])->keyBy('id');

        expect($rows[$job->id]['submission']['phase'])->toBe('submitted')->and($rows[$job->id]['submission']['count'])->toBe(2)
            ->and($rows[$mistake->id]['submission'])->toBeNull();
    });
});
