<?php

namespace App\Http\Controllers;

use App\Enums\SubmissionEventType;
use App\Http\Requests\StoreSubmissionCorrectionRequest;
use App\Http\Requests\StoreSubmissionEventRequest;
use App\Models\Submission;
use App\Support\CurrentCareerProfile;
use App\Support\Navigation\ReturnTo;
use App\Support\Submissions\RecordSubmissionEvent;
use App\Support\Submissions\SubmissionException;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

/**
 * Updates to an application a person recorded: what happened afterwards, and the correction for a record made by mistake.
 * Thin by design: RecordSubmissionEvent owns every rule (append-only, idempotency, nothing after an ending, nothing before
 * the submission) and the status is derived from the events, so this stores no status and never edits or removes the
 * submission or an earlier event. It never changes the job's disposition and never touches an inspection record.
 */
class SubmissionEventController extends Controller
{
    public function store(StoreSubmissionEventRequest $request, Submission $submission, RecordSubmissionEvent $recorder): RedirectResponse
    {
        $this->own($submission);

        // A calendar date in the person's own timezone, kept as that date, recorded as the start of that day: no time is implied.
        $occurredAt = CarbonImmutable::createFromFormat('Y-m-d', (string) $request->input('occurred_on'), (string) $request->input('timezone'))->startOfDay();
        $url = trim((string) $request->input('url'));

        try {
            $recorder->record(
                $submission,
                SubmissionEventType::from((string) $request->input('type')),
                $occurredAt,
                note: $request->input('note'),
                evidence: $url === '' ? null : ['kind' => 'url', 'reference' => $url],
                idempotencyKey: (string) $request->input('idempotency_key'),
                occurredOn: (string) $request->input('occurred_on'),
            );
        } catch (SubmissionException $e) {
            throw $this->refusal($e);
        }

        return ReturnTo::redirect($request, $submission->jobPosting) ?? back();
    }

    /**
     * Marks the application as recorded in error: an event like any other, so the submission and its history stay, and the
     * derived status becomes "recorded in error". Nothing can be added to it afterwards.
     */
    public function correct(StoreSubmissionCorrectionRequest $request, Submission $submission, RecordSubmissionEvent $recorder): RedirectResponse
    {
        $this->own($submission);

        try {
            $recorder->record(
                $submission,
                SubmissionEventType::RecordedInError,
                CarbonImmutable::now(),
                note: $request->input('reason'),
                idempotencyKey: (string) $request->input('idempotency_key'),
                occurredOn: CarbonImmutable::now((string) config('app.timezone'))->toDateString(),
            );
        } catch (SubmissionException $e) {
            throw $this->refusal($e);
        }

        return ReturnTo::redirect($request, $submission->jobPosting) ?? back();
    }

    /** Only the current profile's own applications: another profile's is simply not found. */
    private function own(Submission $submission): void
    {
        abort_unless($submission->jobPosting->career_profile_id === CurrentCareerProfile::resolve()->id, 404);
    }

    private function refusal(SubmissionException $e): ValidationException
    {
        $field = match ($e->reason) {
            'before_submission', 'occurred_in_future', 'occurred_on_invalid' => 'occurred_on',
            'evidence_invalid' => 'url',
            default => 'submission',
        };

        return ValidationException::withMessages([$field => $e->getMessage(), 'reason' => $e->reason]);
    }
}
