<?php

namespace App\Support\ResumeProfile;

/**
 * Phrases interview questions in one batch. Implementations only rephrase what they are
 * given; ranking and the facts used stay deterministic. Throws on any failure so the caller
 * can fall back to the deterministic wording.
 */
interface QuestionWriter
{
    /**
     * @param  array<string, mixed>  $schema  bounded JSON schema for the response
     * @return array{content: array<string, mixed>, model: string, usage: array<string, mixed>|null}
     *
     * @throws \RuntimeException
     */
    public function write(string $systemPrompt, string $userPrompt, array $schema): array;
}
