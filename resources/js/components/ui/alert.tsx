import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';
import type { Tone } from './badge';

const STYLES: Record<Tone, string> = {
    neutral: 'border-line bg-surface',
    accent: 'border-accent/40 bg-accent-soft',
    ok: 'border-ok-line bg-ok-soft',
    warn: 'border-warn-line bg-warn-soft',
    bad: 'border-bad-line bg-bad-soft',
};

const BAR: Record<Tone, string> = {
    neutral: 'bg-fg-subtle',
    accent: 'bg-accent',
    ok: 'bg-ok',
    warn: 'bg-warn',
    bad: 'bg-bad',
};

/** A callout with a thin status stripe; body text stays high-contrast. */
export function Alert({
    tone = 'neutral',
    children,
    className,
    role,
}: {
    tone?: Tone;
    children: ReactNode;
    className?: string;
    role?: 'alert' | 'status';
}) {
    return (
        <div
            role={role}
            className={cn(
                'relative overflow-hidden rounded-control border py-3 pr-4 pl-5 text-sm leading-relaxed text-fg',
                STYLES[tone],
                className,
            )}
        >
            <span
                aria-hidden="true"
                className={cn('absolute inset-y-0 left-0 w-1', BAR[tone])}
            />
            {children}
        </div>
    );
}
