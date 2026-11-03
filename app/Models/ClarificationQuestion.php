<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A ranked, persisted question. Ranking happens once, when the session is
 * created, and is stored here, so a reload shows the same questions in the
 * same order and the score explains itself (`score_breakdown`).
 *
 * @property int $id
 * @property int $clarification_session_id
 * @property string $topic_key
 * @property string $topic_label
 * @property int|null $job_analysis_finding_id The finding that drove the score.
 * @property array<int, int> $related_finding_ids
 * @property string $question_text
 * @property float $score
 * @property array<string, mixed> $score_breakdown
 * @property array<string, mixed> $context
 * @property string|null $suggested_attributable_type
 * @property int|null $suggested_attributable_id
 * @property string $suggested_visibility
 * @property string $status open|answered|skipped
 * @property int $sort_order
 */
#[Fillable([
    'clarification_session_id', 'topic_key', 'topic_label', 'job_analysis_finding_id', 'related_finding_ids',
    'question_text', 'score', 'score_breakdown', 'context', 'suggested_attributable_type',
    'suggested_attributable_id', 'suggested_visibility', 'status', 'sort_order',
])]
class ClarificationQuestion extends Model
{
    protected function casts(): array
    {
        return [
            'related_finding_ids' => 'array',
            'score' => 'float',
            'score_breakdown' => 'array',
            'context' => 'array',
        ];
    }

    /**
     * @return BelongsTo<ClarificationSession, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(ClarificationSession::class, 'clarification_session_id');
    }

    /**
     * @return HasMany<ClarificationAnswer, $this>
     */
    public function answers(): HasMany
    {
        return $this->hasMany(ClarificationAnswer::class)->orderBy('id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function suggestedAttributable(): MorphTo
    {
        return $this->morphTo('suggestedAttributable', 'suggested_attributable_type', 'suggested_attributable_id');
    }
}
