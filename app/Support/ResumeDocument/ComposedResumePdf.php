<?php

namespace App\Support\ResumeDocument;

/**
 * The outcome of a measured PDF composition: the bytes plus what was
 * actually rendered and measured to produce them. `lastPageFill` is the
 * fraction of the last page's usable height occupied by content, or
 * null when no measurement tool was available; it is a diagnostic only
 * and never a quality target.
 */
final readonly class ComposedResumePdf
{
    /**
     * @param  'standard'|'compact'|'trimmed'  $mode
     * @param  array<int, array{density: string, blocks_removed: int, pages: int, ms: int}>  $renders
     * @param  array<int, array{employer: string, title: string, bullets: int, by_page: array<int, int>}>|null  $placement  where each role's bullets landed (BulletPlacement)
     * @param  array<string, mixed>|null  $quality  the layout judgment (LayoutQuality), from the measurement alone
     */
    public function __construct(
        public string $pdf,
        public int $pageCount,
        public string $mode,
        public int $blocksRemoved,
        public int $renderMs,
        public array $renders,
        public ?float $lastPageFill,
        public ?float $lastPageRemainingPoints,
        public ?array $placement = null,
        public ?array $quality = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function diagnostics(): array
    {
        return [
            'mode' => $this->mode,
            'pages' => $this->pageCount,
            'blocks_removed' => $this->blocksRemoved,
            'render_count' => count($this->renders),
            'renders' => $this->renders,
            'last_page_fill' => $this->lastPageFill,
            'last_page_remaining_points' => $this->lastPageRemainingPoints,
            'placement' => $this->placement,
            'quality' => $this->quality,
        ];
    }
}
