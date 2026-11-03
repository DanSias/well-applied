<?php

namespace App\Support\Expansion;

use App\Models\CareerFact;
use App\Support\JobMatch\CareerFactAttribution;
use Illuminate\Support\Collection;

/**
 * The shared claim identity. CareerFacts that carry the same `claim_group` are ONE underlying claim
 * attached to several places (projects or roles), not several accomplishments. Wherever evidence is
 * counted or offered to a model, a group is represented once:
 *
 *  - the first fact of the group (in the caller's order) is the representative;
 *  - the others are not offered separately, so a matcher or Selection cannot count the claim more
 *    than once or place the same wording under several projects or roles;
 *  - the representative lists the other places, so the model still knows where it also applies.
 *
 * Facts without a group, and different groups, are untouched: distinct project-specific
 * accomplishments stay independently selectable.
 */
final class SharedClaims
{
    /**
     * @param  Collection<int, CareerFact>  $facts
     * @return array{0: Collection<int, CareerFact>, 1: array<int, array<int, string>>} the facts to offer, and fact id => other places
     */
    public static function collapse(Collection $facts): array
    {
        $seen = [];
        $also = [];
        $kept = [];

        foreach ($facts as $fact) {
            $group = $fact->claim_group;

            if ($group === null) {
                $kept[] = $fact;

                continue;
            }

            if (! isset($seen[$group])) {
                $seen[$group] = $fact->id;
                $kept[] = $fact;

                continue;
            }

            $also[$seen[$group]][] = self::place($fact);
        }

        return [new Collection($kept), $also];
    }

    public static function place(CareerFact $fact): string
    {
        $a = CareerFactAttribution::resolve($fact);

        return match (true) {
            $a->project !== null => $a->project.($a->employer !== null ? " ({$a->employer})" : ''),
            $a->role !== null => $a->role.($a->employer !== null ? " at {$a->employer}" : ''),
            default => (string) $a->employer,
        };
    }
}
