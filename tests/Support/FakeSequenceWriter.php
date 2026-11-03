<?php

namespace Tests\Support;

use App\Support\ResumeProfile\QuestionWriter;
use RuntimeException;

/** Returns canned results in order (the last repeats) and records what it was sent. */
final class FakeSequenceWriter implements QuestionWriter
{
    public int $calls = 0;

    public ?string $lastUser = null;

    /** @param array<int, array<string, mixed>> $queue */
    public function __construct(private array $queue, private readonly bool $fail = false) {}

    public function write(string $systemPrompt, string $userPrompt, array $schema): array
    {
        $this->calls++;
        $this->lastUser = $userPrompt;

        if ($this->fail) {
            throw new RuntimeException('model unavailable');
        }

        $content = $this->queue[min($this->calls, count($this->queue)) - 1] ?? [];

        return ['content' => $content, 'model' => 'fake-model', 'usage' => ['input_tokens' => 1200, 'output_tokens' => 400, 'reasoning_tokens' => null]];
    }
}
