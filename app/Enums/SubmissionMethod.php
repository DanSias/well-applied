<?php

namespace App\Enums;

/**
 * How an application went out. Only a person doing it themselves exists today. `Assisted` and `Automated` are
 * reserved for later browser-assisted submission and are refused by RecordSubmission until a feature produces them.
 */
enum SubmissionMethod: string
{
    case Manual = 'manual';
    case Assisted = 'assisted';
    case Automated = 'automated';

    /** @return list<self> */
    public static function supported(): array
    {
        return [self::Manual];
    }
}
