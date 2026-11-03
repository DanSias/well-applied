<?php

namespace App\Models;

use App\Enums\ClarificationSessionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One round of optional clarification for a job analysis. At most one
 * session exists per (job posting, job analysis): once resolved, the
 * pipeline never pauses for that analysis again, so retries and
 * re-matching after approvals cannot loop back into questions.
 *
 * @property int $id
 * @property int|null $career_profile_id Set for a Resume Profile interview, which has no job.
 * @property int|null $resume_profile_id
 * @property int|null $job_posting_id
 * @property int|null $job_analysis_id
 * @property int|null $job_match_id The match the questions were ranked against.
 * @property string|null $pipeline_run_id
 * @property string $mode standard|deep
 * @property ClarificationSessionStatus $status
 * @property Carbon|null $resolved_at
 */
#[Fillable(['career_profile_id', 'resume_profile_id', 'job_posting_id', 'job_analysis_id', 'job_match_id', 'pipeline_run_id', 'mode', 'status', 'resolved_at'])]
class ClarificationSession extends Model
{
    protected function casts(): array
    {
        return [
            'status' => ClarificationSessionStatus::class,
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<JobPosting, $this>
     */
    public function jobPosting(): BelongsTo
    {
        return $this->belongsTo(JobPosting::class);
    }

    /**
     * @return BelongsTo<CareerProfile, $this>
     */
    public function careerProfile(): BelongsTo
    {
        return $this->belongsTo(CareerProfile::class);
    }

    /**
     * @return BelongsTo<ResumeProfile, $this>
     */
    public function resumeProfile(): BelongsTo
    {
        return $this->belongsTo(ResumeProfile::class);
    }

    /**
     * The owning career profile, whether this session is for a job or a Resume Profile interview.
     */
    public function ownerCareerProfile(): CareerProfile
    {
        return $this->careerProfile ?? $this->jobPosting->careerProfile;
    }

    /**
     * @return HasMany<ClarificationQuestion, $this>
     */
    public function questions(): HasMany
    {
        return $this->hasMany(ClarificationQuestion::class)->orderBy('sort_order');
    }

    public function isPending(): bool
    {
        return $this->status === ClarificationSessionStatus::Pending;
    }
}
