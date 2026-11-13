import { useEffect, useRef, type ReactNode } from 'react';
import { Toaster } from '@/components/ui/toaster';
import { Button } from '@/components/ui/button';
import { setToastTarget } from '@/lib/toast';

/**
 * The right-hand review drawer shell shared by the Jobs and Opportunities drawers: a modal side sheet for reading
 * something and acting on it without leaving the list. A native <dialog> opened with showModal(), so the browser traps
 * focus, makes the page behind inert and routes Escape. Escape, the close button and a click on the backdrop all go
 * through `onClose`, so the page can keep the URL and history in step and restore focus to what opened it. Full width
 * on a narrow screen, `max-w-xl` beside the list on a wide one; the body scrolls on its own; the slide-in respects
 * reduced motion (see `dialog.job-drawer` in app.css).
 *
 * Toasts: the app-wide toaster sits behind a modal and is inert, so while a drawer is open its own toaster (and the
 * Undo on it) is the one that shows.
 */
export function Drawer({
    title,
    subtitle,
    closeLabel,
    onClose,
    header,
    footer,
    children,
}: {
    title: string;
    subtitle?: string;
    closeLabel: string;
    onClose: () => void;
    /** Extra band under the title (badges, metadata). */
    header?: ReactNode;
    footer?: ReactNode;
    children: ReactNode;
}) {
    const dialog = useRef<HTMLDialogElement>(null);

    useEffect(() => {
        const element = dialog.current;

        if (element !== null && !element.open) {
            element.showModal();
        }
    }, []);

    useEffect(() => {
        setToastTarget('drawer');

        return () => setToastTarget(undefined);
    }, []);

    return (
        <dialog
            ref={dialog}
            aria-labelledby="drawer-title"
            onCancel={(event) => {
                // Escape: let the page decide how to close, so the URL and history stay in step.
                event.preventDefault();
                onClose();
            }}
            onClick={(event) => {
                // A click on the backdrop lands on the dialog element itself, never on its content.
                if (event.target === event.currentTarget) {
                    onClose();
                }
            }}
            className="job-drawer border-line bg-surface text-fg fixed inset-y-0 right-0 left-auto m-0 h-dvh max-h-none w-full max-w-xl flex-col border-l p-0 shadow-xl backdrop:bg-black/55 open:flex"
        >
            <header className="border-line flex items-start justify-between gap-3 border-b px-5 py-4">
                <div className="min-w-0">
                    <h2
                        id="drawer-title"
                        className="text-fg text-base leading-snug font-semibold break-words"
                    >
                        {title}
                    </h2>
                    {subtitle && (
                        <p className="text-fg-muted mt-0.5 text-sm">
                            {subtitle}
                        </p>
                    )}
                </div>
                <Button
                    variant="ghost"
                    size="sm"
                    aria-label={closeLabel}
                    onClick={onClose}
                >
                    ✕
                </Button>
            </header>

            {header}

            <div className="min-h-0 flex-1 overflow-y-auto overscroll-contain px-5 py-4">
                {children}
            </div>

            {footer && (
                <footer className="border-line bg-surface border-t px-5 py-3">
                    {footer}
                </footer>
            )}
            <Toaster id="drawer" inset={88} />
        </dialog>
    );
}
