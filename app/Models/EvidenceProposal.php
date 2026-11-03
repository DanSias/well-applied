<?php

namespace App\Models;

use App\Enums\ProposalKind;
use App\Enums\ProposalStatus;
use App\Enums\Verification;
use App\Enums\Visibility;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * A draft of career evidence derived from a user's answer. Nothing here is
 * canonical: only approval (App\Support\Clarification\ApproveEvidenceProposal)
 * creates a CareerFact, CareerLimitation or PresentationPreference.
 *
 * `history` is the approval history: one entry per create, edit, approve,
 * reject or supersede, each with a timestamp.
 *
 * @property int $id
 * @property int $career_profile_id
 * @property int|null $clarification_answer_id
 * @property int|null $job_posting_id
 * @property int|null $job_analysis_finding_id
 * @property ProposalKind $kind
 * @property ProposalStatus $status
 * @property string $topic_key
 * @property string $topic_label
 * @property string $statement
 * @property array<int, string> $scopes
 * @property string|null $attributable_type
 * @property int|null $attributable_id
 * @property Visibility $visibility
 * @property Verification $verification
 * @property int|null $career_fact_id
 * @property int|null $career_limitation_id
 * @property int|null $presentation_preference_id
 * @property int $revision
 * @property int|null $revises_proposal_id
 * @property array<int, array<string, mixed>> $history
 * @property Carbon|null $decided_at
 * @property-read ClarificationAnswer|null $answer
 */
#[Fillable([
    'career_profile_id', 'clarification_answer_id', 'job_posting_id', 'job_analysis_finding_id', 'kind', 'status',
    'topic_key', 'topic_label', 'statement', 'scopes', 'attributable_type', 'attributable_id', 'visibility',
    'revision', 'revises_proposal_id', 'verification', 'career_fact_id', 'career_limitation_id', 'presentation_preference_id', 'history', 'decided_at',
])]
class EvidenceProposal extends Model
{
    protected function casts(): array
    {
        return [
            'kind' => ProposalKind::class,
            'status' => ProposalStatus::class,
            'visibility' => Visibility::class,
            'verification' => Verification::class,
            'scopes' => 'array',
            'history' => 'array',
            'decided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ClarificationAnswer, $this>
     */
    public function answer(): BelongsTo
    {
        return $this->belongsTo(ClarificationAnswer::class, 'clarification_answer_id');
    }

    /**
     * @return BelongsTo<EvidenceProposal, $this>
     */
    public function revises(): BelongsTo
    {
        return $this->belongsTo(self::class, 'revises_proposal_id');
    }

    /**
     * @return BelongsTo<CareerFact, $this>
     */
    public function careerFact(): BelongsTo
    {
        return $this->belongsTo(CareerFact::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function attributable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    public function recordHistory(string $action, array $detail = []): void
    {
        $history = $this->history ?? [];
        $history[] = ['at' => now()->toIso8601String(), 'action' => $action, ...$detail];
        $this->history = $history;
    }
}
