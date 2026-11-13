import { useCallback, useEffect, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Button } from '@/components/ui/button';
import { TextInput } from '@/components/ui/field';
import { DISMISSAL_REASONS, type DecideFn } from '@/components/job-decisions';
import { computePopoverPosition, dismissalPayload } from '@/lib/popover-position';
import { cn } from '@/lib/utils';
import type { DismissalReason } from '@/types/job-posting';

/**
 * Dismiss one job from a small popover anchored to an X button, instead of an expanding panel under the row.
 *
 * Common path is two clicks: the X, then a reason, which submits at once (the app-wide toast then offers Undo). "Add
 * note" switches to a confirm flow first: pick a reason, type the note, press Dismiss. The popover is portaled (into the
 * enclosing modal <dialog> when there is one, so it is not stranded behind the drawer), measured and flipped near the
 * viewport edges, and follows the trigger while a container scrolls. Escape, outside click and Cancel close it and
 * return focus to the X.
 */
export function DismissPopover({
    jobLabel,
    disabled,
    onDecide,
    withLabel = false,
}: {
    jobLabel: string;
    disabled: boolean;
    onDecide: DecideFn;
    /** Show "Dismiss" beside the icon (the drawer footer has room for it). */
    withLabel?: boolean;
}) {
    const [open, setOpen] = useState(false);
    const [noting, setNoting] = useState(false);
    const [reason, setReason] = useState<DismissalReason | null>(null);
    const [note, setNote] = useState('');
    const submitted = useRef(false);
    const trigger = useRef<HTMLButtonElement>(null);
    const panel = useRef<HTMLDivElement>(null);

    const close = useCallback((returnFocus = true) => {
        setOpen(false);
        setNoting(false);
        setReason(null);
        setNote('');
        submitted.current = false;

        if (returnFocus) {
            trigger.current?.focus();
        }
    }, []);

    const submit = (chosen: DismissalReason, text: string) => {
        const payload = dismissalPayload(chosen, text);

        if (payload === null || submitted.current || disabled) {
            return;
        }

        submitted.current = true;
        onDecide('dismissed', {
            reason: payload.reason as DismissalReason,
            note: payload.note,
        });
        close();
    };

    const place = useCallback(() => {
        const anchor = trigger.current?.getBoundingClientRect();
        const box = panel.current;

        if (!anchor || !box) {
            return;
        }

        if (anchor.bottom < 0 || anchor.top > window.innerHeight) {
            close(false);

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
    }, [close]);

    // Re-measure whenever the content changes size (the note field appearing).
    useLayoutEffect(() => {
        if (open) {
            place();
        }
    }, [open, noting, place]);

    useEffect(() => {
        if (!open) {
            return;
        }

        panel.current?.querySelector<HTMLElement>('button')?.focus();

        const onPointerDown = (event: PointerEvent) => {
            const target = event.target as Node;

            if (
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
    }, [open, close, place]);

    const container =
        (open && trigger.current?.closest('dialog')) || document.body;

    return (
        <>
            <button
                ref={trigger}
                type="button"
                disabled={disabled}
                aria-label={`Dismiss ${jobLabel}`}
                aria-haspopup="dialog"
                aria-expanded={open}
                title="Dismiss this job, with a reason"
                onClick={() => (open ? close() : setOpen(true))}
                className={cn(
                    'rounded-control text-fg-subtle hover:bg-bad-soft hover:text-bad focus-visible:bg-bad-soft focus-visible:text-bad focus-visible:ring-focus inline-flex h-8 items-center justify-center gap-1.5 text-xs font-medium transition-colors focus-visible:ring-2 focus-visible:outline-none disabled:pointer-events-none disabled:opacity-45',
                    withLabel ? 'px-2.5' : 'w-8',
                    open && 'bg-bad-soft text-bad',
                )}
            >
                <svg
                    aria-hidden="true"
                    viewBox="0 0 16 16"
                    className="h-4 w-4"
                    fill="none"
                    stroke="currentColor"
                    strokeWidth="1.6"
                    strokeLinecap="round"
                >
                    <path d="M4 4l8 8M12 4l-8 8" />
                </svg>
                {withLabel && 'Dismiss'}
            </button>
            {open &&
                createPortal(
                    <div
                        ref={panel}
                        role="dialog"
                        aria-label="Dismiss job"
                        style={{ visibility: 'hidden' }}
                        onKeyDown={(event) => {
                            if (event.key === 'Escape') {
                                event.preventDefault();
                                event.stopPropagation();
                                close();
                            }
                        }}
                        className="border-line-strong bg-raised shadow-card rounded-card fixed top-0 left-0 z-50 w-72 max-w-[calc(100vw-1rem)] border p-3"
                    >
                        <p className="text-fg mb-2 text-sm font-medium">
                            Dismiss job
                        </p>
                        <div
                            role="group"
                            aria-label="Reason"
                            className="grid grid-cols-2 gap-1.5"
                        >
                            {DISMISSAL_REASONS.map((item) => (
                                <Button
                                    key={item.value}
                                    size="sm"
                                    variant={
                                        noting && reason === item.value
                                            ? 'primary'
                                            : 'secondary'
                                    }
                                    aria-pressed={
                                        noting ? reason === item.value : undefined
                                    }
                                    disabled={disabled}
                                    onClick={() =>
                                        noting
                                            ? setReason(item.value)
                                            : submit(item.value, '')
                                    }
                                    className="justify-start"
                                >
                                    {item.label}
                                </Button>
                            ))}
                        </div>
                        {noting && (
                            <TextInput
                                autoFocus
                                value={note}
                                onChange={(e) => setNote(e.target.value)}
                                onKeyDown={(e) => {
                                    if (e.key === 'Enter' && reason !== null) {
                                        e.preventDefault();
                                        submit(reason, note);
                                    }
                                }}
                                maxLength={500}
                                placeholder="Note (optional)"
                                aria-label="Dismissal note"
                                className="mt-2 h-8 py-1 text-xs"
                            />
                        )}
                        <div className="mt-2 flex items-center gap-1">
                            {noting ? (
                                <Button
                                    variant="primary"
                                    size="sm"
                                    disabled={disabled || reason === null}
                                    onClick={() =>
                                        reason !== null && submit(reason, note)
                                    }
                                >
                                    Dismiss
                                </Button>
                            ) : (
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => setNoting(true)}
                                >
                                    Add note
                                </Button>
                            )}
                            <Button
                                variant="ghost"
                                size="sm"
                                className="ml-auto"
                                onClick={() => close()}
                            >
                                Cancel
                            </Button>
                        </div>
                        {noting && reason === null && (
                            <p className="text-fg-subtle mt-1.5 text-xs">
                                Choose a reason, then Dismiss.
                            </p>
                        )}
                    </div>,
                    container,
                )}
        </>
    );
}
