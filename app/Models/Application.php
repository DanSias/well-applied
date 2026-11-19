<?php

namespace App\Models;

use App\Enums\ApplicationStatus;
use App\Support\ApplicationInspection\InspectionUrl;
use Database\Factories\ApplicationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A candidate's mutable operational attempt to evaluate or pursue one
 * JobPosting — the first entity in this domain that is neither
 * canonical data nor an immutable AI-generated snapshot. See
 * docs/domain-model.md "Application" and docs/application-inspector.md.
 *
 * status is real, mutable state (App\Enums\ApplicationStatus) — not a
 * snapshot version like JobAnalysis/JobMatch/ResumeVariant, because it
 * describes an ongoing real-world process rather than a point-in-time
 * generation result.
 *
 * @property int $id
 * @property int $job_posting_id
 * @property ApplicationStatus $status
 * @property string|null $application_url Where the application is actually completed; job_postings.source_url is where the job was discovered.
 * @property string|null $application_url_origin 'user' (a person's override, never replaced automatically) or 'resolved' (the inspector reached a form there).
 * @property Carbon|null $application_url_set_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['job_posting_id', 'status', 'application_url', 'application_url_origin', 'application_url_set_at'])]
class Application extends Model
{
    /** @use HasFactory<ApplicationFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => ApplicationStatus::class,
            'application_url_set_at' => 'datetime',
        ];
    }

    public const URL_USER = 'user';

    public const URL_RESOLVED = 'resolved';

    /**
     * Records the URL the inspector reached a form at. Never replaces a person's own URL, and a URL that is just the
     * job's source URL is not a resolution (nothing was resolved), so it clears an earlier resolved one instead.
     */
    public function recordResolvedUrl(string $url, ?string $sourceUrl): void
    {
        if ($this->application_url_origin === self::URL_USER) {
            return;
        }

        if ($sourceUrl !== null && InspectionUrl::comparable($url) === InspectionUrl::comparable($sourceUrl)) {
            $this->update(['application_url' => null, 'application_url_origin' => null, 'application_url_set_at' => null]);

            return;
        }

        $this->update(['application_url' => $url, 'application_url_origin' => self::URL_RESOLVED, 'application_url_set_at' => now()]);
    }

    /**
     * @return BelongsTo<JobPosting, $this>
     */
    public function jobPosting(): BelongsTo
    {
        return $this->belongsTo(JobPosting::class);
    }

    /**
     * @return HasMany<WorkflowRun, $this>
     */
    public function workflowRuns(): HasMany
    {
        return $this->hasMany(WorkflowRun::class);
    }

    /**
     * Every field/question discovered on this Application across all of
     * its AgentRuns.
     *
     * @return HasMany<ApplicationQuestion, $this>
     */
    public function applicationQuestions(): HasMany
    {
        return $this->hasMany(ApplicationQuestion::class);
    }
}
