<?php

namespace App\Support\JobFreshness\Pilot;

use RuntimeException;

/** The pilot declined to start (before any request): an unapproved or altered manifest, a changed source record, an unavailable mode. */
final class PilotRefused extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
