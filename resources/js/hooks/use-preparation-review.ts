import { useEffect, useState } from 'react';
import { show as reviewRoute } from '@/routes/preparation-attempts';
import type { PreparationReview } from '@/types/application-preparation';

export type ReviewLoad =
    | { status: 'loading' }
    | { status: 'error' }
    | { status: 'ready'; review: PreparationReview };

/**
 * One attempt's review, fetched when it is shown (never embedded in the page's props, so applicant-adjacent report
 * detail is not sent to a list or to a page that only needs the summary). Re-fetched when the attempt changes, so two
 * attempts never share a review.
 */
export function usePreparationReview(
    agentRunId: number | null,
    enabled: boolean,
): ReviewLoad | null {
    const [load, setLoad] = useState<{ id: number; value: ReviewLoad } | null>(
        null,
    );

    useEffect(() => {
        if (agentRunId === null || !enabled) {
            return;
        }

        let cancelled = false;

        fetch(reviewRoute.url({ agentRun: agentRunId }), {
            headers: { Accept: 'application/json' },
        })
            .then(async (response) => {
                if (!response.ok) {
                    throw new Error('not ok');
                }

                return (await response.json()) as PreparationReview;
            })
            .then(
                (review) =>
                    !cancelled &&
                    setLoad({
                        id: agentRunId,
                        value: { status: 'ready', review },
                    }),
            )
            .catch(
                () =>
                    !cancelled &&
                    setLoad({ id: agentRunId, value: { status: 'error' } }),
            );

        return () => {
            cancelled = true;
        };
    }, [agentRunId, enabled]);

    if (agentRunId === null || !enabled) {
        return null;
    }

    return load?.id === agentRunId ? load.value : { status: 'loading' };
}
