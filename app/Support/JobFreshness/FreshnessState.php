<?php

namespace App\Support\JobFreshness;

/** What is known about whether a job is still open. Only `expired` and `closed` make a job non-actionable. */
enum FreshnessState: string
{
    case Active = 'active';
    case Expired = 'expired';
    case Closed = 'closed';
    case Uncertain = 'uncertain';
    case Unchecked = 'unchecked';

    public function actionable(): bool
    {
        return ! in_array($this, [self::Expired, self::Closed], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Confirmed open',
            self::Expired => 'Expired',
            self::Closed => 'Closed',
            self::Uncertain => 'May be closed',
            self::Unchecked => 'Not live-checked',
        };
    }
}
