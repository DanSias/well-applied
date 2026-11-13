<?php

namespace App\Http\Requests;

use App\Enums\DismissalReason;
use App\Enums\JobDisposition;
use App\Support\JobDecisions\RecordJobDecisionBatch;
use App\Support\Navigation\ReturnTo;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Input for deciding on one job (`jobs.decision.store`) or an explicit set of jobs (`jobs.batch.decision`). Local-first,
 * single-user app with no auth, so every request is authorized, like the other job endpoints. Bulk requests may only
 * shortlist, dismiss or restore: pursuing a job is a deliberate one-job action.
 */
class JobDecisionRequest extends FormRequest
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
        $allowed = $this->routeIs('jobs.batch.decision')
            ? [JobDisposition::New, JobDisposition::Shortlisted, JobDisposition::Dismissed]
            : JobDisposition::cases();

        return [
            'disposition' => ['required', Rule::in(array_map(fn (JobDisposition $d) => $d->value, $allowed))],
            'reason' => ['nullable', 'required_if:disposition,dismissed', Rule::enum(DismissalReason::class)],
            'note' => ['nullable', 'string', 'max:500'],
            // Where a single-job decision lands: only fixed keys, see ReturnTo. Bulk decisions never navigate.
            'return_to' => [$this->routeIs('jobs.batch.decision') ? 'prohibited' : 'sometimes', 'nullable', ReturnTo::RULE],
            'job_ids' => [$this->routeIs('jobs.batch.decision') ? 'required' : 'prohibited', 'array', 'min:1', 'max:'.RecordJobDecisionBatch::MAX_ITEMS],
            'job_ids.*' => ['required', 'integer', 'distinct'],
        ];
    }

    public function disposition(): JobDisposition
    {
        return JobDisposition::from((string) $this->validated('disposition'));
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

    /**
     * @return list<int>
     */
    public function jobIds(): array
    {
        return array_values(array_map('intval', (array) $this->validated('job_ids')));
    }
}
