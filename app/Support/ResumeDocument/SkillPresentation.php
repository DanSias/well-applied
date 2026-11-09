<?php

namespace App\Support\ResumeDocument;

/**
 * How a reference resume shows Technical Skills: recognizable categories, professional labels, and line breaks decided
 * here rather than by the browser. The map lives in config/resume.php; a canonical skill that is not in it is simply not
 * shown, which keeps internal tags and obscure tools off the page without inventing or renaming anything.
 */
final class SkillPresentation
{
    /**
     * @param  string|null  $layout  A named regrouping from config (e.g. "tailored"); null is the reference grouping. A
     *                               layout changes only the category: the label still comes from the shared map, and a
     *                               skill the layout does not place is not shown.
     * @return array{0: string, 1: string}|null [category, display label]
     */
    public static function for(string $canonicalName, ?string $layout = null): ?array
    {
        // Not config()'s dot notation: names such as "Node.js" contain dots.
        $entry = ((array) config('resume.skill_presentation.skills'))[$canonicalName] ?? null;

        if (! is_array($entry)) {
            return null;
        }

        if ($layout === null) {
            return [(string) $entry[0], (string) $entry[1]];
        }

        $category = ((array) config("resume.skill_presentation.layouts.{$layout}.categories"))[$canonicalName] ?? null;

        return is_string($category) ? [$category, (string) $entry[1]] : null;
    }

    /**
     * @return array<int, string>
     */
    public static function groups(?string $layout = null): array
    {
        return array_values((array) ($layout === null ? config('resume.skill_presentation.groups') : config("resume.skill_presentation.layouts.{$layout}.groups")));
    }

    /**
     * Packs skills into the fewest lines that fit the width, balanced so no line is left with a lone skill, and never
     * breaks inside a skill. The first line carries the label, so it holds fewer characters. With one skill, or skills
     * that fit one line, there is one line.
     *
     * @param  array<int, string>  $skills
     * @return array<int, string>
     */
    public static function pack(string $label, array $skills, ?int $width = null): array
    {
        $width ??= (int) config('resume.skill_presentation.line_chars', 88);
        $skills = array_values($skills);
        $separator = ' · ';
        $joined = fn (array $items) => implode($separator, $items);

        if ($skills === [] || mb_strlen($label.': '.$joined($skills)) <= $width) {
            return $skills === [] ? [] : [$joined($skills)];
        }

        // The fewest lines that fit, then the most even split of the characters across them.
        for ($lines = 2; $lines <= count($skills); $lines++) {
            $best = self::balance($label, $skills, $lines, $width, $separator);

            if ($best !== null) {
                return $best;
            }
        }

        return array_map(fn (string $s) => $s, $skills);
    }

    /**
     * @param  array<int, string>  $skills
     * @return array<int, string>|null
     */
    private static function balance(string $label, array $skills, int $lines, int $width, string $separator): ?array
    {
        $count = count($skills);
        $best = null;
        $bestSpread = PHP_INT_MAX;

        // Try every way to cut the list into $lines runs of at least two skills (one only if the whole list is one).
        $cuts = self::cuts($count, $lines);

        foreach ($cuts as $cut) {
            $rows = [];
            $from = 0;

            foreach ($cut as $size) {
                $rows[] = implode($separator, array_slice($skills, $from, $size));
                $from += $size;
            }

            $lengths = array_map(fn (string $row, int $i) => mb_strlen(($i === 0 ? $label.': ' : '').$row), $rows, array_keys($rows));

            $longest = max([0, ...$lengths]);

            if ($longest > $width) {
                continue;
            }

            $spread = $longest - min([PHP_INT_MAX, ...$lengths]);

            if ($spread < $bestSpread) {
                $bestSpread = $spread;
                $best = $rows;
            }
        }

        return $best;
    }

    /**
     * Compositions of $count into $parts positive parts, each at least 2 when $count allows it.
     *
     * @return array<int, array<int, int>>
     */
    private static function cuts(int $count, int $parts): array
    {
        $min = $count >= $parts * 2 ? 2 : 1;
        $out = [];
        $walk = function (int $left, int $partsLeft, array $acc) use (&$walk, &$out, $min): void {
            if ($partsLeft === 1) {
                if ($left >= $min) {
                    $out[] = [...$acc, $left];
                }

                return;
            }

            for ($size = $min; $size <= $left - $min * ($partsLeft - 1); $size++) {
                $walk($left - $size, $partsLeft - 1, [...$acc, $size]);
            }
        };
        $walk($count, $parts, []);

        return $out;
    }
}
