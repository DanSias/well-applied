<?php

namespace App\Support\GenerationAttempt;

/**
 * Provider-neutral token usage: input, output, and (when the provider
 * reports it) reasoning tokens. Each provider client normalizes its own
 * wire format into this shape at the edge, so generic services and the
 * GenerationAttempt row never see a provider-specific field name.
 */
final class TokenUsage
{
    /**
     * Normalizes an OpenAI-compatible Chat Completions `usage` object
     * (what Ollama's compatibility endpoint returns). Null when the
     * provider reported nothing; reasoning tokens stay null when not
     * reported (never guessed).
     *
     * @param  array<string, mixed>|null  $usage
     * @return array{input_tokens: int|null, output_tokens: int|null, reasoning_tokens: int|null}|null
     */
    public static function fromChatCompletions(?array $usage): ?array
    {
        if ($usage === null) {
            return null;
        }

        $details = is_array($usage['completion_tokens_details'] ?? null) ? $usage['completion_tokens_details'] : [];

        return [
            'input_tokens' => self::int($usage['prompt_tokens'] ?? null),
            'output_tokens' => self::int($usage['completion_tokens'] ?? null),
            'reasoning_tokens' => self::int($details['reasoning_tokens'] ?? null),
        ];
    }

    private static function int(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : null;
    }
}
