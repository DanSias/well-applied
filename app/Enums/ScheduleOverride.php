<?php

namespace App\Enums;

/**
 * A person's override of whether the scheduler runs a discovery provider. `Default` (also what a missing row means)
 * leaves the code-defined default in charge. An override never makes an unconfigured or unregistered provider
 * runnable: DiscoveryProviderInventory applies it only to a provider that is available at all.
 */
enum ScheduleOverride: string
{
    case Default = 'default';
    case Paused = 'paused';
    case Enabled = 'enabled';
}
