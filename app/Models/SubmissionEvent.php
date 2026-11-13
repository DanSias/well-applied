<?php

namespace App\Models;

use App\Enums\SubmissionEventSource;
use App\Enums\SubmissionEventType;
use Database\Factories\SubmissionEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One thing that happened to a submission. Append-only: written once by RecordSubmissionEvent and never updated or
 * removed, so the history can always explain the current state. `occurred_at` is when it happened; `created_at` is when
 * it was recorded, so a late entry keeps its true time.
 *
 * @property int $id
 * @property int $submission_id
 * @property SubmissionEventType $type
 * @property Carbon $occurred_at The instant used for ordering; for an entered date, the start of that day in the person's timezone.
 * @property string|null $occurred_on The calendar date the person entered (Y-m-d), never converted through a timezone; null on older rows.
 * @property SubmissionEventSource $source
 * @property string|null $note
 * @property array<string, mixed>|null $evidence
 * @property string|null $idempotency_key
 * @property Carbon $created_at
 */
#[Fillable(['submission_id', 'type', 'occurred_at', 'occurred_on', 'source', 'note', 'evidence', 'idempotency_key'])]
class SubmissionEvent extends Model
{
    /** @use HasFactory<SubmissionEventFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Submission event history is append-only.'));
        static::deleting(fn () => throw new LogicException('Submission event history is append-only.'));
    }

    protected function casts(): array
    {
        return [
            'type' => SubmissionEventType::class,
            'source' => SubmissionEventSource::class,
            'occurred_at' => 'datetime',
            'evidence' => 'array',
        ];
    }

    /**
     * The date to show, as Y-m-d: the date entered, or for older events the date of `occurred_at` in the application's
     * timezone (documented as approximate: the entered timezone was never stored).
     */
    public function displayDate(): string
    {
        return $this->occurred_on ?? $this->occurred_at->setTimezone(config('app.timezone'))->toDateString();
    }

    /**
     * @return BelongsTo<Submission, $this>
     */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }
}
