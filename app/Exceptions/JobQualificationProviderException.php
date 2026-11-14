<?php

namespace App\Exceptions;

use App\Support\ProviderDiagnostics;
use RuntimeException;
use Throwable;

/** The qualification model could not be reached or did not complete (transport, timeout, truncation, empty output). */
class JobQualificationProviderException extends RuntimeException
{
    public function __construct(string $message, public readonly ?ProviderDiagnostics $diagnostics = null, ?Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }
}
