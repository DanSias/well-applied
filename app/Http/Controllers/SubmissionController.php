<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSubmissionRequest;
use App\Models\JobPosting;
use App\Models\ResumeVariant;
use App\Support\CurrentCareerProfile;
use App\Support\Navigation\ReturnTo;
use App\Support\Submissions\RecordSubmission;
use App\Support\Submissions\SubmissionException;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

/**
 * Records that a person submitted an application themselves. Thin by design: RecordSubmission owns every rule (the
 * lock, idempotency, "another needs explicit intent", the snapshot), and this only turns a request into its arguments
 * and its refusals into form errors. Well Applied submits nothing; this never changes the job's disposition and never
 * requires an inspection record, a resume or a link.
 */
class SubmissionController extends Controller
{
    public function store(StoreSubmissionRequest $request, JobPosting $jobPosting, RecordSubmission $recorder): RedirectResponse
    {
        abort_unless($jobPosting->career_profile_id === CurrentCareerProfile::resolve()->id, 404);

        $choice = $request->input('resume_choice');
        // A calendar date, in the person's own timezone, recorded as the start of that day: no time is implied.
        $submittedAt = CarbonImmutable::createFromFormat('Y-m-d', (string) $request->input('submitted_on'), (string) $request->input('timezone'))->startOfDay();
        $variant = $choice === 'well_applied' ? ResumeVariant::query()->find($request->integer('resume_variant_id')) : null;

        try {
            if ($choice === 'well_applied' && $variant === null) {
                throw new SubmissionException('Choose which resume you used.', 'resume_not_for_job');
            }

            $recorder->record(
                $jobPosting,
                $submittedAt,
                idempotencyKey: (string) $request->input('idempotency_key'),
                allowAnother: $request->boolean('allow_another'),
                resumeVariant: $variant,
                applicationUrl: $request->input('application_url'),
                notes: $request->input('notes'),
                pdfBytes: $choice === 'external' ? (string) file_get_contents($request->file('pdf')->getRealPath()) : null,
                // The PDF Well Applied already holds for that resume, or none: never rendered here to manufacture evidence.
                snapshotServedPdf: $choice === 'well_applied',
            );
        } catch (SubmissionException $e) {
            throw ValidationException::withMessages([
                $this->field($e->reason) => $e->getMessage(),
                'reason' => $e->reason,
            ]);
        }

        return ReturnTo::redirect($request, $jobPosting) ?? back();
    }

    /** Which form field a refusal belongs to; anything else is shown as a general message. */
    private function field(string $reason): string
    {
        return match ($reason) {
            'submitted_in_future' => 'submitted_on',
            'url_invalid' => 'application_url',
            'resume_not_for_job' => 'resume_variant_id',
            'snapshot_not_pdf', 'snapshot_too_large' => 'pdf',
            default => 'submission',
        };
    }
}
