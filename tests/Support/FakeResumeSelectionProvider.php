<?php

namespace Tests\Support;

use App\Contracts\GeneratesResumeSelection;
use App\Exceptions\ResumeGenerationProviderException;
use App\Support\ResumeVariant\ResumeSelectionProviderResponse;
use LogicException;

/**
 * A test double for GeneratesResumeSelection — lets the deterministic
 * test suite exercise the full generation pipeline with zero real
 * network calls, and records exactly what the orchestrator sent it.
 * Mirrors Tests\Support\FakeJobMatchProvider.
 */
final class FakeResumeSelectionProvider implements GeneratesResumeSelection
{
    private ?ResumeSelectionProviderResponse $response = null;

    private ?ResumeGenerationProviderException $failure = null;

    public ?string $capturedSystemPrompt = null;

    public ?string $capturedUserPrompt = null;

    /** @var array<string, mixed>|null */
    public ?array $capturedSchema = null;

    public int $callCount = 0;

    /** @var array<int, ResumeSelectionProviderResponse|ResumeGenerationProviderException> */
    private array $sequence = [];

    /**
     * Outcomes (a response or an exception to throw) used one per call;
     * the last repeats.
     *
     * @param  array<int, ResumeSelectionProviderResponse|ResumeGenerationProviderException>  $outcomes
     */
    public function willReturnInOrder(array $outcomes): static
    {
        $this->sequence = array_values($outcomes);
        $this->failure = null;

        return $this;
    }

    public function willReturn(ResumeSelectionProviderResponse $response): static
    {
        $this->response = $response;
        $this->failure = null;

        return $this;
    }

    public function willFail(ResumeGenerationProviderException $failure): static
    {
        $this->failure = $failure;
        $this->response = null;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    public function generate(string $systemPrompt, string $userPrompt, array $schema): ResumeSelectionProviderResponse
    {
        $this->callCount++;
        $this->capturedSystemPrompt = $systemPrompt;
        $this->capturedUserPrompt = $userPrompt;
        $this->capturedSchema = $schema;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        if ($this->sequence !== []) {
            $outcome = $this->sequence[min($this->callCount, count($this->sequence)) - 1];

            return $outcome instanceof ResumeGenerationProviderException ? throw $outcome : $outcome;
        }

        return $this->response ?? throw new LogicException('FakeResumeSelectionProvider has no configured response.');
    }
}
