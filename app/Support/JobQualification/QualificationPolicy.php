<?php

namespace App\Support\JobQualification;

/**
 * The versioned constants of the Balanced qualification policy. Bump VERSION whenever the rules in AssessEligibility,
 * ReconcileVerdict or the prompt change what a given job would be told: stored assessments carry the version they were made
 * under, so an older one is recognised as out of date rather than silently trusted.
 */
final class QualificationPolicy
{
    public const VERSION = 'balanced-1';

    /** Below this many characters a description is too thin to judge a role from, whatever its completeness tag says. */
    public const MIN_DESCRIPTION_CHARS = 400;

    /** The state the candidate works from. A remote role is eligible from here; an on-site one elsewhere is not. */
    public const HOME_STATE = 'Florida';

    /** Most skills offered to the model: the ones with the most evidence behind them. */
    public const MAX_CAPABILITY_SKILLS = 80;
}
