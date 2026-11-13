<?php

namespace App\Enums;

/** What backs an event: what the person reports, or something they point to. Others are reserved for later matching. */
enum SubmissionEvidenceKind: string
{
    case SelfReported = 'self_reported';
    case Url = 'url';
    case Email = 'email';
    case Automation = 'automation';

    /** @return list<self> */
    public static function supported(): array
    {
        return [self::SelfReported, self::Url];
    }
}
