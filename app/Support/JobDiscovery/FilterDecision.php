<?php

namespace App\Support\JobDiscovery;

use App\Enums\DiscoveryRejectionReason;

/**
 * The outcome of the global discovery filter for one candidate: accepted, or rejected for exactly one primary reason.
 * Evidence is the short text the decision rested on (the title, the location), never a description.
 */
final readonly class FilterDecision
{
    public const EVIDENCE_LIMIT = 240;

    private function __construct(
        public bool $accepted,
        public ?DiscoveryRejectionReason $reason = null,
        public ?string $matchedTerm = null,
        public ?string $matchedField = null,
        public ?string $evidence = null,
    ) {}

    public static function accept(): self
    {
        return new self(true);
    }

    public static function reject(DiscoveryRejectionReason $reason, ?string $matchedTerm, string $matchedField, ?string $evidence): self
    {
        return new self(
            false,
            $reason,
            $matchedTerm === null ? null : mb_substr(trim($matchedTerm), 0, 100),
            $matchedField,
            $evidence === null ? null : mb_substr(trim($evidence), 0, self::EVIDENCE_LIMIT),
        );
    }
}
