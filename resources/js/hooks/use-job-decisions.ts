import { router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { store as decisionStore } from '@/routes/jobs/decision';
import { undo as decisionsUndo } from '@/routes/jobs/decisions';
import type {
    DecisionResult,
    DismissalReason,
    JobDisposition,
} from '@/types/job-posting';

/**
 * Decide on jobs from any page (the same `jobs.decision.store` endpoint the Jobs list uses) and keep the one-time
 * `decision_result` flash around for the page to show, with Undo. The flash is copied into state because a partial
 * reload clears it, which would otherwise make the message vanish.
 */
export function useJobDecisions() {
    const flashed = usePage().flash?.decision_result;
    const [result, setResult] = useState<DecisionResult | undefined>(flashed);
    const seen = useRef<DecisionResult | undefined>(flashed);
    const [pending, setPending] = useState(false);

    useEffect(() => {
        if (flashed !== undefined && seen.current !== flashed) {
            seen.current = flashed;
            setResult(flashed);
        }
    }, [flashed]);

    const post = (url: string, data: Record<string, unknown>) => {
        setPending(true);
        router.post(url, data as Parameters<typeof router.post>[1], {
            showProgress: false,
            preserveScroll: true,
            preserveState: true,
            onFinish: () => setPending(false),
        });
    };

    return {
        result,
        pending,
        dismiss: () => setResult(undefined),
        decide: (
            jobId: number,
            to: JobDisposition,
            options?: { reason?: DismissalReason; note?: string },
        ) =>
            post(decisionStore.url({ jobPosting: jobId }), {
                disposition: to,
                reason: options?.reason,
                note: options?.note,
            }),
        undo: () => {
            if (result !== undefined) {
                post(decisionsUndo.url(), { decisions: result.decision_ids });
            }
        },
    };
}
