import { cn } from '@/lib/utils';
import { Button } from './button';

/** 1 … 4 [5] 6 … 12: always the ends and the current neighbourhood. */
function windowed(page: number, last: number): (number | 'gap')[] {
    const wanted = new Set([1, last, page - 1, page, page + 1]);
    const pages = [...wanted].filter((p) => p >= 1 && p <= last).sort((a, b) => a - b);
    const out: (number | 'gap')[] = [];

    pages.forEach((p, i) => {
        if (i > 0 && p - pages[i - 1] > 1) {
            out.push('gap');
        }

        out.push(p);
    });

    return out;
}

export function Pagination({
    page,
    lastPage,
    onPage,
    className,
}: {
    page: number;
    lastPage: number;
    onPage: (page: number) => void;
    className?: string;
}) {
    if (lastPage <= 1) {
        return null;
    }

    return (
        <nav
            aria-label="Pagination"
            className={cn('flex flex-wrap items-center gap-1', className)}
        >
            <Button
                variant="ghost"
                size="sm"
                disabled={page <= 1}
                onClick={() => onPage(page - 1)}
            >
                ← Previous
            </Button>
            {windowed(page, lastPage).map((p, i) =>
                p === 'gap' ? (
                    <span key={`gap-${i}`} aria-hidden="true" className="px-1 text-fg-subtle">
                        …
                    </span>
                ) : (
                    <Button
                        key={p}
                        variant={p === page ? 'secondary' : 'ghost'}
                        size="sm"
                        aria-current={p === page ? 'page' : undefined}
                        aria-label={`Page ${p}`}
                        className={cn('min-w-8 tabular-nums', p === page && 'text-fg')}
                        onClick={() => p !== page && onPage(p)}
                    >
                        {p}
                    </Button>
                ),
            )}
            <Button
                variant="ghost"
                size="sm"
                disabled={page >= lastPage}
                onClick={() => onPage(page + 1)}
            >
                Next →
            </Button>
        </nav>
    );
}
