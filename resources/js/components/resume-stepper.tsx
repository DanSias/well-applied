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

/** What each step is called on screen; the full label stays in the accessible name. */
const SHORT_LABEL: Record<ResumeStep['key'], string> = {
    analyze: 'Analyze',
    compose: 'Compose',
    validate: 'Validate PDF',
};

/**
 * A compact three-step progress indicator for the tailored-resume run. Presentation only: the steps and their states
 * come from `resumeSteps` (the persisted pipeline status), and the elapsed time is passed in. A title row (with the
 * elapsed time) sits above the connected steps. The layout answers to the width of the container it is placed in, not
 * the viewport (a CSS container query): three steps share one line while there is room, and below that the steps stack
 * vertically, so they never wrap unpredictably. Labels are short on screen; the full step name and its state are read
 * out to assistive technology.
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
        <div className="@container mt-3">
            <div className="border-line bg-sunken rounded-md border px-3 py-2.5">
                <div className="flex items-baseline justify-between gap-x-3">
                    <p className="text-fg-muted text-xs font-medium">
                        Resume generation
                    </p>
                    {elapsed && (
                        <span className="text-fg-subtle text-xs whitespace-nowrap tabular-nums">
                            {elapsed}
                        </span>
                    )}
                </div>
                <ol
                    aria-label="Resume generation progress"
                    className="mt-2 flex items-center gap-x-2 @max-[22rem]:flex-col @max-[22rem]:items-start @max-[22rem]:gap-y-2"
                >
                    {steps.map((step, i) => (
                        <li
                            key={step.key}
                            aria-current={
                                step.state === 'active' ? 'step' : undefined
                            }
                            className={cn(
                                'flex min-w-0 items-center gap-2',
                                i < steps.length - 1 &&
                                    'flex-1 @max-[22rem]:flex-none',
                            )}
                        >
                            <Marker state={step.state} />
                            <span
                                className={cn(
                                    'text-xs font-medium whitespace-nowrap',
                                    step.state === 'pending' &&
                                        'text-fg-subtle',
                                    step.state === 'done' && 'text-fg-muted',
                                    step.state === 'active' && 'text-fg',
                                    step.state === 'failed' && 'text-bad',
                                    step.state === 'interrupted' && 'text-warn',
                                )}
                            >
                                <span aria-hidden="true">
                                    {SHORT_LABEL[step.key] ?? step.label}
                                </span>
                                <span className="sr-only">
                                    {step.label}, {STATE_WORD[step.state]}
                                    {step.note ? `, ${step.note}` : ''}
                                </span>
                            </span>
                            {i < steps.length - 1 && (
                                <span
                                    aria-hidden="true"
                                    className={cn(
                                        'h-px min-w-3 flex-1 @max-[22rem]:hidden',
                                        step.state === 'done'
                                            ? 'bg-ok-line'
                                            : 'bg-line-strong',
                                    )}
                                />
                            )}
                        </li>
                    ))}
                </ol>
            </div>
        </div>
    );
}
