<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One Application Readiness Review of one exact resume variant. `result` holds the validated coverage assessment, the
 * gap classification, the document checks and the credibility risks; `metrics` holds duration, tokens and calls. The
 * fingerprints say what the review was made from, so a changed job description, document or review prompt makes it stale
 * (the variant itself cannot change).
 *
 * @property int $id
 * @property int $resume_variant_id
 * @property int $job_analysis_id
 * @property string $status queued|running|succeeded|failed
 * @property Carbon $requested_at
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property string|null $phase
 * @property string $description_fingerprint
 * @property string|null $analysis_fingerprint
 * @property string|null $artifact_fingerprint
 * @property string $prompt_version
 * @property string|null $model
 * @property array<string, mixed>|null $result
 * @property array<string, mixed>|null $metrics
 * @property string|null $failure_message
 */
#[Fillable([
    'resume_variant_id', 'job_analysis_id', 'status', 'requested_at', 'started_at', 'finished_at', 'phase',
    'description_fingerprint', 'analysis_fingerprint', 'artifact_fingerprint', 'prompt_version', 'model', 'result', 'metrics', 'failure_message',
])]
class ResumeReadinessReview extends Model
{
    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'result' => 'array',
            'metrics' => 'array',
        ];
    }

    /**
     * @return BelongsTo<ResumeVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ResumeVariant::class, 'resume_variant_id');
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['queued', 'running'], true);
    }
}
