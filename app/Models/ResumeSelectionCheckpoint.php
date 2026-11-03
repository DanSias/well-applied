<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A validated Resume Selection response saved so that a failure in a
 * later stage (Wording) does not repeat the Selection call. Reuse is
 * keyed by `fingerprint`, a hash of the prompt, schema and every input
 * Selection saw, so a changed profile, match, visibility or prompt can
 * never reuse a stale one. The stored content is revalidated on reuse.
 * `consumed_at` marks a checkpoint that already produced a variant, so
 * a later manual generation starts fresh. Never deleted by the app.
 *
 * @property int $id
 * @property int $job_match_id
 * @property string $fingerprint
 * @property string $prompt_version
 * @property string $schema_version
 * @property string $provider
 * @property string $model
 * @property array<string, mixed> $structured_content
 * @property int|null $duration_ms
 * @property Carbon|null $consumed_at
 */
#[Fillable([
    'job_match_id',
    'fingerprint',
    'prompt_version',
    'schema_version',
    'provider',
    'model',
    'structured_content',
    'duration_ms',
    'consumed_at',
])]
class ResumeSelectionCheckpoint extends Model
{
    protected function casts(): array
    {
        return [
            'structured_content' => 'array',
            'consumed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<JobMatch, $this> */
    public function jobMatch(): BelongsTo
    {
        return $this->belongsTo(JobMatch::class);
    }
}
