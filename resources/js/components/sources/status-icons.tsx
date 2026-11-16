import type { ReactNode } from 'react';
import { Badge } from '@/components/ui/badge';
import type { OutcomeMark } from '@/lib/sources';

/**
 * Small inline icons in the Lucide style (the project has no icon package; it draws its few icons inline, as the
 * disclosure and overflow menu do). Decorative on their own: anything that carries meaning is wrapped with a label.
 */
function Icon({
    children,
    className = 'h-4 w-4',
}: {
    children: ReactNode;
    className?: string;
}) {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth={2}
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
            className={className}
        >
            {children}
        </svg>
    );
}

export function CircleCheckIcon() {
    return (
        <Icon>
            <circle cx="12" cy="12" r="10" />
            <path d="m9 12 2 2 4-4" />
        </Icon>
    );
}

/** A success that found nothing: the same circle, with a dash instead of a tick, so the two stay distinguishable. */
export function CircleDashIcon() {
    return (
        <Icon>
            <circle cx="12" cy="12" r="10" />
            <path d="M8 12h8" />
        </Icon>
    );
}

export function InfoIcon() {
    return (
        <Icon className="h-3.5 w-3.5 shrink-0">
            <circle cx="12" cy="12" r="10" />
            <path d="M12 16v-4" />
            <path d="M12 8h.01" />
        </Icon>
    );
}

export function TriangleAlertIcon() {
    return (
        <Icon className="h-3.5 w-3.5 shrink-0">
            <path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3" />
            <path d="M12 9v4" />
            <path d="M12 17h.01" />
        </Icon>
    );
}

export function ChevronRightIcon() {
    return (
        <Icon className="h-3.5 w-3.5 shrink-0">
            <path d="m9 18 6-6-6-6" />
        </Icon>
    );
}

/**
 * How an outcome is shown. A plain success is a quiet icon with an accessible name (and a title as a supplement, never
 * the only explanation); everything else keeps its words, so problems stand out and nothing relies on colour.
 */
export function OutcomeIndicator({
    mark,
    withText = false,
}: {
    mark: OutcomeMark;
    /** Show the word beside a success icon (the drawer, where there is room). */
    withText?: boolean;
}) {
    if (mark.kind === 'badge') {
        return <Badge tone={mark.tone}>{mark.text}</Badge>;
    }

    return (
        <span
            role="img"
            aria-label={mark.label}
            title={mark.label}
            className="text-ok inline-flex items-center gap-1 text-xs"
        >
            {mark.icon === 'success' ? <CircleCheckIcon /> : <CircleDashIcon />}
            {withText && <span aria-hidden="true">{mark.short}</span>}
        </span>
    );
}
