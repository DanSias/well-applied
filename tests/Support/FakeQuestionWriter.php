<?php

namespace Tests\Support;

use App\Support\ResumeProfile\QuestionWriter;
use RuntimeException;

/** Returns canned question text per id (or fails), and records every call. */
final class FakeQuestionWriter implements QuestionWriter
{
    public int $calls = 0;

    public ?string $lastUser = null;

    /** @var array<string, mixed>|null */
    public ?array $lastSchema = null;

    /** @param array<int, string>|null $texts  id => text; null = derive a valid question from the input */
    public function __construct(public ?array $texts = null, public bool $fail = false) {}

    public function write(string $systemPrompt, string $userPrompt, array $schema): array
    {
        $this->calls++;
        $this->lastUser = $userPrompt;
        $this->lastSchema = $schema;

        if ($this->fail) {
            throw new RuntimeException('model unavailable');
        }

        $items = collect(json_decode(substr($userPrompt, (int) strpos($userPrompt, '[')), true) ?? [])->keyBy('id');
        $rows = [];

        foreach ($schema['properties']['questions']['items']['properties']['id']['enum'] as $id) {
            $capability = $items[$id]['capability'] ?? 'this area';
            $rows[] = ['id' => $id, 'text' => $this->texts[$id] ?? "Which of your applications involved the most substantial {$capability} work, and what did you personally design or implement there?"];
        }

        return ['content' => ['questions' => $rows], 'model' => 'fake-model', 'usage' => ['input_tokens' => 900, 'output_tokens' => 300, 'reasoning_tokens' => null]];
    }
}
