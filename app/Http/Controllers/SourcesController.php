<?php

namespace App\Http\Controllers;

use App\Enums\JobDiscoverySource;
use App\Support\Sources\PresentSources;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The read-only Sources page: every discovery provider, what discovery did recently, and (in a drawer, `?provider=`) one
 * provider's detail and history. All of the data comes from PresentSources; nothing here recalculates it.
 *
 * Two independent page numbers: `page` for recent activity and `history_page` for the open provider's history, so
 * paging one never moves the other.
 */
class SourcesController extends Controller
{
    public function index(Request $request, PresentSources $sources): Response
    {
        $provider = JobDiscoverySource::tryFrom((string) $request->query('provider', ''));
        $provider = $provider === JobDiscoverySource::Manual ? null : $provider;
        $activityPage = max(1, (int) $request->query('page', 1));
        $historyPage = max(1, (int) $request->query('history_page', 1));

        // Computed at most once, and only when a request wants them: opening the drawer reloads just `filters` and `selected`.
        $providers = null;
        $loadProviders = function () use (&$providers, $sources): array {
            return $providers ??= $sources->providers();
        };

        return Inertia::render('sources/index', [
            'summary' => fn () => $sources->summary($loadProviders()),
            'providers' => fn () => $loadProviders(),
            'activity' => fn () => $sources->activity($activityPage),
            'filters' => ['provider' => $provider?->value, 'history_page' => $historyPage],
            // The drawer's content, fetched on demand: only when a provider is open.
            'selected' => fn () => $provider === null ? null : $sources->detail($provider, $historyPage),
        ]);
    }
}
