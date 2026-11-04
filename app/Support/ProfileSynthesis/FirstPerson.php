<?php

namespace App\Support\ProfileSynthesis;

/**
 * First-person wording ("I've used JavaScript extensively…"). An approved fact written that way is real evidence, so it
 * may authorize a capability, but its wording is never offered to the synthesis model and never reaches a resume.
 */
final class FirstPerson
{
    private const PATTERN = "/(?<![\\w'])(?:I|I've|I'm|my|me)(?![\\w])/";

    public static function in(string $text): bool
    {
        return preg_match(self::PATTERN, $text) === 1;
    }
}
