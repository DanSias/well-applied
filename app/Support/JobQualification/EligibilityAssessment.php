<?php

namespace App\Support\JobQualification;

use App\Enums\QualificationEligibility;

/**
 * The deterministic gate's outcome and every reason behind it, each with the source evidence it rests on (a field value or
 * the exact sentence of the posting).
 */
final readonly class EligibilityAssessment
{
    /**
     * @param  list<array{code: string, severity: string, message: string, evidence: string|null}>  $reasons
     */
    public function __construct(
        public QualificationEligibility $outcome,
        public array $reasons,
    ) {}
}
