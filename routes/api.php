<?php

use App\Http\Controllers\Worker\AgentRunClaimController;
use App\Http\Controllers\Worker\AgentRunResultController;
use App\Http\Controllers\Worker\PreparerClaimController;
use App\Http\Controllers\Worker\PreparerRunController;
use App\Http\Middleware\AuthenticateBrowserWorker;
use App\Http\Middleware\AuthenticatePreparerWorker;
use Illuminate\Support\Facades\Route;

// The browser worker's entire protocol surface — machine-only, never
// called from the frontend. See docs/application-inspector.md
// "Approved future worker transport" and
// App\Http\Middleware\AuthenticateBrowserWorker.
Route::middleware(AuthenticateBrowserWorker::class)->prefix('worker')->group(function () {
    Route::post('/agent-runs/claim', [AgentRunClaimController::class, 'store'])
        ->name('worker.agent-runs.claim');

    Route::post('/agent-runs/{agentRun}/result', [AgentRunResultController::class, 'store'])
        ->name('worker.agent-runs.result');
});

// The application-preparer's protocol surface: a different trust class from the inspector above (it receives applicant
// data and a resume), so a different token, a different route group and run-scoped access. Machine-only. See
// docs/application-preparation.md.
Route::middleware(AuthenticatePreparerWorker::class)->prefix('worker/preparer')->group(function () {
    Route::post('/agent-runs/claim', [PreparerClaimController::class, 'store'])
        ->name('worker.preparer.claim');

    Route::get('/agent-runs/{agentRun}/input', [PreparerRunController::class, 'input'])
        ->whereNumber('agentRun')->name('worker.preparer.input');
    Route::get('/agent-runs/{agentRun}/resume', [PreparerRunController::class, 'resume'])
        ->whereNumber('agentRun')->name('worker.preparer.resume');
    Route::post('/agent-runs/{agentRun}/heartbeat', [PreparerRunController::class, 'heartbeat'])
        ->whereNumber('agentRun')->name('worker.preparer.heartbeat');
    Route::put('/agent-runs/{agentRun}/screenshots/{name}', [PreparerRunController::class, 'screenshot'])
        ->whereNumber('agentRun')->where('name', '[a-z][a-z_]{0,19}-\d{2}of\d{2}-y\d{5}\.png')->name('worker.preparer.screenshot');
    Route::post('/agent-runs/{agentRun}/result', [PreparerRunController::class, 'result'])
        ->whereNumber('agentRun')->name('worker.preparer.result');
});
