<?php

namespace App\Support\JobDiscovery\JobicyCommercial;

/** Wilson score interval for a proportion: a share from a small sample is never reported without its uncertainty. */
final class Wilson
{
    /**
     * @return array{low: float, high: float}|null null when n is 0
     */
    public static function interval(int $successes, int $n, float $z = 1.96): ?array
    {
        if ($n <= 0) {
            return null;
        }
        $p = $successes / $n;
        $denominator = 1 + $z * $z / $n;
        $centre = ($p + $z * $z / (2 * $n)) / $denominator;
        $margin = $z * sqrt($p * (1 - $p) / $n + $z * $z / (4 * $n * $n)) / $denominator;

        return ['low' => round(max(0, $centre - $margin), 4), 'high' => round(min(1, $centre + $margin), 4)];
    }
}
