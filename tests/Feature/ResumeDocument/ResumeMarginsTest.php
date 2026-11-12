<?php

use App\Support\ResumeDocument\GenerateResumePdf;
use App\Support\ResumeDocument\MeasureResumePdf;
use Symfony\Component\Process\Process;
use Tests\Support\PearlyResumeVariantFixture;

/**
 * No text closer than half an inch to any edge of any page, measured on a real render, and a text layer that still reads
 * in order for an ATS. The audit found page 1 running to within 0.14in of the bottom edge and continuation pages starting
 * at 0.42in; fonts and margins are never shrunk to fit more.
 */
function pdftotext(string $pdf, array $args): ?string
{
    $process = new Process(['pdftotext', ...$args, '-', '-']);

    try {
        $process->setInput($pdf)->setTimeout(20)->run();
    } catch (Throwable) {
        return null;
    }

    return $process->isSuccessful() ? $process->getOutput() : null;
}

it('keeps every page at least half an inch from every edge', function () {
    $pdf = app(GenerateResumePdf::class)->generate(PearlyResumeVariantFixture::generate());
    $boxes = pdftotext($pdf, ['-bbox']);

    if ($boxes === null) {
        $this->markTestSkipped('pdftotext is not installed.');
    }

    preg_match_all('/<page width="([\d.]+)" height="([\d.]+)">(.*?)<\/page>/s', $boxes, $pages, PREG_SET_ORDER);
    expect(count($pages))->toBeGreaterThanOrEqual(2);

    foreach ($pages as $i => [, $width, $height, $words]) {
        preg_match_all('/xMin="([\d.]+)" yMin="([\d.]+)" xMax="([\d.]+)" yMax="([\d.]+)"/', $words, $m);
        $inch = 72.0;

        expect(min($m[1]) / $inch)->toBeGreaterThanOrEqual(0.5, 'left, page '.($i + 1))
            ->and(($width - max($m[3])) / $inch)->toBeGreaterThanOrEqual(0.5, 'right, page '.($i + 1))
            ->and(min($m[2]) / $inch)->toBeGreaterThanOrEqual(0.5, 'top, page '.($i + 1))
            ->and(($height - max($m[4])) / $inch)->toBeGreaterThanOrEqual(0.5, 'bottom, page '.($i + 1));
    }
});

it('measures the last page against the same margins the page is rendered with', function () {
    $pdf = app(GenerateResumePdf::class)->generate(PearlyResumeVariantFixture::generate());
    $pages = pdftotext($pdf, ['-bbox']) === null ? null : preg_match_all('/<page /', pdftotext($pdf, ['-bbox']));
    $measured = $pages === null ? null : (new MeasureResumePdf)->lastPage($pdf, $pages);

    if ($measured === null) {
        $this->markTestSkipped('pdftotext is not installed.');
    }

    // The content of a full page ends at 0.5in from the bottom, which is a fill of 1.0 and no more.
    expect($measured['fill'])->toBeGreaterThan(0.0)->toBeLessThanOrEqual(1.0)->and($measured['remaining_points'])->toBeGreaterThanOrEqual(0.0);
    expect(config('resume.layout_quality.later_page_points'))->toBe(792.0 - 36.0 - 36.0);
});

it('still extracts as readable text in reading order', function () {
    $pdf = app(GenerateResumePdf::class)->generate(PearlyResumeVariantFixture::generate());
    $text = pdftotext($pdf, ['-layout']);

    if ($text === null) {
        $this->markTestSkipped('pdftotext is not installed.');
    }

    $summary = stripos($text, 'Technical Skills');
    $experience = stripos($text, 'Professional Experience');
    $education = stripos($text, 'Education');

    expect($summary)->not->toBeFalse()->and($experience)->toBeGreaterThan($summary)->and($education)->toBeGreaterThan($experience)
        // No bullet is cut mid-word by a page break.
        ->and($text)->not->toMatch('/\w-\n\s*\w/');
});
