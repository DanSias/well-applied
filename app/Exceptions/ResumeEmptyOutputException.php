<?php

namespace App\Exceptions;

use App\Support\ProviderDiagnostics;
use Throwable;

/**
 * The provider answered but produced no final content (for example a
 * reasoning-only response). Retryable without any corrective feedback,
 * unlike a validation failure. Reasoning is never used as content; only
 * whether a reasoning field was present and its size are kept.
 */
final class ResumeEmptyOutputException extends ResumeGenerationProviderException
{
    public function __construct(
        string $message,
        ?ProviderDiagnostics $diagnostics = null,
        ?Throwable $previous = null,
        public readonly bool $reasoningPresent = false,
        public readonly int $reasoningChars = 0,
    ) {
        parent::__construct($message, $diagnostics, $previous);
    }
}
