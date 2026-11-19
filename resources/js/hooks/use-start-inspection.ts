import { router } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { notify } from '@/lib/toast';
import { store as inspectStore } from '@/routes/jobs/applications';

/**
 * Where an inspection is started from, which decides what it returns to and which props a started run refreshes: the
 * Opportunities list (`return_to=opportunities` plus its resume filter) or one Opportunity's workspace.
 */
export type InspectionDestination = {
    post: Record<string, string>;
    refresh: string[];
};

export type StartOptions = {
    /**
     * The one-click open: start only if this job has never been inspected. The server enforces it, so a stale page can
     * never start a second inspection over an existing result.
     */
    onlyIfNone?: boolean;
    /** The person's own application link: saved as their override, then inspected. */
    applicationUrl?: string;
    /** Drop the saved application link and inspect from the original listing again. */
    clearApplicationUrl?: boolean;
    /** The server refused the link; shown beside the field, never as a toast. */
    onInvalid?: (message: string) => void;
};

export type InspectionStarter = {
    starting: boolean;
    start: (jobId: number, options?: StartOptions) => void;
};

/**
 * Starts an inspection from a user action. Dispatch is always called from the click (or submit) handler, never from a
 * mount effect, so React StrictMode's double effects and the drawer's remounts cannot start one.
 *
 * A ref as well as state gates it: two clicks in the same tick both see the state as false. The server also reuses an
 * active inspection, so even a request that gets past this gate cannot create a duplicate.
 */
export function useStartInspection(
    destination: InspectionDestination,
): InspectionStarter {
    const [starting, setStarting] = useState(false);
    const requested = useRef(false);

    const start = (jobId: number, options: StartOptions = {}) => {
        if (requested.current) {
            return;
        }

        requested.current = true;
        setStarting(true);

        const data: Record<string, string | boolean> = {
            ...destination.post,
            ...(options.onlyIfNone ? { only_if_none: true } : {}),
            ...(options.applicationUrl
                ? { application_url: options.applicationUrl }
                : {}),
            ...(options.clearApplicationUrl
                ? { clear_application_url: true }
                : {}),
        };

        router.post(inspectStore.url({ jobPosting: jobId }), data, {
            only: ['inspection_job', ...destination.refresh, 'filters'],
            preserveScroll: true,
            preserveState: true,
            showProgress: false,
            onError: (errors) => {
                if (errors.application_url && options.onInvalid) {
                    options.onInvalid(errors.application_url);

                    return;
                }

                notify.error("Couldn't start the inspection", {
                    description: 'Nothing was queued. Try again.',
                });
            },
            onNetworkError: () => {
                notify.error("Couldn't reach the server", {
                    description: 'Nothing was queued. Try again.',
                });
            },
            onFinish: () => {
                requested.current = false;
                setStarting(false);
            },
        });
    };

    return { starting, start };
}
