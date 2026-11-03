<?php

namespace App\Models;

use App\Enums\ResumeProfileStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A reusable professional narrative for a category of jobs. It owns no
 * career facts: it points at a market sample, at the evidence revision its
 * coverage was last computed against, and at the interview that enriches the
 * shared career database. Stable evidence is referenced by fact key, never
 * copied.
 *
 * @property int $id
 * @property int $career_profile_id
 * @property string $slug
 * @property string $name
 * @property string $role_family
 * @property string|null $purpose
 * @property array<string, mixed>|null $search_parameters
 * @property ResumeProfileStatus $status
 * @property int $revision
 * @property int|null $market_sample_id
 * @property int $evidence_revision
 * @property string|null $presentation_guidance
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['career_profile_id', 'slug', 'name', 'role_family', 'purpose', 'search_parameters', 'status', 'revision', 'market_sample_id', 'evidence_revision', 'presentation_guidance'])]
class ResumeProfile extends Model
{
    protected function casts(): array
    {
        return ['status' => ResumeProfileStatus::class, 'search_parameters' => 'array'];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * @return BelongsTo<CareerProfile, $this>
     */
    public function careerProfile(): BelongsTo
    {
        return $this->belongsTo(CareerProfile::class);
    }

    /**
     * @return HasMany<MarketSample, $this>
     */
    public function samples(): HasMany
    {
        return $this->hasMany(MarketSample::class)->orderByDesc('id');
    }

    /**
     * @return BelongsTo<MarketSample, $this>
     */
    public function currentSample(): BelongsTo
    {
        return $this->belongsTo(MarketSample::class, 'market_sample_id');
    }

    /**
     * @return HasMany<ClarificationSession, $this>
     */
    public function interviewSessions(): HasMany
    {
        return $this->hasMany(ClarificationSession::class);
    }
}
