<?php

namespace App\Support\JobFreshness\Pilot;

/** What a transport hands back: nothing is kept beyond this object except what the runner chooses to record. Header names are lower-case. */
final readonly class PilotResponse
{
    /** @param array<string, string> $headers */
    public function __construct(public int $status, public array $headers, public string $body) {}
}
