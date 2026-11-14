<?php

namespace App\Contracts;

use App\Exceptions\JobQualificationProviderException;
use App\Support\JobQualification\JobQualificationProviderResponse;

/**
 * One bounded, structured screening call. Knows nothing about job postings or Eloquent: it only sees prompt strings and
 * a schema, exactly like GeneratesJobAnalysis.
 */
interface GeneratesJobQualification
{
    /**
     * @param  array<string, mixed>  $schema
     *
     * @throws JobQualificationProviderException
     */
    public function generate(string $systemPrompt, string $userPrompt, array $schema): JobQualificationProviderResponse;
}
