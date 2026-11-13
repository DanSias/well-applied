import { router } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { notify } from '@/lib/toast';
import { store as decisionStore } from '@/routes/jobs/decision';
import { decision as batchDecision } from '@/routes/jobs/batch';
import type { DismissalReason, JobDisposition } from '@/types/job-posting';

type Options = { reason?: DismissalReason; note?: string };

/**
 * Decide on one job or a selection from any page, through the same endpoints. The outcome is not shown here: the
 * server's answer arrives as flash data and the app-wide toast (see lib/flash-toasts.ts) reports it, with Undo. What
 * this owns is the request itself: one at a time (a repeated click while one is in flight is ignored), and a toast when
 * the request is rejected or never gets an answer.
 */
export function useJobDecisions() {
    const [pending, setPending] = useState(false);
    const inFlight = useRef(false);

    const post = (
        url: string,
        data: Record<string, unknown>,
        onSuccess?: () => void,
    ) => {
        if (inFlight.current) {
            return;
        }

        inFlight.current = true;
        setPending(true);
        router.post(url, data as Parameters<typeof router.post>[1], {
            showProgress: false,
            preserveScroll: true,
            preserveState: true,
            onSuccess,
            onError: (errors) =>
                notify.error("Couldn't save that decision", {
                    description: Object.values(errors)[0],
                }),
            onNetworkError: () => {
                notify.error("Couldn't reach the server", {
                    description: 'Nothing was changed. Try again.',
                });
            },
            onFinish: () => {
                inFlight.current = false;
                setPending(false);
            },
        });
    };

    return {
        pending,
        decide: (jobId: number, to: JobDisposition, options?: Options) =>
            post(decisionStore.url({ jobPosting: jobId }), {
                disposition: to,
                reason: options?.reason,
                note: options?.note,
            }),
        decideMany: (
            jobIds: number[],
            to: JobDisposition,
            options?: Options,
            onSuccess?: () => void,
        ) =>
            post(
                batchDecision.url(),
                {
                    job_ids: jobIds,
                    disposition: to,
                    reason: options?.reason,
                    note: options?.note,
                },
                onSuccess,
            ),
    };
}
