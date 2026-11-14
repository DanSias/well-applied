<?php

namespace App\Support\JobQualification;

/**
 * The versioned constants of the Balanced qualification policy. Bump VERSION whenever the rules in AssessEligibility,
 * ReconcileVerdict or the prompt change what a given job would be told: stored assessments carry the version they were made
 * under, so an older one is recognised as out of date rather than silently trusted.
 */
final class QualificationPolicy
{
    public const VERSION = 'balanced-2';

    /** Below this many characters a description is too thin to judge a role from, whatever its completeness tag says. */
    public const MIN_DESCRIPTION_CHARS = 400;

    /** The state the candidate works from. A remote role is eligible from here; an on-site one elsewhere is not. */
    public const HOME_STATE = 'Florida';

    /**
     * What a posting sentence must say for a claim that the role is people management to stand. Deliberately narrow and used
     * only to CONFIRM a claim the model already made with a cited sentence, never to infer management from keywords: mentoring,
     * "lead" and seniority titles are not in it, because they describe hands-on engineers as often as managers.
     */
    public const PEOPLE_MANAGEMENT_EVIDENCE = [
        '/\bdirect\s+reports?\b/i',
        '/\b(?:people|line)\s+manage(?:r|ment)\b/i',
        '/\bengineering\s+manager\b/i',
        '/\bperformance\s+(?:reviews?|evaluations?|management)\b/i',
        '/\b(?:build|building|grow|growing|lead|leading|manage|managing|hire|hiring|recruit|recruiting|run|running)\s+(?:and\s+\w+\s+)?(?:a\s+|an\s+|the\s+|your\s+)?(?:\w+\s+)?(?:team|org|organi[sz]ation)\s+of\b/i',
        '/\b(?:build|building|grow|growing|hire|hiring|manage|managing)\s+(?:and\s+\w+\s+)?(?:a\s+|an\s+|the\s+|your\s+)?(?:\w+\s+)?team\b/i',
        '/\b(?:lead|leading)\s+and\s+(?:grow|develop|manage)\b/i',
        '/\bdevelop(?:ing)?\s+(?:talent|colleagues|your\s+team|the\s+people)\b/i',
        '/\b(?:headcount|staffing|one-on-ones?|1:1s?)\b/i',
        '/\b(?:personnel|people|team)\s+(?:budget|ownership)\b/i',
    ];

    /** Most skills offered to the model: the ones with the most evidence behind them. */
    public const MAX_CAPABILITY_SKILLS = 80;
}
