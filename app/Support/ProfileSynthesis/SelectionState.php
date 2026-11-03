<?php

namespace App\Support\ProfileSynthesis;

/**
 * What one role's selection has chosen so far; shared by the closures that score, test and take a bullet.
 */
final class SelectionState
{
    /** @var array<int, array<string, mixed>> */
    public array $chosen = [];

    /** @var array<string, int> */
    public array $facetCount = [];

    /** @var array<string, bool> */
    public array $projectFacet = [];

    /** @var array<int, int> */
    public array $perProject = [];
}
