<?php

namespace App\Support\Submissions;

use RuntimeException;

/**
 * A recording the rules refuse (a second active submission without saying so, an event on a mistaken record, a time
 * that cannot be true). The message is safe to show; `reason` is a stable machine-readable code.
 */
final class SubmissionException extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }
}
