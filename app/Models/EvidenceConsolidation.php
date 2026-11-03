<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One reviewable correction to the canonical career evidence: revise a fact in place, add a fact
 * to a shared claim, tag skills, or reject a redundant pending proposal. Nothing here is canonical;
 * only App\Support\Consolidation\ApplyConsolidation changes a CareerFact, and only when the user
 * approves this item. `notes` carries the planner's reasoning, `applied` what approval changed.
 *
 * @property int $id
 * @property int $career_profile_id
 * @property string $code
 * @property string $theme
 * @property string $action revise|create|group|tag|advice
 * @property string $status proposed|approved|rejected
 * @property int $sort_order
 * @property string $title
 * @property string|null $summary
 * @property int|null $target_fact_id
 * @property int|null $target_proposal_id
 * @property int|null $source_answer_id
 * @property string|null $topic_key
 * @property string|null $statement
 * @property array<int, string>|null $scopes
 * @property string|null $attributable_type
 * @property int|null $attributable_id
 * @property string|null $visibility
 * @property string|null $verification
 * @property string|null $claim_group
 * @property array<int, int>|null $fact_ids
 * @property array<int, string>|null $skills
 * @property array<int, int>|null $links
 * @property array<string, mixed>|null $decision
 * @property array<string, mixed>|null $notes
 * @property array<int, array<string, mixed>>|null $history
 * @property array<string, mixed>|null $applied
 * @property Carbon|null $decided_at
 */
#[Fillable([
    'career_profile_id', 'code', 'theme', 'action', 'status', 'sort_order', 'title', 'summary', 'target_fact_id', 'target_proposal_id', 'source_answer_id', 'topic_key',
    'statement', 'scopes', 'attributable_type', 'attributable_id', 'visibility', 'verification', 'claim_group', 'fact_ids', 'skills', 'links', 'decision', 'notes', 'history', 'applied', 'decided_at',
])]
class EvidenceConsolidation extends Model
{
    protected function casts(): array
    {
        return [
            'scopes' => 'array', 'fact_ids' => 'array', 'skills' => 'array', 'links' => 'array', 'decision' => 'array',
            'notes' => 'array', 'history' => 'array', 'applied' => 'array', 'decided_at' => 'datetime',
        ];
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
