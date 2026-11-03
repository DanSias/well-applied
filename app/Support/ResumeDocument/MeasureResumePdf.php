<?php

namespace App\Support\ResumeDocument;

use Symfony\Component\Process\Process;
use Throwable;

/**
 * Measures where content ends on the last page of an already rendered
 * PDF, using poppler's `pdftotext -bbox` when it is installed. Returns
 * null (not a guess) when the tool is unavailable or fails: page count,
 * which drives every layout decision, never depends on this.
 *
 * Geometry matches print.blade.php: US Letter (792pt), 0.75in bottom
 * padding, and a top inset of 0.75in on page 1 or 0.4in on later pages.
 */
final class MeasureResumePdf
{
    private const PAGE_HEIGHT = 792.0;

    private const BOTTOM_INSET = 54.0;

    private const FIRST_PAGE_TOP_INSET = 54.0;

    private const LATER_PAGE_TOP_INSET = 28.8;

    /**
     * @return array{fill: float, remaining_points: float}|null
     */
    public function lastPage(string $pdfBytes, int $pageCount): ?array
    {
        try {
            $process = new Process(['pdftotext', '-bbox', '-f', (string) $pageCount, '-l', (string) $pageCount, '-', '-']);
            $process->setInput($pdfBytes)->setTimeout(15)->run();
        } catch (Throwable) {
            return null;
        }

        if (! $process->isSuccessful()) {
            return null;
        }

        preg_match_all('/yMax="([0-9.]+)"/', $process->getOutput(), $matches);

        if ($matches[1] === []) {
            return null;
        }

        $bottom = max(array_map('floatval', $matches[1]));
        $top = $pageCount === 1 ? self::FIRST_PAGE_TOP_INSET : self::LATER_PAGE_TOP_INSET;
        $usableBottom = self::PAGE_HEIGHT - self::BOTTOM_INSET;

        return [
            'fill' => round(max(0.0, min(1.0, ($bottom - $top) / ($usableBottom - $top))), 3),
            'remaining_points' => round(max(0.0, $usableBottom - $bottom), 1),
        ];
    }
}
