<?php

namespace App\Support\ResumeProfile;

use App\Support\GenerationAttempt\TokenUsage;
use App\Support\OllamaChatCompletionsClient;
use App\Support\OllamaChatCompletionsException;

/**
 * The local model as a QuestionWriter, through the shared Ollama transport.
 */
final class OllamaQuestionWriter implements QuestionWriter
{
    private const MAX_OUTPUT_TOKENS = 6000;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $model,
        private readonly int $timeoutSeconds,
        private readonly OllamaChatCompletionsClient $transport = new OllamaChatCompletionsClient,
    ) {}

    public function write(string $systemPrompt, string $userPrompt, array $schema): array
    {
        try {
            $result = $this->transport->call(
                baseUrl: $this->baseUrl, model: $this->model, systemPrompt: $systemPrompt, userPrompt: $userPrompt, schema: $schema,
                schemaName: 'profile_questions', maxOutputTokens: self::MAX_OUTPUT_TOKENS, timeoutSeconds: $this->timeoutSeconds,
                logPrefix: 'Profile question enrichment (Ollama)', reasoningEffort: (string) config('resume_profiles.enrichment.reasoning_effort'),
            );
        } catch (OllamaChatCompletionsException $e) {
            throw new \RuntimeException($e->getMessage(), previous: $e);
        }

        return ['content' => $result->structuredContent, 'model' => $result->model, 'usage' => TokenUsage::fromChatCompletions($result->usage)];
    }
}
