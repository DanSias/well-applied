import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * Status color is used sparingly and always carries a text label:
 *   neutral  informational, no judgement
 *   accent   the active/selected thing (e.g. "Current")
 *   ok       something is good or complete
 *   warn     needs a look, not a failure
 *   bad      a defect or failure
 */
export type Tone = 'neutral' | 'accent' | 'ok' | 'warn' | 'bad';

export const TONE_STYLES: Record<Tone, string> = {
    neutral: 'bg-raised text-fg-muted ring-line-strong',
    accent: 'bg-accent-soft text-accent-text ring-accent/40',
    ok: 'bg-ok-soft text-ok ring-ok-line',
    warn: 'bg-warn-soft text-warn ring-warn-line',
    bad: 'bg-bad-soft text-bad ring-bad-line',
};

export function Badge({
    tone = 'neutral',
    children,
    className,
}: {
    tone?: Tone;
    children: ReactNode;
    className?: string;
}) {
    return (
        <span
            className={cn(
                'inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium whitespace-nowrap ring-1 ring-inset',
                TONE_STYLES[tone],
                className,
            )}
        >
            {children}
        </span>
    );
}
