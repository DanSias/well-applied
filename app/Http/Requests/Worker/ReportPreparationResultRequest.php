<?php

namespace App\Http\Requests\Worker;

use App\Enums\AgentRunFailureCategory;
use App\Enums\PreparationOutcome;
use App\Support\ApplicationPreparation\PreparationArtifacts;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * Validates the application-preparer's delivery before it can touch durable state: the fail-closed boundary between an
 * external process's output and this application, as ReportInspectionResultRequest is for the inspector.
 *
 * Only the fields named here survive (validated() drops the rest), which is what keeps raw DOM, option lists, control
 * dumps and anything else the worker's report happens to carry out of the database. Fields are bounded in number and
 * length. The report must carry `submission.assertion = worker_did_not_submit`: nothing in this contract can state that
 * an application was submitted, and a report that says anything else is refused outright.
 *
 * authorize() is true: AuthenticatePreparerWorker and PreparerRunAccess already gate every route this is used on.
 */
class ReportPreparationResultRequest extends FormRequest
{
    private const FIELD_STATUSES = ['filled_verified', 'unanswered_optional', 'unresolved_required', 'unresolved_uncertain', 'unverified', 'manual_action_required', 'preselected_by_page', 'error'];

    private const REVIEWS = ['complete', 'optional_skipped', 'needs_answer', 'needs_person', 'needs_check', 'failed'];

    private const EVENT_KINDS = ['blocked_mutating_request', 'blocked_third_party_request', 'blocked_bot_detection_request', 'blocked_telemetry_request', 'submit_event_blocked', 'unexpected_navigation', 'suspected_submission', 'allowed_exception_used'];

    private const UPLOAD_OUTCOMES = ['not_applicable', 'selection_failed', 'no_upload_request', 'unauthorized_request', 'failed', 'unknown', 'accepted_confirmation_missing', 'accepted_filename_visible'];

