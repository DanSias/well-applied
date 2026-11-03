<?php

namespace App\Models;

use App\Support\Clarification\EvidenceRevision;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One version of a synthesized professional profile. `content` holds the evidence inventory
 * (deterministic: capabilities, ranked projects, accomplishments, market provenance, every item
 * with its supporting fact keys) and the presentation language (model-written: positioning,
 * summary, differentiators). `edits` is the user's decisions, kept apart so regenerating never
 * silently discards them and approving locks what was reviewed. A version is never rewritten;
 * a new synthesis is a new version, and the one the user approves is the only one a reference
 * resume can be composed from.
 *
 * @property int $id
 * @property int $career_profile_id
 * @property int $resume_profile_id
 * @property int $version
 * @property string $status draft|approved|superseded
 * @property int $evidence_revision
 * @property int|null $market_sample_id
 * @property bool $pilot
 * @property string $input_hash
 * @property string|null $prompt_version
 * @property string|null $generated_by
 * @property array<string, mixed>|null $usage
 * @property array<string, mixed> $content
 * @property array<string, mixed>|null $edits
 * @property array<string, mixed>|null $validation
 * @property Carbon|null $approved_at
 * @property-read ResumeProfile $resumeProfile
 */
#[Fillable([
    'career_profile_id', 'resume_profile_id', 'version', 'status', 'evidence_revision', 'market_sample_id', 'pilot', 'input_hash',
    'prompt_version', 'generated_by', 'usage', 'content', 'edits', 'validation', 'approved_at',
])]
class ResumeProfileSynthesis extends Model
{
    protected $table = 'resume_profile_syntheses';

    protected function casts(): array
    {
        return ['pilot' => 'boolean', 'usage' => 'array', 'content' => 'array', 'edits' => 'array', 'validation' => 'array', 'approved_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<ResumeProfile, $this>
     */
    public function resumeProfile(): BelongsTo
    {
        return $this->belongsTo(ResumeProfile::class);
    }

    /**
     * @return BelongsTo<CareerProfile, $this>
     */
    public function careerProfile(): BelongsTo
    {
        return $this->belongsTo(CareerProfile::class);
    }

    /**
     * @return HasMany<ResumeVariant, $this>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(ResumeVariant::class, 'resume_profile_synthesis_id');
    }

    /**
     * The approved evidence changed after this version was built, so it no longer describes the record.
     */
    public function isStale(): bool
    {
        return $this->evidence_revision !== EvidenceRevision::current($this->careerProfile);
    }
}
