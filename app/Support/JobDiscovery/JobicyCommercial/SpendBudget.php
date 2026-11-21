<?php

namespace App\Support\JobDiscovery\JobicyCommercial;

use InvalidArgumentException;

/**
 * A strict request and spending budget, enforced BEFORE a paid request is made. A commercial page can charge at most one cent per job it returns, so the worst
 * case of a request is `count` cents: it is refused unless that worst case still fits the remaining budget. The hard ceilings are in code (raising one is a reviewed
 * change, not a flag), and the wallet balance stays the last line of defence, not the first.
 */
final class SpendBudget
{
    public const MAX_COST_CENTS_CEILING = 200;

    public const MAX_REQUESTS_CEILING = 6;

    public const CENTS_PER_DIRECT_JOB = 1;

    private int $requests = 0;

    private int $reportedCents = 0;

    private int $reservedCents = 0;

    public function __construct(public readonly int $maxCostCents, public readonly int $maxRequests)
    {
        if ($maxCostCents < 1 || $maxCostCents > self::MAX_COST_CENTS_CEILING) {
            throw new InvalidArgumentException('The spending budget must be 1 to '.self::MAX_COST_CENTS_CEILING.' cents.');
        }
        if ($maxRequests < 1 || $maxRequests > self::MAX_REQUESTS_CEILING) {
            throw new InvalidArgumentException('The request budget must be 1 to '.self::MAX_REQUESTS_CEILING.' requests.');
        }
    }

    public function requestsUsed(): int
    {
        return $this->requests;
    }

    public function reportedCents(): int
    {
        return $this->reportedCents;
    }

    public function canRequest(): bool
    {
        return $this->requests < $this->maxRequests;
    }

    /** True only if the WORST CASE of a commercial page of `$count` jobs still fits what has not been reserved or reported. */
    public function canAffordCommercial(int $count): bool
    {
        return $this->canRequest() && $this->reportedCents + $this->reservedCents + $count * self::CENTS_PER_DIRECT_JOB <= $this->maxCostCents;
    }

    public function recordRequest(): void
    {
        $this->requests++;
    }

    /** Called when a commercial request is about to be sent. */
    public function reserve(int $count): void
    {
        $this->reservedCents += $count * self::CENTS_PER_DIRECT_JOB;
    }

    /** What the API said it charged (USD). Replaces the reservation. */
    public function recordCharge(float $usd): void
    {
        $this->reservedCents = 0;
        $this->reportedCents += (int) round($usd * 100);
    }

    public function exceeded(): bool
    {
        return $this->reportedCents > $this->maxCostCents;
    }
}
