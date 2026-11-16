<?php

namespace App\Support\JobDiscovery;

use RuntimeException;

/** Thrown at a checkpoint when a queued run has used its whole time budget (see config `execution.max_queued_run_seconds`). */
final class ExecutionDeadlineException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The run reached its time limit.');
    }
}
