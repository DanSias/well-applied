<?php

namespace App\Contracts;

use App\Exceptions\ResumeGenerationProviderException;
use App\Support\ResumeVariant\ResumeWordingProviderResponse;

/**
 * The seam between the Application Readiness Review and the model that answers its one structured request. Local-first:
 * the only implementation is Ollama.
 */
interface GeneratesReadinessReview
{
    /**
     * @param  array<string, mixed>  $schema  JSON Schema the structured response must satisfy.
     *
     * @throws ResumeGenerationProviderException on any transport/provider failure.
     */
    public function generate(string $systemPrompt, string $userPrompt, array $schema): ResumeWordingProviderResponse;
}
