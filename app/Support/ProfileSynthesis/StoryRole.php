<?php

namespace App\Support\ProfileSynthesis;

/**
 * What part of a project's story a fact tells, read from its own statement: that it was deployed to or used by someone,
 * that it reports a measured result, or what was built. Deterministic and conservative: a statement that does not clearly
 * say it was delivered to a team is not called a deployment, and nothing here adds a claim. ReferenceSelection uses it to
 * keep a project's facts in one accomplishment, and the wording stage is told each fact's role so a bullet can say what
 * was built, how, who used it and what it achieved, using only the parts its facts actually state.
 */
final class StoryRole
{
    private const AUDIENCE = 'team|teams|staff|users|managers|analysts|merchants|customers|department|group|stakeholders|organization';

    public const DEPLOYMENT = 'deployment';

    public const OUTCOME = 'outcome';

    public const BUILT = 'built';

    public const IMPLEMENTATION = 'implementation';

    /** Delivered, deployed or rolled out to a named audience ("was delivered and deployed to the Product Management team", "delivered it to the Analytics team"). */
    public static function isDeployment(string $statement): bool
    {
        return preg_match('/\b(?:delivered|deployed|rolled out|released)(?:\s+and\s+(?:delivered|deployed))?(?:\s+[^\s,.;]+){0,5}?\s+(?:to|for)\s+(?:the\s+|a\s+|an\s+|its\s+|our\s+|their\s+)?[^,.;]{0,60}?\b(?:'.self::AUDIENCE.')\b/i', $statement) === 1;
    }

    /** A measured result: a figure with a unit or a verb of change ("reduced reporting time by 85%"). */
    public static function isOutcome(string $statement): bool
    {
        return preg_match('/\d/', $statement) === 1
            && preg_match('/%|\bhours?\b|\bminutes?\b|\bdays?\b|\b(?:reduc|sav|cut|increas|improv|grew|lift|decreas)\w*/i', $statement) === 1;
    }

    /** Opens with a verb for work that was built ("Built Flow, a ..."), the lead of a project's story. */
    public static function isBuild(string $statement): bool
    {
        return preg_match('/^(?:Built|Developed|Created|Designed|Implemented|Engineered|Delivered)\b/', trim($statement)) === 1;
    }

    /**
     * @return 'deployment'|'outcome'|'built'|'implementation'
     */
    public static function of(string $statement, bool $lead): string
    {
        return match (true) {
            self::isDeployment($statement) => self::DEPLOYMENT,
            self::isOutcome($statement) => self::OUTCOME,
            $lead => self::BUILT,
            default => self::IMPLEMENTATION,
        };
    }
}
