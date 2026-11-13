<?php

namespace App\Models;

use App\Enums\SubmissionMethod;
use Database\Factories\SubmissionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A person's record that a real job application was submitted. A user assertion, not proof the employer received it,
 * and deliberately a separate thing from Application (the browser inspection record, which this never replaces,
 * changes or is inferred from). Written only by RecordSubmission, and never removed: history is the point. There is no
 * status column; the standing of an application is derived from its events (SummarizeSubmission).
 *
 * @property int $id
 * @property int $job_posting_id
 * @property int|null $application_id Inspection record current at the time; provenance only.
 * @property int|null $resume_variant_id
 * @property string|null $application_url
 * @property SubmissionMethod $method
 * @property Carbon $submitted_at The instant used to order things; for an entered date, the start of that day in the person's timezone.
 * @property string|null $submitted_on The calendar date the person entered (Y-m-d), never converted through a timezone; null on older rows.
 * @property string|null $notes
 * @property int|null $workflow_run_id Reserved for browser-assisted submission.
 * @property string|null $submitted_pdf_path
 * @property string|null $submitted_pdf_sha256
 * @property string|null $idempotency_key
 * @property Carbon|null $created_at When it was recorded.
 * @property Carbon|null $updated_at
 */
#[Fillable(['job_posting_id', 'application_id', 'resume_variant_id', 'application_url', 'method', 'submitted_at', 'submitted_on', 'notes', 'workflow_run_id', 'submitted_pdf_path', 'submitted_pdf_sha256', 'idempotency_key'])]
class Submission extends Model
{
    /** @use HasFactory<SubmissionFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Submission history is never deleted; record a correction instead.'));
    }

    protected function casts(): array
    {
        return [
            'method' => SubmissionMethod::class,
            'submitted_at' => 'datetime',
        ];
    }

    /**
     * The date to show for this submission, as Y-m-d. The date the person entered when there is one (the authority, shown
     * as written in any timezone); otherwise, for rows recorded before that was kept, the date of `submitted_at` in the
     * application's timezone. That fallback is documented, not exact: the entered timezone was never stored.
     */
    public function displayDate(): string
    {
        return $this->submitted_on ?? $this->submitted_at->setTimezone(config('app.timezone'))->toDateString();
    }

    /**
     * @return BelongsTo<JobPosting, $this>
     */
    public function jobPosting(): BelongsTo
    {
        return $this->belongsTo(JobPosting::class);
    }

    /**
     * @return BelongsTo<Application, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /**
     * @return BelongsTo<ResumeVariant, $this>
     */
    public function resumeVariant(): BelongsTo
    {
        return $this->belongsTo(ResumeVariant::class);
    }

    /**
     * @return BelongsTo<WorkflowRun, $this>
     */
    public function workflowRun(): BelongsTo
    {
        return $this->belongsTo(WorkflowRun::class);
    }

    /**
     * @return HasMany<SubmissionEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(SubmissionEvent::class)->orderBy('occurred_at')->orderBy('id');
    }
}
