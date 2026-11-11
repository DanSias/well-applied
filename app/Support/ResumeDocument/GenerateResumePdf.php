<?php

namespace App\Support\ResumeDocument;

use App\Models\ResumeVariant;
use HeadlessChromium\BrowserFactory;
use Smalot\PdfParser\Parser as PdfParser;
use Throwable;

/**
 * Renders one ResumeVariant to PDF bytes via headless Chromium
 * (chrome-php/chrome) — the exact same GenerateResumeDocument +
 * resources/views/resume/print.blade.php path the browser preview
 * uses, never a second PDF-specific template. No provider/inference
 * call, no live Employer/Role/Skill/Education re-resolution beyond
 * what GenerateResumeDocument itself already does, no network
 * dependency: the rendered HTML is handed to Chromium in-process via
 * Page::setHtml(), never an HTTP round trip to this app's own preview
 * route.
 *
 * Page geometry (US Letter, zero page margin) is set to match
 * print.blade.php's own `@page` rule exactly — see that file's
 * docblock for why this is the one physical-geometry source of truth
 * and why the margins here are 0, not a second, independent margin.
 * `displayHeaderFooter` is explicitly false so Chromium never injects
 * its own default title/URL/page-number header-footer.
 *
 * A fresh headless Chromium process is launched per call and closed
 * before returning — appropriate for a local, single-operator tool;
 * no persistent browser pool. `chrome-php/chrome`'s own AutoDiscover
 * finds the installed Chrome/Chromium binary (honoring a `CHROME_PATH`
 * environment variable override, e.g. for a Linux/CI environment
 * without the macOS default path) — no Career Toolkit-specific
 * configuration was added for this, since the library already covers
 * it. See docs/resume-variant-generation.md "PDF export".
 */
final class GenerateResumePdf
{
    private const PAPER_WIDTH_INCHES = 8.5;

    private const PAPER_HEIGHT_INCHES = 11.0;

    public function __construct(
        private readonly GenerateResumeDocument $documentGenerator,
    ) {}

    /**
     * Upper bound on Chromium renders for one composition: the standard
     * render, an optional compact trial, and a binary search over how
     * many optional blocks to drop. Never exceeded.
     */
    public const MAX_RENDERS = 8;

    public function generate(ResumeVariant $variant): string
    {
        return $this->compose($variant)->pdf;
    }

