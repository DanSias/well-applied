import { toast } from 'sonner';

/**
 * The app's one way to show a transient notification. A thin layer over Sonner that fixes how long each kind stays
 * (about 4 seconds for success and info, longer for warnings and errors, which a person may need to read twice) and
 * keeps the options small: a title, an optional description, an optional action such as Undo.
 *
 * Use it for feedback that has done its job once read. Validation errors, persistent warnings, progress of long tasks
 * and anything a person needs to refer back to belong in the page itself.
 */
export const TOAST_DURATION_MS = {
    success: 4000,
    info: 4000,
    warning: 6000,
    error: 8000,
} as const;

export type NotifyOptions = {
    description?: string;
    /** A contextual action such as Undo. Clicking it also closes the toast. */
    action?: { label: string; onClick: () => void };
    /** Notifications with the same id replace each other instead of piling up. */
    id?: string | number;
};

type Kind = keyof typeof TOAST_DURATION_MS;

/**
 * Where new toasts go. Normally the app-wide toaster; while a modal surface (the job drawer) is open, that toaster is
 * behind it and inert, so the surface registers its own and toasts follow it: an Undo has to stay clickable.
 */
let target: string | undefined;

export function setToastTarget(id: string | undefined) {
    target = id;
}

function show(kind: Kind, title: string, options: NotifyOptions = {}) {
    return toast[kind](title, {
        description: options.description,
        action: options.action,
        id: options.id,
        toasterId: target,
        duration: TOAST_DURATION_MS[kind],
    });
}

export const notify = {
    success: (title: string, options?: NotifyOptions) =>
        show('success', title, options),
    info: (title: string, options?: NotifyOptions) =>
        show('info', title, options),
    warning: (title: string, options?: NotifyOptions) =>
        show('warning', title, options),
    error: (title: string, options?: NotifyOptions) =>
        show('error', title, options),
};
