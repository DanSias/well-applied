<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An explicit, user-confirmed qualification gap (for example "Ruby on
 * Rails: no direct experience"). Never a resume accomplishment: it is not a
 * CareerFact, is never offered to Selection or Wording as evidence, blocks
 * unsupported claims about its `terms`, and suppresses redundant questions.
 * Revisable: revoking sets `revoked_at`, after which it has no effect.
 *
 * @property int $id
 * @property int $career_profile_id
 * @property string $topic_key
 * @property string $topic
 * @property array<int, string> $terms Words and phrases that must not be claimed.
 * @property string $status no_direct_experience|limited_experience
 * @property string|null $note
 * @property string $source
 * @property Carbon $confirmed_at
 * @property Carbon|null $revoked_at
 * @property int|null $source_job_posting_id
 * @property int|null $source_answer_id
 */
#[Fillable([
    'career_profile_id', 'topic_key', 'topic', 'terms', 'status', 'note', 'source', 'confirmed_at', 'revoked_at',
    'source_job_posting_id', 'source_answer_id',
])]
class CareerLimitation extends Model
{
    protected function casts(): array
    {
        return [
            'terms' => 'array',
            'confirmed_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<CareerLimitation>  $query
     * @return Builder<CareerLimitation>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }
}
