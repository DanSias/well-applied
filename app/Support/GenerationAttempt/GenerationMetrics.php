<?php

namespace App\Support\GenerationAttempt;

use Closure;

/**
 * Collects provider-neutral timing and usage while one generation job
 * runs, so the job can persist it on the GenerationAttempt when it
 * succeeds. Registered as a container singleton; each job calls
 * reset() first and read() after.
 *
 * Generic services record through measure() and hand over a
 * normalized usage array (see TokenUsage); nothing here knows about any
 * provider's wire format.
 */
final class GenerationMetrics
{
    /** @var array<string, array{ms: int, calls: int}> */
    private array $stages = [];

    private int $inputTokens = 0;

    private int $outputTokens = 0;

    private int $reasoningTokens = 0;

    private bool $sawInput = false;

    private bool $sawOutput = false;

    private bool $sawReasoning = false;

    private int $providerCalls = 0;

    /** @var array<int, array<string, mixed>> */
    private array $events = [];

    private bool $selectionReused = false;

    public function reset(): void
    {
        $this->stages = [];
        $this->events = [];
        $this->selectionReused = false;
        $this->inputTokens = $this->outputTokens = $this->reasoningTokens = $this->providerCalls = 0;
        $this->sawInput = $this->sawOutput = $this->sawReasoning = false;
    }

    /**
     * Times one provider call under a stage name and records its usage.
     *
     * @template T
     *
     * @param  Closure(): T  $call
     * @param  Closure(T): (array{input_tokens?: int|null, output_tokens?: int|null, reasoning_tokens?: int|null}|null)|null  $usageOf
     * @return T
     */
    public function measure(string $stage, Closure $call, ?Closure $usageOf = null): mixed
    {
        $start = hrtime(true);

        try {
            $result = $call();
        } finally {
            $this->addTime($stage, (int) round((hrtime(true) - $start) / 1_000_000));
            $this->providerCalls++;
        }

        if ($usageOf !== null) {
            $this->addUsage($usageOf($result));
        }

        return $result;
    }

    /**
     * @param  array{input_tokens?: int|null, output_tokens?: int|null, reasoning_tokens?: int|null}|null  $usage
     */
    public function addUsage(?array $usage): void
    {
        if ($usage === null) {
            return;
        }

        foreach (['input' => 'inputTokens', 'output' => 'outputTokens', 'reasoning' => 'reasoningTokens'] as $key => $property) {
            $value = $usage["{$key}_tokens"] ?? null;

            if ($value !== null) {
                $this->{$property} += $value;
                $this->{'saw'.ucfirst($key)} = true;
            }
        }
    }

    public function addTime(string $stage, int $ms): void
    {
        $this->stages[$stage] = ['ms' => ($this->stages[$stage]['ms'] ?? 0) + $ms, 'calls' => ($this->stages[$stage]['calls'] ?? 0) + 1];
    }

    /**
     * One reliability event (a rejection, an empty output, a checkpoint
     * reuse). Callers pass only non-sensitive facts: a stage, a kind, a
     * validator message, sizes. Never prompts, model output or reasoning.
     *
     * @param  array<string, mixed>  $event
     */
    public function event(array $event): void
    {
        $this->events[] = $event;
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    public function events(): ?array
    {
        return $this->events === [] ? null : $this->events;
    }

    public function markSelectionReused(): void
    {
        $this->selectionReused = true;
    }

    public function selectionReused(): bool
    {
        return $this->selectionReused;
    }

    public function stageMs(string $stage): ?int
    {
        return $this->stages[$stage]['ms'] ?? null;
    }

    public function stageCalls(string $stage): int
    {
        return $this->stages[$stage]['calls'] ?? 0;
    }

    /**
     * Columns for GenerationAttempt. Token fields are null (not zero)
     * when no provider call reported them.
     *
     * @return array<string, int|null>
     */
    public function tokenColumns(): array
    {
        return [
            'prompt_tokens' => $this->sawInput ? $this->inputTokens : null,
            'completion_tokens' => $this->sawOutput ? $this->outputTokens : null,
            'reasoning_tokens' => $this->sawReasoning ? $this->reasoningTokens : null,
            'total_tokens' => ($this->sawInput || $this->sawOutput) ? $this->inputTokens + $this->outputTokens : null,
            'provider_calls' => $this->providerCalls > 0 ? $this->providerCalls : null,
        ];
    }
}
