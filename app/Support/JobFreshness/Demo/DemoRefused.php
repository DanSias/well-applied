<?php

namespace App\Support\JobFreshness\Demo;

use RuntimeException;

final class DemoRefused extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
