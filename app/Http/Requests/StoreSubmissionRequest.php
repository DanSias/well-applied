<?php

namespace App\Http\Requests;

use App\Support\Navigation\ReturnTo;
use App\Support\Submissions\SubmittedResumeSnapshot;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A person's report that they submitted an application themselves (`jobs.submissions.store`). Local-first,
 * single-user app with no auth, so every request is authorized, like the other job endpoints; the controller still
 * limits it to the current profile's jobs. Shape only: every business rule lives in RecordSubmission.
 *
 * The date is a calendar date plus the person's IANA timezone, never a bare timestamp: a date is all most people know,
 * and the server must not guess which day that was for them.
 */
class StoreSubmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // One per recording action, kept stable across retries, so a retry or a double click records once.
            'idempotency_key' => ['required', 'string', 'max:100'],
            'submitted_on' => ['required', 'date_format:Y-m-d'],
            'timezone' => ['required', 'timezone:all'],
            'resume_choice' => ['required', Rule::in(['well_applied', 'external', 'none'])],
            'resume_variant_id' => ['nullable', 'required_if:resume_choice,well_applied', 'integer'],
            // Never silently attach a resume: choosing one of ours needs this explicit confirmation.
            'resume_confirmed' => ['nullable', 'accepted_if:resume_choice,well_applied'],
            'pdf' => ['nullable', 'required_if:resume_choice,external', 'file', 'mimes:pdf', 'max:10240'],
            'application_url' => ['nullable', 'url:http,https', 'max:2048'],
            'notes' => ['nullable', 'string', 'max:5000'],
            // Only after the person confirmed they really applied again.
            'allow_another' => ['sometimes', 'boolean'],
            'return_to' => ['sometimes', 'nullable', ReturnTo::RULE],
        ];
    }

    public function messages(): array
    {
        return [
            'resume_confirmed.accepted_if' => 'Confirm that this is the document you actually submitted.',
            'resume_variant_id.required_if' => 'Choose which resume you used.',
            'pdf.required_if' => 'Choose the PDF you submitted.',
            // PHP refuses a file over its own upload limit before any rule runs; say so, with the number.
            'pdf.uploaded' => 'That file is larger than this server accepts ('.round(SubmittedResumeSnapshot::maxUploadBytes() / 1048576, 1).' MB).',
        ];
    }
}
