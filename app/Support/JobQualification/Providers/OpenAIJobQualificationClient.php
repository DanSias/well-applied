<?php

namespace App\Support\JobQualification\Providers;

use App\Contracts\GeneratesJobQualification;
use App\Exceptions\JobQualificationProviderException;
use App\Support\JobQualification\JobQualificationProviderResponse;
use App\Support\OpenAIResponsesApiClient;
use App\Support\OpenAIResponsesApiException;

/** The explicitly selectable OpenAI alternative (AI_JOB_QUALIFICATION_PROVIDER=openai); never an automatic fallback. */
final class OpenAIJobQualificationClient implements GeneratesJobQualification
{
    private const MAX_OUTPUT_TOKENS = 4000;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        private readonly OpenAIResponsesApiClient $transport = new OpenAIResponsesApiClient,
    ) {}

    public function generate(string $systemPrompt, string $userPrompt, array $schema): JobQualificationProviderResponse
    {
        try {
            $result = $this->transport->call(
                apiKey: $this->apiKey,
                model: $this->model,
                systemPrompt: $systemPrompt,
                userPrompt: $userPrompt,
                schema: $schema,
                schemaName: 'job_qualification',
                maxOutputTokens: self::MAX_OUTPUT_TOKENS,
                logPrefix: 'JobQualification generation',
            );
        } catch (OpenAIResponsesApiException $e) {
            throw new JobQualificationProviderException($e->getMessage(), previous: $e);
        }

        return new JobQualificationProviderResponse(provider: 'openai', model: $result->model, structuredContent: $result->structuredContent);
    }
}
