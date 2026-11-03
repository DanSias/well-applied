<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * How the user wants existing experience presented (for example "emphasize
 * marketing and conversion work where relevant"). Guidance only: it holds
 * no factual claim and is never a CareerFact.
 *
 * @property int $id
 * @property int $career_profile_id
 * @property string $topic_key
 * @property string $topic
 * @property string $guidance
 * @property Carbon $confirmed_at
 * @property Carbon|null $revoked_at
 * @property int|null $source_job_posting_id
 * @property int|null $source_answer_id
 */
#[Fillable(['career_profile_id', 'topic_key', 'topic', 'guidance', 'confirmed_at', 'revoked_at', 'source_job_posting_id', 'source_answer_id'])]
class PresentationPreference extends Model
{
    protected function casts(): array
    {
        return ['confirmed_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    /**
     * @param  Builder<PresentationPreference>  $query
     * @return Builder<PresentationPreference>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }
}