    /**
     * Fit-aware, measured composition. Content is never rewritten,
     * padded, or invented, and the stored variant is never modified:
     *
     * 1. Render at standard density and count the real pages.
     * 2. One page: done. Two pages: also try compact density, kept only
     *    if it yields one page (an intentional one-pager); otherwise the
     *    standard two-page render stands, however full page two is.
     * 3. More than two pages: binary-search the smallest number of
     *    lowest-priority optional blocks to drop (see
     *    ResumeDocument::withoutLowestPriorityBlock()) that fits two
     *    pages, within MAX_RENDERS. If nothing optional remains, or the
     *    bound is reached, the over-length PDF is returned and
     *    ResumePdfValidator rejects it. A third page is never accepted.
     *
     * Growing an underfilled resume from a pool of optional ranked
     * content is deliberately not done here: the stored variant has no
     * such pool yet (see docs/resume-variant-generation.md).
     */
    public function compose(ResumeVariant $variant): ComposedResumePdf
    {
        $original = $this->documentGenerator->generate($variant);
        $renders = [];

        $render = function (ResumeDocument $document, string $density, int $removed) use (&$renders): array {
            $start = hrtime(true);
            $pdf = $this->render($document, $density);
            $pages = $this->pageCount($pdf);
            $renders[] = [
                'density' => $density,
                'blocks_removed' => $removed,
                'pages' => $pages,
                'ms' => (int) round((hrtime(true) - $start) / 1_000_000),
            ];

            return [$pdf, $pages];
        };

        [$pdf, $pages] = $render($original, 'standard', 0);
        $mode = 'standard';
        $removed = 0;

        if ($pages === 2) {
            [$compactPdf, $compactPages] = $render($original, 'compact', 0);

            if ($compactPages === 1) {
                [$pdf, $pages, $mode] = [$compactPdf, 1, 'compact'];
            }
        } elseif ($pages > 2) {
            $removable = $this->removableBlocks($original);
            $low = 0;
            $high = $removable;
            $best = null;

            while ($low < $high && count($renders) < self::MAX_RENDERS) {
                $mid = intdiv($low + $high, 2);
                [$candidatePdf, $candidatePages] = $render($this->dropBlocks($original, $mid), 'standard', $mid);

                if ($candidatePages <= 2) {
                    $best = [$candidatePdf, $candidatePages, $mid];
                    $high = $mid;
                } else {
                    $low = $mid + 1;
                }
            }

            if ($best === null && $removable > 0 && count($renders) < self::MAX_RENDERS) {
                [$allPdf, $allPages] = $render($this->dropBlocks($original, $removable), 'standard', $removable);

                if ($allPages <= 2) {
                    $best = [$allPdf, $allPages, $removable];
                } else {
                    // Even with every optional block gone it does not fit; report that render.
                    [$pdf, $pages, $removed, $mode] = [$allPdf, $allPages, $removable, 'trimmed'];
                }
            }

            if ($best !== null) {
                [$pdf, $pages, $removed] = $best;
                $mode = $removed > 0 ? 'trimmed' : 'standard';
            }
        }

        $measured = (new MeasureResumePdf)->lastPage($pdf, $pages);
        // Judged from what was just measured; no further render, and nothing about the PDF or the document changes.
        $placement = $pages > 1 ? (new BulletPlacement)->of($removed > 0 ? $this->dropBlocks($original, $removed) : $original, $pdf) : null;
        $quality = (new LayoutQuality)->assess($pages, $measured['fill'] ?? null, $placement);

        return new ComposedResumePdf(
            pdf: $pdf,
            pageCount: $pages,
            mode: $mode,
            blocksRemoved: $removed,
            renderMs: array_sum(array_column($renders, 'ms')),
            renders: $renders,
            lastPageFill: $measured['fill'] ?? null,
            lastPageRemainingPoints: $measured['remaining_points'] ?? null,
            placement: $placement,
            quality: $quality,
        );
    }

    private function removableBlocks(ResumeDocument $document): int
    {
        $count = 0;

        while (($document = $document->withoutLowestPriorityBlock()) !== null) {
            $count++;
        }

        return $count;
    }

    private function dropBlocks(ResumeDocument $document, int $count): ResumeDocument
    {
        for ($i = 0; $i < $count; $i++) {
            $document = $document->withoutLowestPriorityBlock() ?? $document;
        }

        return $document;
    }

    private function pageCount(string $pdfBytes): int
    {
        try {
            return count((new PdfParser)->parseContent($pdfBytes)->getPages());
        } catch (Throwable) {
            // Unparseable output is ResumePdfValidator's job to reject;
            // report a count that never triggers a fit adjustment.
            return 1;
        }
    }

    private function render(ResumeDocument $document, string $density): string
    {
        $html = view('resume.print', [
            'document' => $document,
            'density' => $density,
        ])->render();

        $browser = (new BrowserFactory)->createBrowser(['headless' => true]);

        try {
            $page = $browser->createPage();
            $page->setHtml($html);

            $pdf = $page->pdf([
                'printBackground' => true,
                'displayHeaderFooter' => false,
                'paperWidth' => self::PAPER_WIDTH_INCHES,
                'paperHeight' => self::PAPER_HEIGHT_INCHES,
                'marginTop' => 0,
                'marginBottom' => 0,
                'marginLeft' => 0,
                'marginRight' => 0,
                'preferCSSPageSize' => true,
            ]);

            $bytes = base64_decode($pdf->getBase64(), true);

            return $bytes === false ? '' : $bytes;
        } finally {
            $browser->close();
        }
    }
}
