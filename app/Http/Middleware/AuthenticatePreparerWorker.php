<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the application-preparer routes only (routes/api.php, /api/worker/preparer): a shared-secret Bearer token that
 * is separate from the browser inspector's, because this worker receives applicant data and a resume. The inspector's
 * token is rejected here, and this token is rejected by AuthenticateBrowserWorker.
 *
 * Fails closed: an unset token rejects every request, and so does a preparer token identical to the inspector's (one
 * secret must never open both doors).
 */
class AuthenticatePreparerWorker
{
    public function handle(Request $request, Closure $next): Response
    {
        $configured = (string) config('services.preparer_worker.token');
        if ($configured === '') {
            abort(401, 'Preparer worker authentication is not configured.');
        }

        $inspector = (string) config('services.browser_worker.token');
        if ($inspector !== '' && hash_equals($inspector, $configured)) {
            abort(401, 'The preparer token must differ from the inspector token.');
        }

        $provided = (string) $request->bearerToken();
        if ($provided === '' || ! hash_equals($configured, $provided)) {
            abort(401, 'Invalid worker credentials.');
        }

        return $next($request);
    }
}
