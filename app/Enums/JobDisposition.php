<?php

namespace App\Enums;

/**
 * What the user (or, later, a rule) has decided about a discovered job. Deliberately separate from the external
 * posting's open/closed lifecycle (JobPostingLifecycleStatus), the derived processing stage (raw/analyzing/analyzed/
 * matched/failed) and application-inspection state: those describe the posting and the pipeline, this describes a
 * decision. An Opportunity is not a table; it is a job whose disposition is `pursued`.
 */
enum JobDisposition: string
{
    case New = 'new';
    case Shortlisted = 'shortlisted';
    case Dismissed = 'dismissed';
    case Pursued = 'pursued';

    public function label(): string
    {
        return match ($this) {
            self::New => 'Inbox',
            self::Shortlisted => 'Shortlisted',
            self::Dismissed => 'Dismissed',
            self::Pursued => 'Pursued',
        };
    }

    /**
     * The moves a person may make from here. Dismissed jobs are restored first, so pursuing is always a deliberate
     * step from a job that is still being considered.
     *
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::New => [self::Shortlisted, self::Dismissed, self::Pursued],
            self::Shortlisted => [self::New, self::Dismissed, self::Pursued],
            self::Dismissed => [self::New, self::Shortlisted],
            self::Pursued => [self::New, self::Shortlisted, self::Dismissed],
        };
    }
}
