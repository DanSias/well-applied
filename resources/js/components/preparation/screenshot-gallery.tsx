import { useState } from 'react';
import { Button } from '@/components/ui/button';
import type { ReviewScreenshot } from '@/types/application-preparation';

const PHASES: Record<string, string> = {
    before: 'Before filling',
    after_fill: 'After filling',
    end_of_hold: 'End of session',
};

export const phaseTitle = (phase: string | null): string =>
    (phase !== null ? PHASES[phase] : undefined) ?? 'Screenshot';

/**
 * The screenshots in the order they were taken, one large view with previous/next and a strip of numbered thumbnails.
 * Each is a viewport-sized slice of the page at a scroll position, so together they read top to bottom. Images load from
 * the attempt's own private route; a slice that was never stored is listed as missing rather than hidden.
 */
export function ScreenshotGallery({
    screenshots,
}: {
    screenshots: ReviewScreenshot[];
}) {
    const [index, setIndex] = useState(0);

    if (screenshots.length === 0) {
        return (
            <p className="text-fg-subtle text-sm">
                No screenshots were kept for this attempt.
            </p>
        );
    }

    const current = screenshots[Math.min(index, screenshots.length - 1)]!;
    const label = (s: ReviewScreenshot) =>
        `${phaseTitle(s.phase)}${s.index !== null && s.of !== null && s.of > 1 ? `, part ${s.index} of ${s.of}` : ''}`;

    return (
        <div>
            <figure>
                <div className="border-line bg-raised overflow-hidden rounded-md border">
                    {current.stored && current.url !== null ? (
                        <img
                            src={current.url}
                            alt={`Screenshot: ${label(current)}`}
                            loading="lazy"
                            className="block h-auto w-full"
                        />
                    ) : (
                        <p className="text-fg-subtle p-6 text-center text-sm">
                            This screenshot was not kept.
                        </p>
                    )}
                </div>
                <figcaption className="text-fg-muted mt-1.5 flex items-center justify-between gap-2 text-xs">
                    <span>
                        {label(current)} ·{' '}
                        {Math.min(index, screenshots.length - 1) + 1} of{' '}
                        {screenshots.length}
                    </span>
                    <span className="flex gap-1">
                        <Button
                            size="sm"
                            variant="ghost"
                            disabled={index === 0}
                            onClick={() => setIndex((i) => Math.max(0, i - 1))}
                            aria-label="Previous screenshot"
                        >
                            ‹ Previous
                        </Button>
                        <Button
                            size="sm"
                            variant="ghost"
                            disabled={index >= screenshots.length - 1}
                            onClick={() =>
                                setIndex((i) =>
                                    Math.min(screenshots.length - 1, i + 1),
                                )
                            }
                            aria-label="Next screenshot"
                        >
                            Next ›
                        </Button>
                    </span>
                </figcaption>
            </figure>
            <ol
                className="mt-2 flex flex-wrap gap-1.5"
                aria-label="Screenshots in order"
            >
                {screenshots.map((s, i) => (
                    <li key={s.file}>
                        <button
                            type="button"
                            onClick={() => setIndex(i)}
                            aria-current={i === index ? 'true' : undefined}
                            aria-label={`Show ${label(s)}`}
                            className={
                                'rounded-control focus-visible:ring-focus h-7 min-w-7 px-1.5 text-xs ring-1 ring-inset focus-visible:ring-2 focus-visible:outline-none ' +
                                (i === index
                                    ? 'bg-accent-soft text-accent-text ring-accent/40'
                                    : s.stored
                                      ? 'bg-raised text-fg-muted ring-line-strong hover:bg-hover'
                                      : 'bg-raised text-fg-subtle ring-line-strong line-through')
                            }
                        >
                            {i + 1}
                        </button>
                    </li>
                ))}
            </ol>
        </div>
    );
}
