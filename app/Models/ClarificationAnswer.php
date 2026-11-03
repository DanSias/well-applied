<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The user's verbatim answer: the provenance for everything derived from
 * it. Never edited after it is stored.
 *
 * @property int $id
 * @property int $clarification_question_id
 * @property string $mode experience|no_experience|preference
 * @property array<int, string> $scopes ResponsibilityScope values
 * @property string $answer_text
 */
#[Fillable(['clarification_question_id', 'mode', 'scopes', 'answer_text'])]
class ClarificationAnswer extends Model
{
    protected function casts(): array
    {
        return ['scopes' => 'array'];
    }

    /**
     * @return BelongsTo<ClarificationQuestion, $this>
     */
    public function question(): BelongsTo
    {
        return $this->belongsTo(ClarificationQuestion::class, 'clarification_question_id');
    }

    /**
     * @return HasMany<EvidenceProposal, $this>
     */
    public function proposals(): HasMany
    {
        return $this->hasMany(EvidenceProposal::class);
    }
}
