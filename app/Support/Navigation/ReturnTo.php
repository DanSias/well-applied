<?php

namespace App\Support\Navigation;

use App\Models\JobPosting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Where an action that used to redirect to the job's page may send the person instead. A request names a destination
 * with `return_to`, and only the fixed keys below are honoured: never a URL, so there is nothing to forge and no open
 * redirect. Every destination is a page of the same job, so a request can only ever return to that job, and an absent
 * (or unknown) value means the action keeps its own default.
 *
 *  - `opportunity`: the Opportunity workspace. For a job that is no longer pursued it redirects on to the job page, so
 *    it can never strand someone on a page that no longer applies.
 *  - `job`: the job page.
 */
final class ReturnTo
{
    public const OPPORTUNITY = 'opportunity';

    public const JOB = 'job';

    public const KEYS = [self::OPPORTUNITY, self::JOB];

    /** Validation rule for the `return_to` input. */
    public const RULE = 'in:opportunity,job';

    public static function redirect(Request $request, JobPosting $job): ?RedirectResponse
    {
        return match ($request->input('return_to')) {
            self::OPPORTUNITY => redirect()->route('opportunities.show', $job),
            self::JOB => redirect()->route('jobs.show', $job),
            default => null,
        };
    }
}
