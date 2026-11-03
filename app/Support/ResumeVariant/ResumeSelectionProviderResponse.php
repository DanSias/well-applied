<?php

namespace App\Support\ResumeVariant;

/**
 * A model provider's raw answer to one Resume Selection request: the
 * decoded structured content (untrusted — not yet validated) plus
 * enough provider/model identity to populate
 * ResumeVariant.selection_generated_by. Mirrors JobMatchProviderResponse.
 */
final readonly class ResumeSelectionProviderResponse
{
    /**
     * @param  array<string, mixed>  $structuredContent
     */
    public function __construct(
        public string $provider,
        public string $model,
        public array $structuredContent,
        /** @var array{input_tokens: int|null, output_tokens: int|null, reasoning_tokens: int|null}|null Provider-neutral usage, when reported. See TokenUsage. */
        public ?array $usage = null,
    ) {}

    public function generatedBy(): string
    {
        return "{$this->provider}:{$this->model}";
    }
}
