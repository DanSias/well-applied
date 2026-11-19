import { router } from '@inertiajs/react';
import {
    useCallback,
    useEffect,
    useId,
    useLayoutEffect,
    useRef,
    useState,
} from 'react';
import { createPortal } from 'react-dom';
import { Button } from '@/components/ui/button';
import { FieldError, TextInput } from '@/components/ui/field';
import { computePopoverPosition } from '@/lib/popover-position';
import { cn } from '@/lib/utils';
import { stop } from '@/routes/opportunities';
import type { DismissalReason } from '@/types/job-posting';

/** Why pursuit may end. Not preselected; "Other reason" shares the plain dismissal vocabulary's `other`. */
export const STOP_REASONS: { value: DismissalReason; label: string }[] = [
    { value: 'posting_closed', label: 'Applications closed' },
    { value: 'no_longer_interested', label: 'No longer interested' },
    { value: 'not_a_good_fit', label: 'Not a good fit' },
    { value: 'other', label: 'Other reason' },
];

/**
 * Stop pursuing an opportunity from a compact popover anchored to its trigger, built the way the Jobs page's dismissal
 * popover is: portaled, placed by `computePopoverPosition` (below and right-aligned, flipped or clamped near the
 * viewport edges, following the trigger on scroll and resize). Escape, an outside click and Cancel close it and return
 * focus to the trigger. A reason is optional (none is recorded if none is chosen); nothing happens until the server answers. On success the server
 * redirects to the Opportunities list (the app-wide toast offers Undo); on a refusal or a failed request the popover
 * stays open with the problem shown and the selection kept.
 */
