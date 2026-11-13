<?php

namespace App\Support\Navigation;

use App\Enums\JobDisposition;
use App\Models\JobPosting;

/**
 * Where "back to this job" and "open this job" lead by default: a pursued job's home is its Opportunity workspace; any
 * other job's is its job page. One rule, so no page repeats the disposition check.
 *
 * Only defaults go through here. A person who deliberately opens /jobs/{id} stays there (nothing redirects a pursued
 * job away from it), and an action that names a destination with `return_to` (see ReturnTo) keeps that choice.
 */
final class JobHome
{
    public static function url(JobPosting $job): string
    {
        return $job->disposition === JobDisposition::Pursued
            ? route('opportunities.show', $job)
            : route('jobs.show', $job);
    }
}
