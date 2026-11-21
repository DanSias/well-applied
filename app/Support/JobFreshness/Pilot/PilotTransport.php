<?php

namespace App\Support\JobFreshness\Pilot;

interface PilotTransport
{
    /** One GET. It must not follow redirects, retry, or paginate. @throws PilotTransportException */
    public function get(string $url, int $timeoutSeconds): PilotResponse;
}
