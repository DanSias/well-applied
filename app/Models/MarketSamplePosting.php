<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A posting considered for a sample, with the reason it was kept or dropped.
 * The description and extraction are cached by `content_hash` and the
 * extraction version, so an unchanged posting is never read twice.
 *
 * @property int $id
 * @property int $market_sample_id
 * @property int|null $job_posting_id
 * @property string $provider
 * @property string|null $source_job_id
 * @property string|null $source_url
 * @property string $company
 * @property string $title
 * @property string|null $location
 * @property string|null $remote_status
 * @property string|null $seniority
 * @property string|null $primary_stack
 * @property string $description_completeness
 * @property string|null $description
 * @property string $content_hash
 * @property Carbon|null $posted_at
 * @property Carbon $retrieved_at
 * @property bool $included
 * @property string $decision
 * @property string|null $decision_detail
 * @property array<string, mixed>|null $signals
 * @property int|null $duplicate_of_id
 * @property string|null $extraction_version
 * @property array<string, mixed>|null $extraction
 */
#[Fillable([
    'market_sample_id', 'job_posting_id', 'provider', 'source_job_id', 'source_url', 'company', 'title', 'location',
    'remote_status', 'seniority', 'primary_stack', 'description_completeness', 'description', 'content_hash',
    'posted_at', 'retrieved_at', 'included', 'decision', 'decision_detail', 'signals', 'duplicate_of_id', 'extraction_version', 'extraction',
])]
class MarketSamplePosting extends Model
{
    protected function casts(): array
    {
        return ['posted_at' => 'datetime', 'retrieved_at' => 'datetime', 'included' => 'boolean', 'extraction' => 'array', 'signals' => 'array'];
    }

    /**
     * @return BelongsTo<MarketSample, $this>
     */
    public function sample(): BelongsTo
    {
        return $this->belongsTo(MarketSample::class, 'market_sample_id');
    }
}
