<?php

namespace App\Support\Clarification;

/**
 * A ranked, not-yet-persisted question.
 */
final readonly class ClarificationCandidate
{
    /**
     * @param  array<int, int>  $relatedFindingIds
     * @param  array<string, float|string>  $scoreBreakdown
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public string $topicKey,
        public string $topicLabel,
        public int $primaryFindingId,
        public array $relatedFindingIds,
        public string $questionText,
        public float $score,
        public array $scoreBreakdown,
        public array $context,
        public ?string $suggestedAttributableType,
        public ?int $suggestedAttributableId,
        public string $suggestedVisibility,
    ) {}
}
