<?php

namespace App\Support\ReadinessReview;

use App\Models\JobAnalysis;
use App\Models\JobPosting;

/**
 * What a readiness review was made from. A review whose job description, analysis or review prompt no longer matches is
 * out of date; one whose rendered document no longer matches is too (the variant itself cannot change).
 */
final class ReadinessFingerprints
{
    public function description(JobPosting $posting): string
    {
        return hash('sha256', (string) $posting->description);
    }

    public function analysis(JobAnalysis $analysis): string
    {
        $statements = $analysis->findings()->orderBy('id')->pluck('statement')->implode('|');

        return hash('sha256', "{$analysis->id}|{$analysis->prompt_version}|{$analysis->role_summary}|{$statements}");
    }
}
