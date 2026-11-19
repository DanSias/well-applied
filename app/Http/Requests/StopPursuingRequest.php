<?php

namespace App\Http\Requests;

use App\Enums\DismissalReason;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Input for ending pursuit of one opportunity (`opportunities.stop`). A reason is optional; when given it must be one of
 * the pursuit-ending reasons or the shared `other`, and a short note is optional. Local-first, single-user app with no auth,
 * so every request is authorized, like the other job endpoints.
 */
class StopPursuingRequest extends FormRequest
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
            'reason' => ['nullable', Rule::in(array_map(fn (DismissalReason $r) => $r->value, self::reasons()))],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return list<DismissalReason>
     */
    public static function reasons(): array
    {
        return [DismissalReason::PostingClosed, DismissalReason::NoLongerInterested, DismissalReason::NotAGoodFit, DismissalReason::Other];
    }

    public function reason(): ?DismissalReason
    {
        return DismissalReason::tryFrom((string) $this->validated('reason'));
    }

    public function note(): ?string
    {
        $note = $this->validated('note');

        return is_string($note) ? $note : null;
    }
}
