<?php

namespace App\Support\Submissions;

use App\Enums\SubmissionPhase;
use App\Models\SubmissionEvent;

/** What SummarizeSubmission derives from a submission's events. Read-only; nothing here is stored. */
final readonly class SubmissionSummary
{
    public function __construct(
        public SubmissionPhase $phase,
        /** Still in flight: not ended and not a mistaken record. The only thing "another submission" is judged by. */
        public bool $active,
        public bool $recordedInError,
        /** A confirmation was recorded (never true for a record marked in error). */
        public bool $confirmed,
        public int $interviews,
        public int $followUps,
        /** More than one kind of ending was recorded (for example rejected and withdrawn): the latest stands. */
        public bool $conflictingOutcomes,
        public ?SubmissionEvent $latestEvent,
    ) {}
}
