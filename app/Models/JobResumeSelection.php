<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Which tailored resume of a job the user is working on, and which (if any) they marked final. A ResumeVariant is
 * immutable, so this lives apart from it, and apart from the posting, which holds no resume-generation state.
 *
 * @property int $id
 * @property int $job_posting_id
 * @property int|null $current_resume_variant_id
 * @property int|null $preferred_resume_variant_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['job_posting_id', 'current_resume_variant_id', 'preferred_resume_variant_id'])]
class JobResumeSelection extends Model {}
