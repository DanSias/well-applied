<?php

namespace App\Support\JobQualification;

use App\Models\JobPosting;

/**
 * A hash of exactly the posting content a qualification depends on. Compensation, source URL and the discovery timestamps
 * are left out on purpose: rediscovering an unchanged posting rewrites them every run, and that must not invalidate a
 * stored assessment. Whitespace and case are normalised, so a reflowed but identical description is the same content.
 */
final class JobContentFingerprint
{
    public static function for(JobPosting $job): string
    {
        $normalize = fn (?string $text): string => mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $text)));

        return hash('sha256', json_encode([
            'title' => $normalize($job->title),
            'company' => $normalize($job->company),
            'location' => $normalize($job->location),
            'remote_status' => $job->remote_status?->value,
            'employment_type' => $normalize($job->employment_type),
            'description' => $normalize($job->description),
        ], JSON_THROW_ON_ERROR));
    }
}
