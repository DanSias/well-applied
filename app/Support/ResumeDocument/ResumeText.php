<?php

namespace App\Support\ResumeDocument;

use Illuminate\Support\HtmlString;

/**
 * Deterministic text hygiene for candidate-facing resume strings —
 * applied at the document layer (GenerateResumeDocument) so it also
 * covers variants persisted before a rule existed, and at generation
 * time so newly persisted text is already clean. Never rewrites
 * meaning: it only normalizes punctuation and controls line breaks.
 */
final class ResumeText
{
    private const EM_DASH = "\u{2014}";

    /**
     * Resumes must never contain an em dash. A clause break is the
     * closest faithful substitute ("boundaries—keeping" reads
     * "boundaries, keeping"); surrounding whitespace is normalized.
     */
    public static function clean(string $text): string
    {
        if (! str_contains($text, self::EM_DASH)) {
            return $text;
        }

        return (string) preg_replace('/\s*'.self::EM_DASH.'\s*/u', ', ', $text);
    }

    public static function containsEmDash(string $text): bool
    {
        return str_contains($text, self::EM_DASH);
    }

    /**
     * HTML for a paragraph/bullet whose last two words are kept
     * together (`white-space: nowrap` span), so the final line can
     * never be a single orphaned word. A pure CSS wrapper: the
     * extracted/ATS text keeps an ordinary space between the words.
     */
    public static function html(string $text): HtmlString
    {
        $text = rtrim($text);

        if (! preg_match('/^(?:(.*\S)\s+)?(\S+\s+\S+)$/us', $text, $parts)) {
            return new HtmlString(e($text));
        }

        $head = $parts[1] === '' ? '' : e($parts[1]).' ';

        return new HtmlString($head.'<span class="nw">'.e($parts[2]).'</span>');
    }
}
