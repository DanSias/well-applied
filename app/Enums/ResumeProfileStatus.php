<?php

namespace App\Enums;

/** Where a Resume Profile is in its build: sampling the market, interviewing, or ready to use. */
enum ResumeProfileStatus: string
{
    case Draft = 'draft';
    case Sampling = 'sampling';
    case Interviewing = 'interviewing';
    case Ready = 'ready';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Not started',
            self::Sampling => 'Studying the market',
            self::Interviewing => 'Interview in progress',
            self::Ready => 'Interview complete',
        };
    }
}
