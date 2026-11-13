<?php

namespace App\Http\Requests;

use App\Support\Navigation\ReturnTo;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Marking an application as recorded in error (`submissions.correction.store`): a correction for a record that should
 * never have been made. It needs an explicit confirmation, and may carry a reason. Nothing is deleted.
 */
class StoreSubmissionCorrectionRequest extends FormRequest
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
            'confirmed' => ['required', 'accepted'],
            'reason' => ['nullable', 'string', 'max:5000'],
            'return_to' => ['sometimes', 'nullable', ReturnTo::RULE],
        ];
    }

    public function messages(): array
    {
        return ['confirmed.accepted' => 'Confirm that this application should not have been recorded.', 'confirmed.required' => 'Confirm that this application should not have been recorded.'];
    }
}
