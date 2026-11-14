<?php

namespace Tests\Support;

use App\Contracts\GeneratesJobQualification;
use App\Exceptions\JobQualificationProviderException;
use App\Support\JobQualification\JobQualificationProviderResponse;
use LogicException;

/** A scripted GeneratesJobQualification: no network, and a record of exactly what the pipeline sent it. */
final class FakeJobQualificationProvider implements GeneratesJobQualification
{
    /** @var array<string, mixed>|null */
    private ?array $content = null;

    private ?JobQualificationProviderException $failure = null;

    public int $callCount = 0;

    public ?string $systemPrompt = null;

    public ?string $userPrompt = null;

    /** @var array<string, mixed>|null */
    public ?array $schema = null;

    /** @param array<string, mixed> $content */
    public function willReturn(array $content): static
    {
        $this->content = $content;
        $this->failure = null;

        return $this;
    }

    public function willFail(string $message = 'Ollama request timed out.'): static
    {
        $this->failure = new JobQualificationProviderException($message);
        $this->content = null;

        return $this;
    }

    public function generate(string $systemPrompt, string $userPrompt, array $schema): JobQualificationProviderResponse
    {
        $this->callCount++;
        [$this->systemPrompt, $this->userPrompt, $this->schema] = [$systemPrompt, $userPrompt, $schema];

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return new JobQualificationProviderResponse('ollama', 'qwen-test', $this->content ?? throw new LogicException('No scripted response.'));
    }
}
