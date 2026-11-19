<?php

namespace App\Support\ApplicationInspection;

use RuntimeException;

/** A change to where an application is inspected was asked for while an inspection of it is queued or running. */
final class InspectionAlreadyRunning extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('An inspection of this application is already running. Wait for it to finish, then try again.');
    }
}
