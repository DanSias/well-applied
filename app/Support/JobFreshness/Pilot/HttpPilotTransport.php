<?php

namespace App\Support\JobFreshness\Pilot;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * The real HTTP transport. It exists so the runner's behaviour against every kind of answer can be tested with `Http::fake`. It REFUSES to send unless
 * `job_freshness.pilot.live_enabled` is true, which is a literal `false` in the config file (no environment variable can change it) and which no command ever sets: live mode is
 * unavailable until a separate authorization changes the code. It does not follow redirects, does not retry and sends no credentials.
 */
final class HttpPilotTransport implements PilotTransport
{
    public function get(string $url, int $timeoutSeconds): PilotResponse
    {
        if (config('job_freshness.pilot.live_enabled') !== true) {
            throw new PilotRefused('live_unavailable', 'Live requests are unavailable: the pilot has not been authorized to run live.');
        }

        try {
            $response = Http::withoutRedirecting()->timeout($timeoutSeconds)->connectTimeout($timeoutSeconds)->accept('application/json')->withUserAgent('freshness-pilot')->get($url);
        } catch (ConnectionException $e) {
            throw new PilotTransportException('connection failed', 0, $e);
        }

        $headers = [];
        foreach (['content-type', 'retry-after', 'cache-control', 'age'] as $name) {
            $value = $response->header($name);
            if ($value !== '') {
                $headers[$name] = $value;
            }
        }

        return new PilotResponse($response->status(), $headers, $response->body());
    }
}
