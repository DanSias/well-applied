<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A posting the user removed from a profile's market sample. Matched by content hash, by
 * provider posting id, or by normalized company and title, so a rebuild (even a reposting)
 * does not bring it back. Never touches job_postings or applications.
 *
 * @property int $id
 * @property int $resume_profile_id
 * @property string $content_hash
 * @property string $provider
 * @property string|null $source_job_id
 * @property string $company_key
 * @property string $title_key
 * @property string $company
 * @property string $title
 * @property string|null $reason
 */
#[Fillable(['resume_profile_id', 'content_hash', 'provider', 'source_job_id', 'company_key', 'title_key', 'company', 'title', 'reason'])]
class MarketSampleExclusion extends Model {}
