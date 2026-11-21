<?php

namespace App\Support\JobFreshness;

/**
 * What the policy concludes for one job: its state, whether it is actionable, and the ACTION that would follow. An action is only an intent: nothing in B3 writes
 * it. `protected` marks a job that must never be changed automatically (pursued, or with an Application): its state is still reported, its action becomes `review`.
 *
 *   action: none | set_closed | reopen | review
 */
final readonly class LivenessDecision
{
    /** @param list<array<string, mixed>> $evidence */
    public function __construct(
        public FreshnessState $state,
        public string $action,
        public string $reason,
        public bool $protected = false,
        public array $evidence = [],
        public bool $conflict = false,
    ) {}

    public function actionable(): bool
    {
        return $this->state->actionable();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['state' => $this->state->value, 'actionable' => $this->actionable(), 'action' => $this->action, 'reason' => $this->reason, 'protected' => $this->protected, 'conflict' => $this->conflict, 'evidence' => $this->evidence];
    }
}
