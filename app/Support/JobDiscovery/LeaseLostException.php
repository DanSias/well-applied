<?php

namespace App\Support\JobDiscovery;

use RuntimeException;

/**
 * Thrown at an ownership checkpoint when the execution no longer holds its provider lease (it expired, or another
 * execution took it). It is deliberately its own type: a lost lease is not a provider failure, and it must stop the
 * execution rather than be retried or swallowed as one.
 */
final class LeaseLostException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The provider lease was lost.');
    }
}
