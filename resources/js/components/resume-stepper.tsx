import { cn } from '@/lib/utils';
import type { ResumeStep, StepState } from '@/lib/resume-stepper';

const STATE_WORD: Record<StepState, string> = {
    done: 'completed',
    active: 'in progress',
    pending: 'not started',
    failed: 'failed',
    interrupted: 'interrupted',
};

function Marker({ state }: { state: StepState }) {
    const base =
        'flex size-6 shrink-0 items-center justify-center rounded-full text-xs font-semibold';

    if (state === 'done') {
        return (
            <span
                className={cn(base, 'bg-ok-soft text-ok ring-ok-line ring-1')}
            >
                <svg
                    aria-hidden="true"
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
        );
    }

    if (state === 'active') {
        return (
            <span className={cn(base, 'bg-accent-soft ring-accent/50 ring-1')}>
                <span
                    aria-hidden="true"
                    className="border-accent-text size-3 animate-spin rounded-full border-2 border-t-transparent motion-reduce:animate-none"
                />
            </span>
        );
    }

    if (state === 'failed' || state === 'interrupted') {
        return (
            <span
                aria-hidden="true"
                className={cn(
                    base,
                    state === 'failed'
                        ? 'bg-bad-soft text-bad ring-bad-line ring-1'
                        : 'bg-warn-soft text-warn ring-warn-line ring-1',
                )}
            >
                {state === 'failed' ? '✕' : '‖'}
            </span>
        );
    }

    return (
        <span
            aria-hidden="true"
            className={cn(base, 'ring-line-strong text-fg-subtle ring-1')}
        >
            <span className="bg-line-strong size-1.5 rounded-full" />
        </span>
    );
}

/**
 * A compact horizontal three-step progress indicator for the tailored-resume run. Presentation only: the steps and their
 * states come from `resumeSteps` (the persisted pipeline status), and the elapsed time is passed in. On a narrow screen
 * the labels stay beside their markers and the row wraps rather than overflowing.
 */
export function ResumeStepper({
    steps,
    elapsed,
}: {
    steps: ResumeStep[];
    /** Already formatted, e.g. "1:05 elapsed". */
    elapsed?: string | null;
}) {
    if (steps.length === 0) {
        return null;
    }

    return (
        <div className="border-line bg-sunken mt-3 flex flex-wrap items-center justify-between gap-x-4 gap-y-2 rounded-md border px-3 py-2.5">
            <ol
                aria-label="Resume generation progress"
                className="flex min-w-0 flex-1 flex-wrap items-center gap-x-2 gap-y-2"
            >
                {steps.map((step, i) => (
                    <li
                        key={step.key}
                        aria-current={
                            step.state === 'active' ? 'step' : undefined
                        }
                        className={cn(
                            'flex min-w-0 items-center gap-2',
                            i < steps.length - 1 && 'flex-1 basis-auto',
                        )}
                    >
                        <Marker state={step.state} />
                        <span
                            className={cn(
                                'text-xs font-medium whitespace-nowrap',
                                step.state === 'pending' && 'text-fg-subtle',
                                step.state === 'done' && 'text-fg-muted',
                                step.state === 'active' && 'text-fg',
                                step.state === 'failed' && 'text-bad',
                                step.state === 'interrupted' && 'text-warn',
                            )}
                        >
                            {step.label}
                            <span className="sr-only">
                                , {STATE_WORD[step.state]}
                                {step.note ? `, ${step.note}` : ''}
                            </span>
                        </span>
                        {i < steps.length - 1 && (
                            <span
                                aria-hidden="true"
                                className={cn(
                                    'hidden h-px min-w-4 flex-1 sm:block',
                                    step.state === 'done'
                                        ? 'bg-ok-line'
                                        : 'bg-line-strong',
                                )}
                            />
                        )}
                    </li>
                ))}
            </ol>
            {elapsed && (
                <span className="text-fg-subtle text-xs tabular-nums">
                    {elapsed}
                </span>
            )}
        </div>
    );
}