export function StopPursuingPopover({
    jobId,
    company,
}: {
    jobId: number;
    company: string;
}) {
    const [open, setOpen] = useState(false);
    const [reason, setReason] = useState<DismissalReason | null>(null);
    const [note, setNote] = useState('');
    const [busy, setBusy] = useState(false);
    const [problem, setProblem] = useState<string | null>(null);
    const inFlight = useRef(false);
    const trigger = useRef<HTMLButtonElement>(null);
    const panel = useRef<HTMLDivElement>(null);
    const headingId = useId();
    const noteId = useId();

    const close = useCallback((returnFocus = true) => {
        setOpen(false);
        setProblem(null);

        if (returnFocus) {
            trigger.current?.focus();
        }
    }, []);

    const place = useCallback(() => {
        const anchor = trigger.current?.getBoundingClientRect();
        const box = panel.current;

        if (!anchor || !box) {
            return;
        }

        const { width, height } = box.getBoundingClientRect();
        const at = computePopoverPosition(
            anchor,
            { width, height },
            { width: window.innerWidth, height: window.innerHeight },
        );

        box.style.top = `${at.top}px`;
        box.style.left = `${at.left}px`;
        box.style.visibility = 'visible';
    }, []);

    // Re-measure when the content changes size (the note field, an error line).
    useLayoutEffect(() => {
        if (open) {
            place();
        }
    }, [open, reason, problem, place]);

    useEffect(() => {
        if (!open) {
            return;
        }

        panel.current?.querySelector<HTMLElement>('input')?.focus();

        const onPointerDown = (event: PointerEvent) => {
            const target = event.target as Node;

            if (
                !busy &&
                !panel.current?.contains(target) &&
                !trigger.current?.contains(target)
            ) {
                close(false);
            }
        };

        document.addEventListener('pointerdown', onPointerDown);
        window.addEventListener('scroll', place, true);
        window.addEventListener('resize', place);

        return () => {
            document.removeEventListener('pointerdown', onPointerDown);
            window.removeEventListener('scroll', place, true);
            window.removeEventListener('resize', place);
        };
    }, [open, busy, close, place]);

    const confirm = () => {
        if (inFlight.current) {
            return;
        }

        inFlight.current = true;
        setBusy(true);
        setProblem(null);
        router.post(
            stop.url({ jobPosting: jobId }),
            {
                reason: reason ?? '',
                note: reason === 'other' ? note.trim() : '',
            },
            {
                showProgress: false,
                onError: (errors) =>
                    setProblem(
                        Object.values(errors)[0] ?? "Couldn't save that.",
                    ),
                onNetworkError: () =>
                    setProblem(
                        "Couldn't reach the server. Nothing was changed.",
                    ),
                onFinish: () => {
                    inFlight.current = false;
                    setBusy(false);
                },
            },
        );
    };

    return (
        <>
            <Button
                ref={trigger}
                variant="ghost"
                size="sm"
                aria-haspopup="dialog"
                aria-expanded={open}
                className={cn(
                    'text-fg-muted hover:bg-bad-soft hover:text-bad focus-visible:bg-bad-soft focus-visible:text-bad focus-visible:ring-bad-line cursor-pointer gap-1.5 focus-visible:ring-2',
                    open && 'bg-bad-soft text-bad',
                )}
                onClick={() => (open ? close() : setOpen(true))}
            >
                <svg
                    aria-hidden="true"
                    viewBox="0 0 24 24"
                    className="h-3.5 w-3.5"
                    fill="none"
                    stroke="currentColor"
                    strokeWidth="2"
                    strokeLinecap="round"
                    strokeLinejoin="round"
                >
                    {/* Lucide circle-x */}
                    <circle cx="12" cy="12" r="10" />
                    <path d="m15 9-6 6" />
                    <path d="m9 9 6 6" />
                </svg>
                Stop pursuing
            </Button>
            {open &&
                createPortal(
                    <div
                        ref={panel}
                        role="dialog"
                        aria-labelledby={headingId}
                        style={{ visibility: 'hidden' }}
                        onKeyDown={(event) => {
                            if (event.key === 'Escape' && !busy) {
                                event.preventDefault();
                                event.stopPropagation();
                                close();
                            }
                        }}
                        className="border-line-strong bg-raised shadow-card rounded-card fixed top-0 left-0 z-50 w-[22rem] max-w-[calc(100vw-1rem)] border p-4"
                    >
                        <form
                            className="space-y-3"
                            onSubmit={(event) => {
                                event.preventDefault();
                                confirm();
                            }}
                        >
                            <div>
                                <h2
                                    id={headingId}
                                    className="text-fg text-sm font-semibold"
                                >
                                    Stop pursuing?
                                </h2>
                                <p className="text-fg-muted mt-1 text-xs leading-relaxed">
                                    Remove this opportunity from your active
                                    list. Your history will be preserved, and
                                    you can undo this.
                                </p>
                            </div>

                            <fieldset className="space-y-1" disabled={busy}>
                                <legend className="text-fg-muted mb-1 text-xs font-medium">
                                    Reason (optional)
                                </legend>
                                {STOP_REASONS.map((option) => (
                                    <label
                                        key={option.value}
                                        className="text-fg flex cursor-pointer items-center gap-2 text-sm"
                                    >
                                        <input
                                            type="radio"
                                            name={`stop-reason-${jobId}`}
                                            value={option.value}
                                            checked={reason === option.value}
                                            onChange={() =>
                                                setReason(option.value)
                                            }
                                            className="accent-accent focus-visible:ring-focus size-4 focus-visible:ring-2 focus-visible:outline-none"
                                        />
                                        {option.label}
                                    </label>
                                ))}
                            </fieldset>

                            {reason === 'other' && (
                                <div>
                                    <label
                                        htmlFor={noteId}
                                        className="text-fg-muted mb-1 block text-xs"
                                    >
                                        Add a short note (optional)
                                    </label>
                                    <TextInput
                                        id={noteId}
                                        value={note}
                                        disabled={busy}
                                        onChange={(event) =>
                                            setNote(event.target.value)
                                        }
                                        maxLength={500}
                                        className="h-8 py-1 text-xs"
                                    />
                                </div>
                            )}

                            {problem !== null && (
                                <FieldError>{problem}</FieldError>
                            )}

                            <div className="flex items-center justify-end gap-2">
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    disabled={busy}
                                    onClick={() => close()}
                                >
                                    Cancel
                                </Button>
                                <Button
                                    type="submit"
                                    variant="primary"
                                    size="sm"
                                    disabled={busy}
                                    aria-busy={busy || undefined}
                                    aria-label={`Stop pursuing ${company}`}
                                >
                                    Stop pursuing
                                </Button>
                            </div>
                        </form>
                    </div>,
                    document.body,
                )}
        </>
    );
}
