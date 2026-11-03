<?php

namespace App\Support;

use Throwable;

/**
 * The model answered normally (HTTP 200, not truncated) but produced
 * no final message content. Distinct from a transport failure, a
 * truncation and malformed JSON because the right response differs: the
 * call can simply be repeated, since there is nothing to correct.
 * Records only whether a reasoning field was present and how long it
 * was, never its text; reasoning is never treated as final content.
 */
final class OllamaEmptyOutputException extends OllamaChatCompletionsException
{
    /**
     * @param  array<string, mixed>|null  $usage
     */
    public function __construct(
        string $message,
        ?Throwable $previous = null,
        ?string $model = null,
        ?string $finishReason = null,
        ?array $usage = null,
        public readonly bool $reasoningPresent = false,
        public readonly int $reasoningChars = 0,
    ) {
        parent::__construct($message, $previous, $model, $finishReason, $usage);
    }

    public function withDiagnostics(?string $model, ?string $finishReason, ?array $usage): self
    {
        return new self($this->getMessage(), $this, $model, $finishReason, $usage, $this->reasoningPresent, $this->reasoningChars);
    }
}
