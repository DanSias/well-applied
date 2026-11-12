<?php

namespace Tests\Support;

use App\Models\ResumeVariant;
use App\Support\ResumeDocument\ComposedResumePdf;
use App\Support\ResumeDocument\GenerateResumeDocument;
use App\Support\ResumeDocument\GenerateResumePdf;

/**
 * A renderer whose measurements a test decides. Each compose() returns the next scripted layout (the last repeats) with a
 * real, valid PDF as its bytes, so everything downstream (validation, the PDF cache, the layout judgment) runs for real.
 * Chromium renders the payload once per process (from the first variant composed).
 *
 * A step is ['pages' => int, 'fill' => float|null, 'mode' => 'standard'|'trimmed', 'placement' => array|null].
 */
final class ScriptedPdfRenderer extends GenerateResumePdf
{
    public int $calls = 0;

    private static ?string $pdf = null;

    /**
     * @param  array<int, array<string, mixed>>  $script
     */
    public function __construct(GenerateResumeDocument $documents, private array $script)
    {
        parent::__construct($documents);
    }

    public function compose(ResumeVariant $variant): ComposedResumePdf
    {
        $step = $this->script[min($this->calls, count($this->script) - 1)];
        $this->calls++;
        self::$pdf ??= (new GenerateResumePdf(app(GenerateResumeDocument::class)))->generate($variant);

        return new ComposedResumePdf(
            pdf: self::$pdf, pageCount: $step['pages'], mode: $step['mode'] ?? 'standard', blocksRemoved: ($step['mode'] ?? 'standard') === 'trimmed' ? 1 : 0, renderMs: 5,
            renders: [], lastPageFill: $step['fill'], lastPageRemainingPoints: 100.0, placement: $step['placement'] ?? null,
        );
    }
}
