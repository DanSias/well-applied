<?php

namespace App\Models;

use App\Enums\DecisionSource;
use App\Enums\JobDisposition;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One entry in a job's decision history: what changed, who decided and why. Append-only: written by RecordJobDecision
 * and never edited or removed, so the history survives rediscovery and can always explain (and undo) the current state.
 *
 * @property int $id
 * @property int $job_posting_id
 * @property JobDisposition $from_disposition
 * @property JobDisposition $to_disposition
 * @property DecisionSource $source
 * @property string|null $reason
 * @property string|null $note
 * @property string|null $rule
 * @property array<string, mixed>|null $details
 * @property Carbon $created_at
 */
#[Fillable(['job_posting_id', 'from_disposition', 'to_disposition', 'source', 'reason', 'note', 'rule', 'details'])]
class JobDecision extends Model
{
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Job decision history is append-only.'));
        static::deleting(fn () => throw new LogicException('Job decision history is append-only.'));
    }

    protected function casts(): array
    {
        return [
            'from_disposition' => JobDisposition::class,
            'to_disposition' => JobDisposition::class,
            'source' => DecisionSource::class,
            'details' => 'array',
        ];
    }

    /**
     * @return BelongsTo<JobPosting, $this>
     */
    public function jobPosting(): BelongsTo
    {
        return $this->belongsTo(JobPosting::class);
    }
}
