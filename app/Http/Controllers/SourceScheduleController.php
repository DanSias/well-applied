<?php

namespace App\Http\Controllers;

use App\Enums\JobDiscoverySource;
use App\Enums\ScheduleOverride;
use App\Models\DiscoveryProviderSetting;
use App\Support\JobDiscovery\DiscoveryProviderInventory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Pause, resume or enable the automatic scheduling of one discovery provider. It stores the override and nothing else:
 * what the override means (and that it never makes an unconfigured provider run) is DiscoveryProviderInventory's.
 *
 * It only changes what the scheduler may select next. A run already in progress is not touched, and a manual run still
 * runs the provider whatever its schedule says.
 */
class SourceScheduleController extends Controller
{
    public function update(Request $request, string $provider, DiscoveryProviderInventory $inventory): RedirectResponse
    {
        $source = JobDiscoverySource::tryFrom($provider);
        abort_if($source === null || $source === JobDiscoverySource::Manual, 404);

        $validated = $request->validate([
            'schedule_override' => ['required', 'string', Rule::enum(ScheduleOverride::class)],
        ]);

        $described = $inventory->describe($source);

        // An override never bypasses configuration or registration: there is nothing to schedule until it is set up.
        if ($described['effective_schedule'] === DiscoveryProviderInventory::SCHEDULE_UNAVAILABLE) {
            throw ValidationException::withMessages([
                'schedule_override' => "{$described['label']} needs setup before it can be scheduled. {$described['unavailable_reason']}",
            ]);
        }

        DiscoveryProviderSetting::query()->updateOrCreate(
            ['provider' => $source],
            ['schedule_override' => ScheduleOverride::from($validated['schedule_override'])],
        );

        Inertia::flash('schedule_result', [
            'provider' => $described['label'],
            'schedule' => $inventory->describe($source)['effective_schedule'],
        ]);

        // Back to the same page, so an open drawer, its history page and the activity page are all kept.
        return back();
    }
}
