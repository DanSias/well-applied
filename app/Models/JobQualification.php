<?php

namespace App\Models;

use App\Enums\QualificationEligibility;
use App\Enums\QualificationVerdict;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One screening assessment of a job against a career profile: the deterministic eligibility outcome, and (when eligibility
 * allowed it) a bounded model assessment checked against the qualification policy. Immutable once written; see the
 * migration for why freshness is a fingerprint comparison.
 *
 * @property int $id
 * @property int $job_posting_id
 * @property int $career_profile_id
 * @property string $content_fingerprint
 * @property string $profile_fingerprint
 * @property string $policy_version
 * @property string|null $prompt_version
 * @property string|null $schema_version
 * @property string|null $generated_by
 * @property QualificationEligibility $eligibility
 * @property list<array<string, mixed>> $eligibility_reasons
 * @property QualificationVerdict|null $verdict
 * @property QualificationVerdict|null $model_verdict
 * @property array<string, mixed>|null $assessment
 * @property list<string>|null $adjustments
 * @property CarbonImmutable $generated_at
 */
#[Fillable([
    'job_posting_id', 'career_profile_id', 'content_fingerprint', 'profile_fingerprint', 'policy_version', 'prompt_version',
    'schema_version', 'generated_by', 'eligibility', 'eligibility_reasons', 'verdict', 'model_verdict', 'assessment',
    'adjustments', 'generated_at',
])]
class JobQualification extends Model
{
    protected function casts(): array
    {
        return [
            'eligibility' => QualificationEligibility::class,
            'eligibility_reasons' => 'array',
            'verdict' => QualificationVerdict::class,
            'model_verdict' => QualificationVerdict::class,
            'assessment' => 'array',
            'adjustments' => 'array',
            'generated_at' => 'datetime',
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
}
