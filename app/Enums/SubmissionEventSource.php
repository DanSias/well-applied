<?php

namespace App\Enums;

/**
 * Who recorded a submission event. Only a person does today. `Email` and `Automation` are reserved for later
 * confirmation matching and browser-assisted submission, and are refused until a feature produces them: nothing
 * infers a confirmation or an outcome.
 */
enum SubmissionEventSource: string
{
    case User = 'user';
    case Email = 'email';
    case Automation = 'automation';

    /** @return list<self> */
    public static function supported(): array
    {
        return [self::User];
    }
}
