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
