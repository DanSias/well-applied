<?php

namespace App\Support\ApplicationPreparation;

use RuntimeException;

/**
 * A preparation the rules refuse (no usable resume, an unsupported application URL, a snapshot that would overwrite
 * different bytes). The message is safe to show; `reason` is a stable machine-readable code.
 */
final class PreparationException extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }
}
