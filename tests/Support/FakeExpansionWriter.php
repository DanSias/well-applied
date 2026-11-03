<?php

namespace Tests\Support;

use App\Support\ResumeProfile\QuestionWriter;
use RuntimeException;

/** Returns a canned expansion result and records what it was sent. */
final class FakeExpansionWriter implements QuestionWriter
{
    public int $calls = 0;

    public ?string $lastSystem = null;

    public ?string $lastUser = null;

    /** @var array<string, mixed>|null */
    public ?array $lastSchema = null;

    /** @param array<string, mixed> $content */
    public function __construct(public array $content = ['claims' => [], 'followups' => []], public bool $fail = false) {}

    public function write(string $systemPrompt, string $userPrompt, array $schema): array
    {
        $this->calls++;
        $this->lastSystem = $systemPrompt;
        $this->lastUser = $userPrompt;
        $this->lastSchema = $schema;

        if ($this->fail) {
            throw new RuntimeException('model unavailable');
        }

        return ['content' => $this->content, 'model' => 'fake-model', 'usage' => ['input_tokens' => 1200, 'output_tokens' => 400, 'reasoning_tokens' => null]];
    }
}
