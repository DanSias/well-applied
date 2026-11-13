<?php

namespace App\Http\Requests;

use App\Enums\SubmissionEventType;
use App\Support\Navigation\ReturnTo;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A person's note of something that happened to an application they submitted (`submissions.events.store`): a manual
 * update, not a verified employer message. Shape only: every rule (not after an ending, not before the submission,
 * idempotency) lives in RecordSubmissionEvent. Like the other endpoints there is no sign-in, so every request is
 * authorized; the controller limits it to the current profile's submissions.
 *
 * The date is a calendar date with the person's timezone, never a bare timestamp, for the same reason a submission's is.
 * A mistaken record is corrected through its own request, never by choosing a type here.
 */
class StoreSubmissionEventRequest extends FormRequest
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
            'idempotency_key' => ['required', 'string', 'max:100'],
            'type' => ['required', Rule::in(self::recordableTypes())],
            'occurred_on' => ['required', 'date_format:Y-m-d'],
            'timezone' => ['required', 'timezone:all'],
            'note' => ['nullable', 'string', 'max:5000'],
            'url' => ['nullable', 'url:http,https', 'max:2048'],
            'return_to' => ['sometimes', 'nullable', ReturnTo::RULE],
        ];
    }

    /**
     * @return list<string>
     */
    public static function recordableTypes(): array
    {
        return [
            SubmissionEventType::ConfirmationReceived->value,
            SubmissionEventType::FollowUpSent->value,
            SubmissionEventType::Interview->value,
            SubmissionEventType::Rejected->value,
            SubmissionEventType::Withdrawn->value,
            SubmissionEventType::OfferReceived->value,
            SubmissionEventType::OfferAccepted->value,
            SubmissionEventType::OfferDeclined->value,
        ];
    }
}
