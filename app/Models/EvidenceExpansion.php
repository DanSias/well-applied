<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One model-assisted reading of a clarification answer into separate claims. Cached by
 * `fingerprint` (prompt version, the answer, the evidence revision and the attribution context);
 * the claims themselves live as EvidenceProposals so the normal review rules apply.
 *
 * @property int $id
 * @property int $career_profile_id
 * @property int $clarification_answer_id
 * @property string $status ready|failed
 * @property string $fingerprint
 * @property string $prompt_version
 * @property int $evidence_revision
 * @property string|null $model
 * @property int|null $latency_ms
 * @property array<string, mixed>|null $usage
 * @property array<string, mixed>|null $result
 * @property string|null $failure
 * @property Carbon|null $created_at
 */
#[Fillable(['career_profile_id', 'clarification_answer_id', 'status', 'fingerprint', 'prompt_version', 'evidence_revision', 'model', 'latency_ms', 'usage', 'result', 'failure'])]
class EvidenceExpansion extends Model
{
    protected function casts(): array
    {
        return ['usage' => 'array', 'result' => 'array'];
    }

    /**
     * @return BelongsTo<ClarificationAnswer, $this>
     */
    public function answer(): BelongsTo
    {
        return $this->belongsTo(ClarificationAnswer::class, 'clarification_answer_id');
    }

    /**
     * @return HasMany<EvidenceProposal, $this>
     */
    public function proposals(): HasMany
    {
        return $this->hasMany(EvidenceProposal::class, 'expansion_id');
    }
}
