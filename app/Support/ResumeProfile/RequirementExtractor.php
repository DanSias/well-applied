<?php

namespace App\Support\ResumeProfile;

/**
 * Deterministic reading of one posting against the capability taxonomy
 * (config/resume_profiles.php). No model call.
 *
 * A mention is classified by the line it sits on, then by the section
 * heading above it:
 *   required  - a qualification or requirement
 *   preferred - "nice to have", "a plus", "preferred"
 *   duty      - something the person will do in the role
 *   mentioned - anywhere else in the role description
 * Benefits, perks and "about us" sections are ignored. A capability keeps the
 * strongest level it was seen at in the posting (required > duty > mentioned >
 * preferred), plus the line that showed it.
 *
 * Each capability lists `patterns` (an explicit phrase) and optionally
 * `weak_patterns` (an ambiguous word such as "scalable" or "monitoring").
 * A hit records the phrase that matched and a confidence: `high` (explicit
 * phrase in a requirement, duty or the title), `medium` (explicit phrase
 * elsewhere or as a preference) or `low` (only an ambiguous word). Low hits
 * are kept as evidence but never counted towards prevalence.
 *
 * Output depends only on (text, title, extraction_version), which is what
 * makes it cacheable by content hash.
 */
final class RequirementExtractor
{
    private const RANK = ['preferred' => 1, 'mentioned' => 2, 'duty' => 3, 'required' => 4];

    /**
     * @return array{version: string, seniority: string, years_min: int|null, stack: string|null, capabilities: array<string, array{level: string, snippet: string, matched: string, confidence: string}>}
     */
    public function extract(string $title, string $text): array
    {
        $capabilities = (array) config('resume_profiles.capabilities');
        $found = [];

        foreach ($capabilities as $key => $capability) {
            $hit = $this->hit($capability, $title);

            if ($hit !== null) {
                $found[$key] = ['level' => 'required', 'snippet' => 'Title: '.$title, 'matched' => $hit['matched'], 'confidence' => $hit['strong'] ? 'high' : 'low'];
            }
        }

        $section = 'unknown';
        $levels = (array) config('resume_profiles.levels');

        foreach ($this->lines($text) as $line) {
            $heading = $this->headingOf($line, $levels);

            if ($heading !== null) {
                $section = $heading;

                continue;
            }

            if ($section === 'ignored') {
                continue;
            }

            $level = match (true) {
                preg_match($levels['preferred_line'], $line) === 1, $section === 'preferred' => 'preferred',
                $section === 'duty' => 'duty',
                $section === 'required', preg_match($levels['required_line'], $line) === 1 => 'required',
                default => 'mentioned',
            };

            foreach ($capabilities as $key => $capability) {
                $hit = $this->hit($capability, $line);

                if ($hit === null) {
                    continue;
                }

                $confidence = ! $hit['strong'] ? 'low' : (in_array($level, ['required', 'duty'], true) ? 'high' : 'medium');
                $candidate = ['level' => $level, 'snippet' => mb_strimwidth($line, 0, 180, '...'), 'matched' => $hit['matched'], 'confidence' => $confidence];

                if (! isset($found[$key]) || $this->better($candidate, $found[$key])) {
                    $found[$key] = $candidate;
                }
            }
        }

        ksort($found);
        $years = $this->yearsMin($text);

        return [
            'version' => (string) config('resume_profiles.extraction_version'),
            'seniority' => $this->seniority($title, $years),
            'years_min' => $years,
            'stack' => $this->stack($text),
            'capabilities' => $found,
        ];
    }

    /**
     * @param  array<string, mixed>  $capability
     * @return array{matched: string, strong: bool}|null
     */
    private function hit(array $capability, string $text): ?array
    {
        foreach ((array) $capability['patterns'] as $pattern) {
            if (preg_match($pattern, $text, $m) === 1) {
                return ['matched' => $m[0], 'strong' => true];
            }
        }

        foreach ((array) ($capability['weak_patterns'] ?? []) as $pattern) {
            if (preg_match($pattern, $text, $m) === 1) {
                return ['matched' => $m[0], 'strong' => false];
            }
        }

        return null;
    }

    /**
     * An explicit phrase beats an ambiguous word; then the stronger level.
     *
     * @param  array{level: string, confidence: string}  $new
     * @param  array{level: string, confidence: string}  $old
     */
    private function better(array $new, array $old): bool
    {
        $score = fn (array $h) => ($h['confidence'] === 'low' ? 0 : 10) + self::RANK[$h['level']];

        return $score($new) > $score($old);
    }

    /**
     * @return array<int, string>
     */
    private function lines(string $text): array
    {
        $lines = [];

        foreach (explode("\n", $text) as $line) {
            $line = trim(ltrim($line, "-*\u{2022}\u{25cf} \t"));

            if ($line === '') {
                continue;
            }

            if (mb_strlen($line) > 220) {
                foreach (preg_split('/(?<=[.;!?])\s+/u', $line) ?: [] as $sentence) {
                    $lines[] = trim($sentence);
                }

                continue;
            }

            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * @param  array<string, string>  $levels
     */
    private function headingOf(string $line, array $levels): ?string
    {
        if (mb_strlen($line) > 90) {
            return null;
        }

        foreach (['ignored' => 'section_ignored', 'preferred' => 'section_preferred', 'required' => 'section_required', 'duty' => 'section_duty'] as $section => $key) {
            if (preg_match($levels[$key], $line) === 1) {
                return $section;
            }
        }

        return null;
    }

    private function yearsMin(string $text): ?int
    {
        if (preg_match_all('/\b(\d{1,2})\+?\s*(?:-\s*\d{1,2}\s*)?(?:years?|yrs?)\b[^.\n]{0,60}\bexperience\b|\bexperience\b[^.\n]{0,40}\b(\d{1,2})\+?\s*(?:years?|yrs?)\b/i', $text, $m) < 1) {
            return null;
        }

        $values = array_filter(array_map('intval', array_merge($m[1], $m[2])), fn (int $y) => $y > 0 && $y <= 20);

        return $values === [] ? null : min($values);
    }

    private function seniority(string $title, ?int $years): string
    {
        $patterns = (array) config('resume_profiles.seniority');

        return match (true) {
            preg_match($patterns['junior'], $title) === 1 => 'junior',
            preg_match($patterns['staff_plus'], $title) === 1 => 'staff_plus',
            preg_match($patterns['senior'], $title) === 1, $years !== null && $years >= 5 => 'senior',
            $years !== null && $years >= 2 => 'mid',
            default => 'unspecified',
        };
    }

    public function stack(string $text): ?string
    {
        $best = null;
        $bestCount = 1;

        foreach ((array) config('resume_profiles.stacks') as $stack => $pattern) {
            $count = (int) preg_match_all($pattern, $text);

            if ($count > $bestCount) {
                $best = (string) $stack;
                $bestCount = $count;
            }
        }

        return $best;
    }
}
