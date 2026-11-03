<?php

namespace App\Models;

use App\Enums\MarketSampleStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One collection of real postings for a Resume Profile, kept separate from
 * job tracking: nothing in it is an opportunity, and sampling never creates a
 * JobPosting or Application. `aggregate` is the derived capability map.
 *
 * @property int $id
 * @property int $resume_profile_id
 * @property MarketSampleStatus $status
 * @property bool $pilot
 * @property int $target_size
 * @property string $extraction_version
 * @property array<int, array<string, mixed>>|null $provider_log
 * @property array<string, int>|null $decision_counts
 * @property array<string, mixed>|null $aggregate
 * @property Carbon|null $aggregated_at
 * @property string|null $failure
 * @property Carbon|null $collected_at
 */
#[Fillable(['resume_profile_id', 'status', 'pilot', 'target_size', 'extraction_version', 'provider_log', 'decision_counts', 'aggregate', 'aggregated_at', 'failure', 'collected_at'])]
class MarketSample extends Model
{
    protected function casts(): array
    {
        return [
            'status' => MarketSampleStatus::class,
            'pilot' => 'boolean',
            'provider_log' => 'array',
            'decision_counts' => 'array',
            'aggregate' => 'array',
            'aggregated_at' => 'datetime',
            'collected_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ResumeProfile, $this>
     */
    public function resumeProfile(): BelongsTo
    {
        return $this->belongsTo(ResumeProfile::class);
    }

    /**
     * @return HasMany<MarketSamplePosting, $this>
     */
    public function postings(): HasMany
    {
        return $this->hasMany(MarketSamplePosting::class);
    }

    /**
     * @return HasMany<MarketSamplePosting, $this>
     */
    public function includedPostings(): HasMany
    {
        return $this->hasMany(MarketSamplePosting::class)->where('included', true);
    }
}
