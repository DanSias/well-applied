<?php

namespace App\Support\ResumeProfile;

/**
 * Turns a provider's description (often HTML) into plain lines, and gives
 * every posting a stable content identity for duplicate detection and for the
 * extraction cache.
 */
final class PostingText
{
    /**
     * Plain text with one logical line per list item, paragraph or heading.
     */
    public static function clean(string $description): string
    {
        $text = preg_replace('~<\s*(?:br|/p|/div|/li|/h[1-6]|/ul|/ol|/tr)\s*/?>~i', "\n", $description) ?? $description;
        $text = preg_replace('~<\s*li[^>]*>~i', "\n- ", $text) ?? $text;
        $text = preg_replace('~<\s*(?:h[1-6]|p|div|ul|ol)[^>]*>~i', "\n", $text) ?? $text;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{a0}", ' ', $text);
        $lines = array_map(fn (string $l) => trim((string) preg_replace('/[ \t]+/u', ' ', $l)), explode("\n", $text));

        return trim((string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)));
    }

    /** sha256 of the lowercased, whitespace-collapsed text: formatting changes do not change identity. */
    public static function hash(string $cleanText): string
    {
        return hash('sha256', mb_strtolower((string) preg_replace('/\s+/u', ' ', trim($cleanText))));
    }

    /**
     * Hashed 5-word shingles of the first ~1500 words, for near-duplicate detection.
     *
     * @return array<int, int>
     */
    public static function shingles(string $cleanText): array
    {
        $words = preg_split('/[^\p{L}\p{N}#+.]+/u', mb_strtolower($cleanText), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_slice($words, 0, 1500);
        $set = [];

        for ($i = 0, $n = count($words) - 4; $i < $n; $i++) {
            $set[crc32(implode(' ', array_slice($words, $i, 5)))] = 1;
        }

        return array_keys($set);
    }

    /**
     * @param  array<int, int>  $a
     * @param  array<int, int>  $b
     */
    public static function similarity(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }

        $intersection = count(array_intersect_key(array_flip($a), array_flip($b)));

        return $intersection / (count($a) + count($b) - $intersection);
    }

    public static function normalizeCompany(string $company): string
    {
        $name = mb_strtolower($company);
        $name = (string) preg_replace('/\b(?:inc|llc|ltd|corp|corporation|co|gmbh|plc)\b\.?/u', '', $name);

        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $name));
    }

    public static function normalizeTitle(string $title): string
    {
        $t = mb_strtolower($title);
        $t = (string) preg_replace('/\b(?:remote|us|usa|united states|hybrid|\(.*?\)|\[.*?\])\b/u', '', $t);

        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $t));
    }
}
