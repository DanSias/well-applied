<?php

namespace App\Support\ResumeDocument;

use App\Models\JobPosting;
use App\Models\ResumeVariant;
use Illuminate\Support\Str;

/**
 * A deterministic, filesystem/download-safe PDF filename for one ResumeVariant, built entirely from data already on the
 * resume pipeline, never a random/opaque id, so two exports of the same variant always produce the same filename.
 *
 * A resume written for a job is named First-Last-Resume-Company-Job-Title.pdf, from the job it targets (reached through
 * the job analysis, or through the job match for older variants). A reference resume has no job, so it keeps its own
 * clearly separate name, built from the profile it was composed from.
 */
final class ResumePdfFilename
{
    /** The longest the part after "First-Last-Resume-" may be, so a long job title cannot produce an unwieldy filename. */
    private const MAX_TARGET_LENGTH = 70;

    public static function build(ResumeVariant $variant): string
    {
        $variant->loadMissing(['careerProfile', 'jobAnalysis.jobPosting', 'jobMatch.jobAnalysis.jobPosting', 'synthesis.resumeProfile']);

        $candidate = self::sanitize($variant->careerProfile->name);
        $posting = $variant->jobAnalysis->jobPosting ?? $variant->jobMatch?->jobAnalysis->jobPosting;

        if ($posting instanceof JobPosting) {
            $target = self::limit(self::sanitize($posting->company), self::sanitize(self::withoutParentheses($posting->title)));

            return "{$candidate}-Resume-{$target}.pdf";
        }

        // A reference resume has no target job; it is named for the profile it was composed from.
        $profile = $variant->synthesis === null ? '' : self::sanitize($variant->synthesis->resumeProfile->name);

        return $candidate.'-Resume-'.($profile === '' ? '' : "{$profile}-").'Reference.pdf';
    }

    /**
     * "AI Portfolio Solution Architect (Sr Stf)" is the posting's title; the parenthetical is a level or team tag, not
     * part of how a person would name the file.
     */
    private static function withoutParentheses(string $title): string
    {
        return trim(preg_replace('/\s*[(\[][^)\]]*[)\]]/', '', $title) ?? $title);
    }

    /**
     * Company and title joined, shortened at a word boundary when over the limit. The company is kept whole when it can
     * be; the title gives way first.
     */
    private static function limit(string $company, string $title): string
    {
        $joined = trim("{$company}-{$title}", '-');

        if (strlen($joined) <= self::MAX_TARGET_LENGTH) {
            return $joined;
        }

        $cut = substr($joined, 0, self::MAX_TARGET_LENGTH);
        $boundary = strrpos($cut, '-');

        return trim($boundary === false ? $cut : substr($cut, 0, $boundary), '-');
    }

    /**
     * Letters, digits, and single hyphens only: accents are transliterated, any run of other characters (spaces,
     * punctuation) becomes one hyphen, and leading/trailing hyphens are trimmed, so the result is safe as both a
     * filesystem path segment and a Content-Disposition filename with no quoting concerns.
     */
    private static function sanitize(string $value): string
    {
        $slug = preg_replace('/[^A-Za-z0-9]+/', '-', Str::ascii($value)) ?? '';

        return trim($slug, '-');
    }
}
