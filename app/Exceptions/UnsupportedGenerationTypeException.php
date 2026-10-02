<?php

namespace App\Exceptions;

use App\Enums\GenerationType;
use RuntimeException;
use Throwable;

/**
 * Thrown when a generation-claim caller asks for a generation type this
 * app does not support claiming for yet — today ResumeVariant, whose
 * store() flow still does its own inline check-then-create (see
 * app/Http/Controllers/ResumeVariantController.php). Failing
 * explicitly here — rather than a silent null or a catch-all
 * RuntimeException — is deliberate: an unsupported type means a
 * caller bug (a new store() path that forgot to wire up claim
 * support), which should be loud the first time it happens, not
 * disguised as "no attempt claimed".
 */
class UnsupportedGenerationTypeException extends RuntimeException
{
    public function __construct(GenerationType $type, ?Throwable $previous = null)
    {
        parent::__construct(
            "Generation type [{$type->value}] is not supported by QueueGenerationAttempt.",
            previous: $previous,
        );
    }
}
