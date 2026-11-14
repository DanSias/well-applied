<?php

namespace App\Models;

use App\Enums\DiscoveryRejectionReason;
use App\Enums\JobDiscoverySource;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A candidate the global discovery filter rejected — see this table's migration. Identified by (source, source_job_id).
 * Active while resolved_at is null; a later run that accepts the same candidate sets resolved_at, and a later rejection
 * clears it again.
 *
 * @property int $id
 * @property JobDiscoverySource $source
 * @property string $source_job_id
 * @property string $title
 * @property string $company
 * @property string|null $location
 * @property string|null $remote_status
 * @property string|null $source_url
 * @property DiscoveryRejectionReason $reason_code
 * @property string|null $matched_term
 * @property string|null $matched_field
 * @property string|null $evidence_snippet
 * @property Carbon $first_seen_at
 * @property Carbon $last_seen_at
 * @property int $seen_count
 * @property int|null $last_discovery_run_id
 * @property Carbon|null $resolved_at
 */
#[Fillable([
    'source', 'source_job_id', 'title', 'company', 'location', 'remote_status', 'source_url', 'reason_code', 'matched_term',
    'matched_field', 'evidence_snippet', 'first_seen_at', 'last_seen_at', 'seen_count', 'last_discovery_run_id', 'resolved_at',
])]
class DiscoveryRejection extends Model
{
    protected function casts(): array
    {
        return [
            'source' => JobDiscoverySource::class,
            'reason_code' => DiscoveryRejectionReason::class,
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<DiscoveryRejection>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('resolved_at');
    }
}
