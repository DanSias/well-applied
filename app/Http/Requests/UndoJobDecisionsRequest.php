<?php

namespace App\Http\Requests;

use App\Support\JobDecisions\RecordJobDecisionBatch;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/** Which decisions to undo, as job id => decision id (the pairs a decision result reported). */
class UndoJobDecisionsRequest extends FormRequest
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
            'decisions' => ['required', 'array', 'min:1', 'max:'.RecordJobDecisionBatch::MAX_ITEMS],
            'decisions.*' => ['required', 'integer'],
        ];
    }

    /**
     * @return array<int, int>
     */
    public function decisions(): array
    {
        $out = [];

        foreach ((array) $this->validated('decisions') as $jobId => $decisionId) {
            $out[(int) $jobId] = (int) $decisionId;
        }

        return $out;
    }
}
