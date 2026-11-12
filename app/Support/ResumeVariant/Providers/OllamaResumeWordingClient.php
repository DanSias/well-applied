<?php

namespace App\Support\ResumeVariant\Providers;

use App\Contracts\GeneratesResumeWording;
use App\Exceptions\ResumeEmptyOutputException;
use App\Exceptions\ResumeGenerationProviderException;
use App\Support\GenerationAttempt\TokenUsage;
use App\Support\OllamaChatCompletionsClient;
use App\Support\OllamaChatCompletionsException;
use App\Support\OllamaEmptyOutputException;
use App\Support\ProviderDiagnostics;
use App\Support\ResumeVariant\ResumeWordingProviderResponse;

/**
 * Calls a local/self-hosted Ollama server's OpenAI-compatible Chat
 * Completions endpoint for Resume Wording generation, using the same
 * `response_format: {type: "json_schema", json_schema: {...}}`
 * mechanism as OllamaResumeSelectionClient/OllamaJobMatchClient/
 * OllamaJobAnalysisClient. The actual HTTP-transport mechanics live in
 * the shared, unmodified App\Support\OllamaChatCompletionsClient — this
 * class owns only what's specific to Resume Wording: its own schema
 * name/token budget and its own *ProviderResponse DTO, exactly
 * mirroring OpenAIResumeWordingClient's division of responsibility.
 *
 * This is the local-first default GeneratesResumeWording implementation
 * — bound by AppServiceProvider::resolveResumeWordingProvider() whenever
 * AI_RESUME_WORDING_PROVIDER is unset/blank or explicitly 'ollama'.
 * OpenAIResumeWordingClient remains fully supported as an explicitly
 * selectable alternative (AI_RESUME_WORDING_PROVIDER=openai), never an
 * automatic fallback target from here. See
 * docs/resume-variant-generation.md "Provider boundary".
 *
 * ResumeWordingPromptV1, its JSON schema, and
 * ResumeWordingResponseValidator are entirely provider-neutral and
 * unchanged by this client's existence — this adapter only chooses
 * where the same prompt/schema is sent.
 */
final class OllamaResumeWordingClient implements GeneratesResumeWording
{
    /**
     * The requested output-token budget sent as `max_tokens` — not a
     * provider-enforced ceiling (Ollama's OpenAI-compat endpoint treats
     * this as a soft budget, same caveat as OllamaResumeSelectionClient's
     * own budget; reported completion usage may exceed the requested
     * value).
     *
     * Deliberately NOT OpenAIResumeWordingClient's own 6,000: that
     * budget is sized for prose alone, and Resume Selection's first
     * local run proved a reasoning-capable local model's *generation*
     * cost is what exhausts this budget, not final output size — it
     * returned `finish_reason: length` at exactly 6,000 completion
     * tokens while producing a final structure worth barely ~1,100.
     * The two real, already-persisted Formic Wording responses are
     * 4,130/4,949 chars of compact JSON (≈900-1,100 tokens), so the
     * visible answer here is smaller than Selection's; 16,000 keeps the
     * same generous reasoning headroom Selection needed empirically
     * while staying well inside the 65,536-token configured context
     * against a Wording prompt measured at ~144K chars (≈32K tokens at
     * the 4.49 chars/token ratio calibrated from Selection's own real
     * tokenizer-reported usage). Revisit against the first real
     * local run's reported usage rather than treating this as settled.
     */
    private const MAX_OUTPUT_TOKENS = 16000;

    /**
     * The reasoning effort asked for on the request that follows an empty or truncated reply. The live test showed why an
     * identical retry is pointless: a reasoning model spent 14.7k of its 16k output tokens thinking and returned nothing, and
     * asking again with the same input repeats the same thinking. "low" did not help: qwen3.8:27b has on/off thinking (Ollama
     * lists the capability as plain `thinking`, with no levels), so every level but "none" means on, and the retry that asked
     * for "low" still spent 13.2k tokens thinking. "none" turns thinking off, which the earlier Job Match experiments showed
     * this server and model honor. It is used only after a reply that was already unusable, where the alternative is failure.
     */
    private const RETRY_REASONING_EFFORT = 'none';

    /** Set when the last reply was empty or truncated; cleared by a reply that carries content. */
    private bool $lastReplyUnusable = false;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $model,
        private readonly int $timeoutSeconds,
        private readonly OllamaChatCompletionsClient $transport = new OllamaChatCompletionsClient,
        /** Sent on every request when set (OLLAMA_RESUME_WORDING_REASONING_EFFORT). Null leaves the model's own default thinking. */
        private readonly ?string $reasoningEffort = null,
        /** Sent on the request after an empty or truncated reply (OLLAMA_RESUME_WORDING_RETRY_REASONING_EFFORT). */
        private readonly ?string $retryReasoningEffort = self::RETRY_REASONING_EFFORT,
    ) {}

    /**
     * @param  array<string, mixed>  $schema
     */
    public function generate(string $systemPrompt, string $userPrompt, array $schema): ResumeWordingProviderResponse
    {
        try {
            $result = $this->transport->call(
                baseUrl: $this->baseUrl,
                model: $this->model,
                systemPrompt: $systemPrompt,
                userPrompt: $userPrompt,
                schema: $schema,
                schemaName: 'resume_wording',
                maxOutputTokens: self::MAX_OUTPUT_TOKENS,
                timeoutSeconds: $this->timeoutSeconds,
                logPrefix: 'Resume Wording generation (Ollama)',
                reasoningEffort: $this->lastReplyUnusable ? ($this->retryReasoningEffort ?? $this->reasoningEffort) : $this->reasoningEffort,
            );
        } catch (OllamaChatCompletionsException $e) {
            $diagnostics = new ProviderDiagnostics(
                model: $e->model,
                finishReason: $e->finishReason,
                usage: $e->usage,
                maxOutputTokens: self::MAX_OUTPUT_TOKENS,
                timeoutSeconds: $this->timeoutSeconds,
            );

            if ($e instanceof OllamaEmptyOutputException) {
                $this->lastReplyUnusable = true;

                throw new ResumeEmptyOutputException($e->getMessage(), $diagnostics, $e, $e->reasoningPresent, $e->reasoningChars);
            }

            // A reply cut off at the output budget is, for the caller, the same as an empty one: there is no usable
            // answer, and asking again at a lower reasoning effort can produce one. It is retried inside the same bounded
            // number of wording calls, not failed outright.
            if ($e->finishReason === 'length') {
                $this->lastReplyUnusable = true;

                throw new ResumeEmptyOutputException($e->getMessage(), $diagnostics, $e);
            }

            throw new ResumeGenerationProviderException($e->getMessage(), diagnostics: $diagnostics, previous: $e);
        }

        $this->lastReplyUnusable = false;

        return new ResumeWordingProviderResponse(
            provider: 'ollama',
            model: $result->model,
            structuredContent: $result->structuredContent,
            usage: TokenUsage::fromChatCompletions($result->usage),
        );
    }
}
