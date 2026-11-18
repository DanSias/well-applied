<?php

namespace App\Http\Controllers;

use App\Support\ApplicationInspection\PresentWorkerAvailability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A small, read-only status feed for the nav-bar worker badge and
 * anywhere else "is inspection capacity available" needs answering
 * (e.g. an Application inspection page showing "waiting for browser
 * worker" while queued). Well Applied only ever OBSERVES the
 * worker here — no start/stop/restart action exists, and none is
 * planned; see docs/application-inspector.md "Worker presence".
 */
class BrowserWorkerStatusController extends Controller
{
    /**
     * `?worker_type=application_preparer` asks about the preparation worker instead; anything else is the inspector.
     * Each is its own fact: one being online says nothing about the other.
     */
    public function show(Request $request, PresentWorkerAvailability $presenter): JsonResponse
    {
        $type = $request->query('worker_type') === 'application_preparer' ? 'application_preparer' : 'browser_inspector';

        return response()->json($presenter->present($type));
    }
}
