<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Shared input contract for the two batch endpoints
 * (`jobs.batch.analyze` / `jobs.batch.match`).
 *
 * Local-first, single-user app with no auth — every request is
 * authorized. Both endpoints accept the same shape: an explicit list of
 * job posting ids the caller selected. There is deliberately no
 * "all filtered" mode — only what is named here is touched.
 */
class BatchOpportunitiesRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'job_ids' => ['required', 'array', 'min:1', 'max:50'],
            'job_ids.*' => ['required', 'integer'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $ids = $this->input('job_ids');

            if (! is_array($ids)) {
                return;
            }

            // (E3-1, H1) The `after` hook runs even when one
            // `job_ids.{index}` element has already failed its own rule —
            // so an element failing shape validation (e.g. a nested array in
            // `job_ids`) must not be touched here: comparing array values
            // with `array_unique` would throw "Array to string conversion"
            // (a 500) instead of letting the proper 422 per-index error
            // through. Bail out as soon as any single index has an error.
            foreach ($ids as $index => $value) {
                if ($validator->errors()->has('job_ids.'.$index)) {
                    return;
                }
            }

            // (E3-1, H2) Distinctness must be judged on the SAME integer
            // normalization `jobIds()` applies for processing, not on the
            // raw request values: `[1, '+1']`, `['3 ', '3']`, `['005', 5]`
            // all normalize to the same int under the `(int)` cast, so a
            // duplicate that only differs in representation must still be
            // rejected — without silently deduping or masking the per-index
            // validation errors, which are the ones the caller sees.
            $normalized = array_map(static fn (mixed $id): int => (int) $id, $ids);

            if (count($normalized) !== count(array_unique($normalized))) {
                $validator->errors()->add('job_ids', 'Job IDs must be unique.');
            }
        });
    }

    /**
     * The validated job posting ids as a plain int list, in request order.
     *
     * @return list<int>
     */
    public function jobIds(): array
    {
        $ids = $this->validated('job_ids');

        if (! is_array($ids)) {
            return [];
        }

        return array_values(array_map(static fn (mixed $id): int => (int) $id, $ids));
    }
}
