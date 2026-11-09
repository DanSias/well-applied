import type { ComponentProps, ReactNode } from 'react';
import { cn } from '@/lib/utils';

/** The standard elevated work area. */
export function Card({ className, ...props }: ComponentProps<'div'>) {
    return (
        <div
            className={cn(
                'rounded-card border border-line bg-surface shadow-card',
                className,
            )}
            {...props}
        />
    );
}

/** A quiet section heading used across the workspace. */
export function SectionTitle({
    children,
    aside,
    className,
}: {
    children: ReactNode;
    aside?: ReactNode;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1',
                className,
            )}
        >
            <h2 className="text-sm font-semibold tracking-tight text-fg">
                {children}
            </h2>
            {aside && <div className="text-xs text-fg-subtle">{aside}</div>}
        </div>
    );
}

/**
 * Collapsible block on native <details>, so it is keyboard and
 * screen-reader accessible with no extra state. Use for evidence,
 * requirements and technical details that should not crowd the main flow.
 */
export function Disclosure({
    summary,
    children,
    className,
    defaultOpen,
    quiet = false,
}: {
    summary: ReactNode;
    children: ReactNode;
    className?: string;
    defaultOpen?: boolean;
    quiet?: boolean;
}) {
    return (
        <details open={defaultOpen} className={cn('group', className)}>
            <summary
                className={cn(
                    'flex cursor-pointer list-none items-center gap-1.5 rounded-control text-fg-muted select-none hover:text-fg [&::-webkit-details-marker]:hidden',
                    quiet ? 'text-xs' : 'text-sm font-medium',
                )}
            >
                <svg
                    aria-hidden="true"
                    viewBox="0 0 16 16"
                    className="h-3.5 w-3.5 shrink-0 text-fg-subtle transition-transform group-open:rotate-90"
                    fill="none"
                    stroke="currentColor"
                    strokeWidth="1.8"
                    strokeLinecap="round"
                    strokeLinejoin="round"
                >
                    <path d="M6 3.5 10.5 8 6 12.5" />
                </svg>
                <span className="min-w-0">{summary}</span>
            </summary>
            <div className="mt-2 pl-5">{children}</div>
        </details>
    );
}