    public function authorize(): bool
    {
        if (strlen($this->getContent()) > PreparationArtifacts::MAX_JSON_BYTES) {
            throw new HttpResponseException(response()->json(['accepted' => false, 'reason' => 'payload_too_large'], 413));
        }

        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $succeeded = fn () => $this->input('status') === 'succeeded';

        return [
            'status' => ['required', Rule::in(['succeeded', 'failed'])],

            // A delivered failure. claim_timeout is reserved for the server's own lapsed-lease recovery.
            'failure_category' => ['required_if:status,failed', 'nullable', Rule::in([
                AgentRunFailureCategory::NavigationTimeout->value,
                AgentRunFailureCategory::UnsupportedAts->value,
                AgentRunFailureCategory::UnexpectedError->value,
            ])],
            'failure_message' => ['required_if:status,failed', 'nullable', 'string', 'max:1000'],

            'screenshots' => ['nullable', 'array', 'max:'.PreparationArtifacts::MAX_SCREENSHOTS],
            'screenshots.*.file' => ['required', 'string', 'regex:'.PreparationArtifacts::SCREENSHOT_NAME],
            'screenshots.*.sha256' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/'],

            'report' => [Rule::requiredIf($succeeded), 'prohibited_if:status,failed', 'array'],
            'report.schemaVersion' => ['required_with:report', Rule::in([2])],
            'report.runId' => ['nullable', 'string', 'max:64'],
            'report.ats' => ['required_with:report', Rule::in(['lever', 'greenhouse'])],
            'report.requestedUrl' => ['nullable', 'string', 'max:2048'],
            'report.finalUrl' => ['nullable', 'string', 'max:2048'],
            'report.outcome' => ['required_with:report', Rule::in(array_column(PreparationOutcome::cases(), 'value'))],
            'report.reasons' => ['nullable', 'array', 'max:100'],
            'report.reasons.*' => ['string', 'max:500'],
            'report.warnings' => ['nullable', 'array', 'max:100'],
            'report.warnings.*' => ['string', 'max:500'],

            'report.summary' => ['nullable', 'array', 'max:20'],
            'report.summary.*' => ['integer', 'min:0'],
            'report.questionSummary' => ['nullable', 'array', 'max:20'],
            'report.questionSummary.*' => ['integer', 'min:0'],

            'report.fields' => ['nullable', 'array', 'max:400'],
            'report.fields.*.label' => ['nullable', 'string', 'max:500'],
            'report.fields.*.kind' => ['required', Rule::in(['text', 'textarea', 'select', 'radio', 'checkbox', 'file'])],
            'report.fields.*.required' => ['nullable', 'boolean'],
            'report.fields.*.status' => ['required', Rule::in(self::FIELD_STATUSES)],
            'report.fields.*.classification' => ['nullable', Rule::in(['known', 'uncertain', 'unknown'])],
            'report.fields.*.mappedTo' => ['nullable', 'string', 'max:50'],
            'report.fields.*.sensitive' => ['nullable', 'boolean'],
            'report.fields.*.note' => ['nullable', 'string', 'max:500'],
            'report.fields.*.expected' => ['nullable', 'string', 'max:500'],
            'report.fields.*.actual' => ['nullable', 'string', 'max:500'],
            'report.fields.*.presence' => ['nullable', 'string', 'max:50'],

            'report.questions' => ['nullable', 'array', 'max:300'],
            'report.questions.*.label' => ['nullable', 'string', 'max:500'],
            'report.questions.*.labelSource' => ['nullable', 'string', 'max:50'],
            'report.questions.*.kind' => ['required', Rule::in(['text', 'textarea', 'select', 'radio', 'checkbox', 'file'])],
            'report.questions.*.required' => ['nullable', 'boolean'],
            'report.questions.*.status' => ['required', Rule::in(self::FIELD_STATUSES)],
            'report.questions.*.review' => ['required', Rule::in(self::REVIEWS)],
            'report.questions.*.sensitive' => ['nullable', 'boolean'],
            'report.questions.*.provenance.source' => ['nullable', Rule::in(['applicant_fact', 'approved_answer', 'chosen_resume', 'none'])],

            'report.resume' => ['nullable', 'array'],
            'report.resume.sha256' => ['nullable', 'string', 'regex:/^[0-9a-f]{64}$/'],
            'report.resume.bytes' => ['nullable', 'integer', 'min:0'],
            'report.resume.uploaded' => ['nullable', 'boolean'],
            'report.resume.verified' => ['nullable', 'boolean'],
            'report.resume.verification' => ['nullable', 'string', 'max:40'],
            'report.resume.inputHeldChosenResume' => ['nullable', 'boolean'],
            'report.resume.upload' => ['nullable', 'array'],
            'report.resume.upload.outcome' => ['nullable', Rule::in(self::UPLOAD_OUTCOMES)],
            'report.resume.upload.artifactSha256Matches' => ['nullable', 'boolean'],
            'report.resume.upload.filenameVisible' => ['nullable', 'boolean'],
            'report.resume.upload.filenameVisibleBefore' => ['nullable', 'boolean'],
            'report.resume.upload.detail' => ['nullable', 'string', 'max:500'],
            'report.resume.upload.refusals' => ['nullable', 'array', 'max:20'],
            'report.resume.upload.refusals.*' => ['string', 'max:500'],
            'report.resume.upload.response' => ['nullable', 'array'],
            'report.resume.upload.response.exception' => ['nullable', 'string', 'max:100'],
            'report.resume.upload.response.destination' => ['nullable', 'string', 'max:300'],
            'report.resume.upload.response.status' => ['nullable', 'integer', 'between:100,599'],
            'report.resume.upload.response.statusText' => ['nullable', 'string', 'max:100'],
            'report.resume.upload.response.contentLength' => ['nullable', 'integer', 'min:0'],
            'report.resume.upload.response.failure' => ['nullable', 'string', 'max:200'],
            'report.resume.upload.response.durationMs' => ['nullable', 'integer', 'min:0'],
            'report.resume.parser' => ['nullable', 'array'],
            'report.resume.parser.status' => ['nullable', 'string', 'max:30'],

            'report.blockers' => ['nullable', 'array'],
            'report.blockers.captcha' => ['nullable', Rule::in(['none', 'inactive_markup', 'challenge_visible', 'uncertain'])],
            'report.blockers.authentication' => ['nullable', 'boolean'],
            'report.blockers.multistep' => ['nullable', 'boolean'],

            // Required, and the only assertion about submission this contract allows.
            'report.submission' => ['required_with:report', 'array'],
            'report.submission.assertion' => ['required_with:report', Rule::in(['worker_did_not_submit'])],
            'report.submission.suspected' => ['required_with:report', 'boolean'],
            'report.submission.blockedAttempts' => ['required_with:report', 'integer', 'min:0'],

            'report.safetyEvents' => ['nullable', 'array', 'max:300'],
            'report.safetyEvents.*.kind' => ['required', Rule::in(self::EVENT_KINDS)],
            'report.safetyEvents.*.detail' => ['nullable', 'string', 'max:500'],
            'report.safetyEvents.*.at' => ['nullable', 'string', 'max:40'],
            'report.safetyEvents.*.phase' => ['nullable', Rule::in(['preparation', 'human_hold'])],

            'report.diagnostics' => ['nullable', 'array'],
            'report.diagnostics.durationMs' => ['nullable', 'integer', 'min:0'],
            'report.diagnostics.headless' => ['nullable', 'boolean'],
            'report.diagnostics.held' => ['nullable', 'boolean'],

            'report.screenshots' => ['nullable', 'array', 'max:'.PreparationArtifacts::MAX_SCREENSHOTS],
            'report.screenshots.*.file' => ['required', 'string', 'regex:'.PreparationArtifacts::SCREENSHOT_NAME],
            'report.screenshots.*.phase' => ['nullable', 'string', 'max:20'],
            'report.screenshots.*.index' => ['nullable', 'integer', 'min:1'],
            'report.screenshots.*.of' => ['nullable', 'integer', 'min:1'],
            'report.screenshots.*.scrollY' => ['nullable', 'integer', 'min:0'],
            'report.screenshots.*.viewportWidth' => ['nullable', 'integer', 'min:0'],
            'report.screenshots.*.viewportHeight' => ['nullable', 'integer', 'min:0'],
            'report.screenshots.*.documentHeight' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
