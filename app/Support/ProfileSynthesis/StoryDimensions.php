<?php

namespace App\Support\ProfileSynthesis;

/**
 * The dimensions of engineering work a statement demonstrates, read from its own words: how it was designed, what was
 * built, what it was integrated with, where it was delivered and used, who it was built with, and what it achieved. A
 * resume that shows all of them reads as an engineer who designs, builds, ships and works with people; one that shows one
 * of them forty times reads as a list. Deterministic and conservative: a dimension is named only when the statement says it.
 */
final class StoryDimensions
{
    public const ALL = ['architecture', 'implementation', 'integration', 'delivery', 'collaboration', 'outcome'];

    private const PATTERNS = [
        'architecture' => '/\b(?:architect\w*|component boundaries|data model|deterministic|designed|design decisions?|single source of truth|provider[- ]abstracted|local[- ]first)\b/i',
        'implementation' => '/\b(?:built|developed|implemented|created|engineered|wrote)\b/i',
        'integration' => '/\b(?:APIs?|integrat\w+|webhooks?|Jira|GitLab|Salesforce|BigQuery|data warehouse|pipelines?|SDKs?)\b/i',
        'collaboration' => '/\b(?:partner\w*|collaborat\w*|stakeholders?|cross-functional|worked with|guid\w+|met with|coordinat\w+)\b/i',
    ];

    /**
     * The kinds of responsibility a long role has to show beyond its single strongest result (see ReferenceSelection's role
     * coverage): what was built, whether the person owned the work across its lifecycle, whether they worked directly with
     * the people it was for, and whether they carried the business and delivery responsibility of working independently.
     * Separate from `of()`, which describes one project's engineering story and must not change with it.
     */
    public const RESPONSIBILITIES = ['build', 'ownership', 'client', 'business'];

    private const RESPONSIBILITY_PATTERNS = [
        // A lifecycle claim: managed or owned a project or engagement, or carried requirements through to delivery or support.
        'ownership' => [
            '/\b(?:managed|managing|owned|oversaw)\b[^.;]{0,60}\b(?:projects?|engagements?|delivery|implementations?|launch(?:es)?)\b/i',
            '/\b(?:requirements?|discovery|solution design)\b[^.;]{0,100}\b(?:launch|delivery|deployment|production|ongoing support)\b/i',
            '/\bend[- ]to[- ]end\b/i',
        ],
        // Working with the people the work was for: a collaboration verb and a client or stakeholder, or their needs as the object.
        'client' => [
            '/\b(?:worked|collaborat\w+|partner\w*|coordinat\w+|communicat\w+|consult(?:ed|ing)|translat\w+|met with|meeting)\b[^.;]{0,70}\b(?:clients?|stakeholders?|customers?)\b/i',
            '/\b(?:clients?|stakeholders?)[\x{2019}\']s?\s+(?:requirements|priorities|needs|objectives)\b/iu',
        ],
        // Responsibility for the business side of the work: founding or running it independently, balancing the demands of it.
        'business' => [
            '/\b(?:found(?:ed|er)|independent(?:ly)? (?:founder|developer|consultant)|owner-operator|balanc(?:ed|ing)|commercial objectives|delivery responsibilit\w+)\b/i',
        ],
    ];

    /**
     * Which responsibilities a statement states, read from its own words. Deterministic and conservative, like `of()`: a
     * responsibility is named only when the statement says it, so a long role is never credited with one it did not claim.
     *
     * @return array<int, string> a subset of RESPONSIBILITIES, in that order
     */
    public static function responsibilities(string $statement): array
    {
        $found = array_intersect(['implementation', 'integration'], self::of($statement)) !== [] ? ['build'] : [];

        foreach (self::RESPONSIBILITY_PATTERNS as $responsibility => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $statement) === 1) {
                    $found[] = $responsibility;

                    break;
                }
            }
        }

        return array_values(array_intersect(self::RESPONSIBILITIES, $found));
    }

    /**
     * @return array<int, string>
     */
    public static function of(string $statement): array
    {
        $found = [];

        foreach (self::PATTERNS as $dimension => $pattern) {
            if (preg_match($pattern, $statement) === 1) {
                $found[] = $dimension;
            }
        }

        if (StoryRole::isDeployment($statement) || preg_match('/\b(?:deployed|delivered|rolled out|in production)\b/i', $statement) === 1) {
            $found[] = 'delivery';
        }

        if (StoryRole::isOutcome($statement)) {
            $found[] = 'outcome';
        }

        return array_values(array_intersect(self::ALL, $found));
    }
}
