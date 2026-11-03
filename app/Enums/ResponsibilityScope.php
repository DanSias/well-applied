<?php

namespace App\Enums;

/**
 * What the user actually did, in their own terms. Carried on every
 * proposal so wording can never claim more than the user said: a person
 * who configured webhooks did not necessarily implement webhook handlers.
 */
enum ResponsibilityScope: string
{
    case Implemented = 'implemented';
    case Integrated = 'integrated';
    case Configured = 'configured';
    case Troubleshot = 'troubleshot';
    case Advised = 'advised';
    case Supported = 'supported';
    case NoExperience = 'no_experience';

    public function label(): string
    {
        return match ($this) {
            self::Implemented => 'Implemented it myself',
            self::Integrated => 'Integrated it',
            self::Configured => 'Configured it',
            self::Troubleshot => 'Troubleshot it',
            self::Advised => 'Advised others on it',
            self::Supported => 'Supported it',
            self::NoExperience => 'No direct experience',
        };
    }
}
