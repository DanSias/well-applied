import type { ReactNode } from 'react';
import { Badge, type Tone } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

/**
 * How a step of the preparation workflow stands, as a marker. It is derived from the tone of the status that already
 * labels the step (never a second opinion about it), and the marker is paired with that text label, so color is never
 * the only signal:
 *   done        a finished step              (ok)
 *   active      work is under way right now  (accent, spinning)
 *   progress    started and unfinished, but nothing is running: a draft resume exists (accent, still)
 *   attention   needs a look, not a failure  (warn), e.g. an unsupported ATS or a held run
 *   failed      a step that failed           (bad)
 *   pending     not started                  (neutral)
 */
export type StepStatus =
    | 'done'
    | 'active'
    | 'progress'
    | 'attention'
    | 'failed'
    | 'pending';

export function stepStatusFromTone(
    tone: Tone,
    /** What an accent tone means for this step: work running now (the default), or an unfinished draft. */
    accent: 'active' | 'progress' = 'active',
): StepStatus {
    return (
        {
            ok: 'done',
            accent,
            warn: 'attention',
            bad: 'failed',
            neutral: 'pending',
        } as const
    )[tone];
}

const WORD: Record<StepStatus, string> = {
    done: 'Completed',
    active: 'In progress',
    progress: 'In progress',
    attention: 'Needs attention',
    failed: 'Failed',
    pending: 'Not started',
};

function StepMarker({ status }: { status: StepStatus }) {
    const base =
        'flex size-6 shrink-0 items-center justify-center rounded-full text-xs font-semibold ring-1';

    return (
        <span aria-hidden="true" className="contents">
            {status === 'done' && (
                <span className={cn(base, 'bg-ok-soft text-ok ring-ok-line')}>
                    <svg
                        viewBox="0 0 16 16"
                        className="size-3.5"
                        fill="none"
                        stroke="currentColor"
                        strokeWidth="2.5"
                        strokeLinecap="round"
                        strokeLinejoin="round"
                    >
                        <path d="M3.5 8.5l3 3 6-7" />
                    </svg>
                </span>
            )}
            {status === 'active' && (
                <span className={cn(base, 'bg-accent-soft ring-accent/50')}>
                    <span className="border-accent-text size-3 animate-spin rounded-full border-2 border-t-transparent motion-reduce:animate-none" />
                </span>
            )}
            {status === 'progress' && (
                <span className={cn(base, 'bg-accent-soft ring-accent/50')}>
                    <span className="bg-accent-text size-2 rounded-full" />
                </span>
            )}
            {status === 'attention' && (
                <span
                    className={cn(
                        base,
                        'bg-warn-soft text-warn ring-warn-line',
                    )}
                >
                    !
                </span>
            )}
            {status === 'failed' && (
                <span
                    className={cn(base, 'bg-bad-soft text-bad ring-bad-line')}
                >
                    ✕
                </span>
            )}
            {status === 'pending' && (
                <span className={cn(base, 'ring-line-strong text-fg-subtle')}>
                    <span className="bg-line-strong size-1.5 rounded-full" />
                </span>
            )}
        </span>
    );
}

/**
 * One step of the Opportunity's preparation workflow: a status marker, a title, a status badge, and a compact body.
 * Steps are siblings in one card, not a forced sequence: each keeps its own actions.
 */
export function WorkflowStep({
    id,
    title,
    status,
    badge,
    prominent = false,
    children,
}: {
    id: string;
    title: string;
    status: StepStatus;
    badge: ReactNode;
    /** The main step of the card (the resume) gets a larger title. */
    prominent?: boolean;
    children: ReactNode;
}) {
    return (
        <section
            id={id}
            aria-labelledby={`${id}-title`}
            className="grid scroll-mt-4 grid-cols-[1.5rem_minmax(0,1fr)] gap-x-3 px-4 py-3.5 sm:px-5"
        >
            <div className="pt-px">
                <StepMarker status={status} />
            </div>
            <div className="min-w-0">
                <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
                    <h2
                        id={`${id}-title`}
                        tabIndex={-1}
                        className={cn(
                            'text-fg font-semibold tracking-tight focus:outline-none',
                            prominent ? 'text-base' : 'text-sm',
                        )}
                    >
                        {title}
                        <span className="sr-only">: {WORD[status]}</span>
                    </h2>
                    {badge}
                </div>
                <div className="mt-1.5">{children}</div>
            </div>
        </section>
    );
}

/** A step's status as a badge, for steps whose label is a plain string. */
export function StepBadge({ tone, label }: { tone: Tone; label: string }) {
    return <Badge tone={tone}>{label}</Badge>;
}
