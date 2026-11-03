<?php

namespace App\Support\ResumeVariant;

/**
 * The outcome of judging one Wording response without throwing.
 *
 * `structuralErrors` are whole-response problems (missing or duplicate
 * bullets, wrong shape) that only a new model response can fix.
 * `findings` are per-location content findings keyed by field path
 * (`summary`, `experience.0.bullets.1.text`, `selected_projects.0.text`);
 * each has a `severity` of `block` (must not ship) or `review` (ships,
 * flagged for a human). `validated` is the response in its validated
 * shape and is null when there are structural errors.
 */
final readonly class WordingTriage
{
    /**
     * @param  array<int, string>  $structuralErrors
     * @param  array<string, array<int, array{kind: string, severity: string, detail: string, message: string, repair?: array<string, string>}>>  $findings
     * @param  array<string, mixed>|null  $validated
     * @param  array<int, string>  $softProblems  Things a better response would fix (an approved capability dropped, a cited fact never stated). They earn one corrective retry and, if they persist, ship flagged rather than failing the run.
     */
    public function __construct(
        public array $structuralErrors,
        public array $findings,
        public ?array $validated,
        public array $softProblems = [],
    ) {}

    public function isStructurallyValid(): bool
    {
        return $this->structuralErrors === [];
    }

    /**
     * @return array<string, array<int, array{kind: string, severity: string, detail: string, message: string, repair?: array<string, string>}>>
     */
    public function blocked(): array
    {
        return array_filter(array_map(
            fn (array $list) => array_values(array_filter($list, fn (array $f) => $f['severity'] === 'block')),
            $this->findings,
        ));
    }

    public function hasBlocked(): bool
    {
        return $this->blocked() !== [];
    }
}
