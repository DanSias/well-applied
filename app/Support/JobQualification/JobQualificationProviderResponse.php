<?php

namespace App\Support\JobQualification;

final readonly class JobQualificationProviderResponse
{
    /**
     * @param  array<string, mixed>  $structuredContent
     * @param  array<string, mixed>|null  $usage
     */
    public function __construct(
        public string $provider,
        public string $model,
        public array $structuredContent,
        public ?array $usage = null,
    ) {}

    public function generatedBy(): string
    {
        return "{$this->provider}:{$this->model}";
    }
}
