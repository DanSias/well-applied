<?php

namespace App\Enums;

/**
 * The recommendation a qualification gives, best first. It is a screening opinion about whether a job is worth the
 * expensive steps (full analysis, matching), never a decision: nothing here changes a job's disposition.
 */
enum QualificationVerdict: string
{
    case Strong = 'strong';
    case Good = 'good';
    case NeedsReview = 'needs_review';
    case Poor = 'poor';

    public function label(): string
    {
        return match ($this) {
            self::Strong => 'Strong match',
            self::Good => 'Good match',
            self::NeedsReview => 'Needs review',
            self::Poor => 'Poor match',
        };
    }

    /** Higher is better; used to cap or floor a verdict. */
    public function rank(): int
    {
        return match ($this) {
            self::Poor => 0,
            self::NeedsReview => 1,
            self::Good => 2,
            self::Strong => 3,
        };
    }

    public static function fromRank(int $rank): self
    {
        return match (true) {
            $rank <= 0 => self::Poor,
            $rank === 1 => self::NeedsReview,
            $rank === 2 => self::Good,
            default => self::Strong,
        };
    }
}
