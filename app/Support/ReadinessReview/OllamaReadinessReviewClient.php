<?php

namespace App\Support\ReadinessReview;

use App\Contracts\GeneratesReadinessReview;
use App\Exceptions\ResumeEmptyOutputException;
use App\Exceptions\ResumeGenerationProviderException;
use App\Support\GenerationAttempt\TokenUsage;
use App\Support\OllamaChatCompletionsClient;
use App\Support\OllamaChatCompletionsException;
use App\Support\OllamaEmptyOutputException;
use App\Support\ProviderDiagnostics;
use App\Support\ResumeVariant\ResumeWordingProviderResponse;

/**
 * One structured request to the local Ollama server through the shared chat-completions transport. The output budget
 * covers the model's hidden reasoning as well as the compact answer; reasoning effort is kept low because the work is
 * judging a short document against a short list, not open-ended composition.
 */
final class OllamaReadinessReviewClient implements GeneratesReadinessReview
{
    public const MAX_OUTPUT_TOKENS = 8000;

    public const REASONING_EFFORT = 'low';

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $model,
        private readonly int $timeoutSeconds,
        private readonly OllamaChatCompletionsClient $transport = new OllamaChatCompletionsClient,
    ) {}

    public function generate(string $systemPrompt, string $userPrompt, array $schema): ResumeWordingProviderResponse
    {
        try {
            $result = $this->transport->call(
                baseUrl: $this->baseUrl,
                model: $this->model,
                systemPrompt: $systemPrompt,
                userPrompt: $userPrompt,
                schema: $schema,
                schemaName: 'resume_readiness_review',
                maxOutputTokens: self::MAX_OUTPUT_TOKENS,
                timeoutSeconds: $this->timeoutSeconds,
                logPrefix: 'Resume readiness review (Ollama)',
                reasoningEffort: self::REASONING_EFFORT,
            );
        } catch (OllamaChatCompletionsException $e) {
            $diagnostics = new ProviderDiagnostics(model: $e->model, finishReason: $e->finishReason, usage: $e->usage, maxOutputTokens: self::MAX_OUTPUT_TOKENS, timeoutSeconds: $this->timeoutSeconds);

            if ($e instanceof OllamaEmptyOutputException) {
                throw new ResumeEmptyOutputException($e->getMessage(), $diagnostics, $e, $e->reasoningPresent, $e->reasoningChars);
            }

            throw new ResumeGenerationProviderException($e->getMessage(), diagnostics: $diagnostics, previous: $e);
        }

        return new ResumeWordingProviderResponse('ollama', $result->model, $result->structuredContent, TokenUsage::fromChatCompletions($result->usage));
    }
}
