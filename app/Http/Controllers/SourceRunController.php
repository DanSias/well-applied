<?php

namespace App\Http\Controllers;

use App\Enums\JobDiscoverySource;
use App\Support\JobDiscovery\DiscoveryProviderInventory;
use App\Support\JobDiscovery\RequestProviderRun;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Run Now for one provider. It only records the request and queues it (RequestProviderRun) and returns at once; the work
 * is a queued job's, and its outcome shows on the page as the attempt settles. Independent of the provider's schedule.
 */
class SourceRunController extends Controller
{
    public function store(string $provider, RequestProviderRun $requests, DiscoveryProviderInventory $inventory): RedirectResponse
    {
        $source = JobDiscoverySource::tryFrom($provider);
        abort_if($source === null || $source === JobDiscoverySource::Manual, 404);

        $outcome = $requests->request($source);
        $described = $inventory->describe($source);

        if ($outcome === RequestProviderRun::UNAVAILABLE) {
            throw ValidationException::withMessages([
                'run' => "{$described['label']} needs setup before it can run. {$described['unavailable_reason']}",
            ]);
        }

        Inertia::flash('run_result', ['provider' => $described['label'], 'outcome' => $outcome]);

        // Back to the same page, so the open drawer and both page numbers stay.
        return back();
    }
}
