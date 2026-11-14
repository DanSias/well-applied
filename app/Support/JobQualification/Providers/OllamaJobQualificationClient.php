<?php

namespace App\Support\JobQualification\Providers;

use App\Contracts\GeneratesJobQualification;
use App\Exceptions\JobQualificationProviderException;
use App\Support\GenerationAttempt\TokenUsage;
use App\Support\JobQualification\JobQualificationProviderResponse;
use App\Support\OllamaChatCompletionsClient;
use App\Support\OllamaChatCompletionsException;
use App\Support\ProviderDiagnostics;

/**
 * Calls the local Ollama server for one screening assessment, through the shared chat-completions transport. The output
 * budget is small on purpose: the contract is a few short fields, and a model that needs thousands of tokens to fill it
 * has gone off-contract.
 */
final class OllamaJobQualificationClient implements GeneratesJobQualification
{
    private const MAX_OUTPUT_TOKENS = 4000;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $model,
        private readonly int $timeoutSeconds,
        private readonly OllamaChatCompletionsClient $transport = new OllamaChatCompletionsClient,
    ) {}

    public function generate(string $systemPrompt, string $userPrompt, array $schema): JobQualificationProviderResponse
    {
        try {
            $result = $this->transport->call(
                baseUrl: $this->baseUrl,
                model: $this->model,
                systemPrompt: $systemPrompt,
                userPrompt: $userPrompt,
                schema: $schema,
                schemaName: 'job_qualification',
                maxOutputTokens: self::MAX_OUTPUT_TOKENS,
                timeoutSeconds: $this->timeoutSeconds,
                logPrefix: 'JobQualification generation (Ollama)',
            );
        } catch (OllamaChatCompletionsException $e) {
            throw new JobQualificationProviderException($e->getMessage(), diagnostics: new ProviderDiagnostics(
                model: $e->model,
                finishReason: $e->finishReason,
                usage: $e->usage,
                maxOutputTokens: self::MAX_OUTPUT_TOKENS,
                timeoutSeconds: $this->timeoutSeconds,
            ), previous: $e);
        }

        return new JobQualificationProviderResponse(
            provider: 'ollama',
            model: $result->model,
            structuredContent: $result->structuredContent,
            usage: TokenUsage::fromChatCompletions($result->usage),
        );
    }
}
