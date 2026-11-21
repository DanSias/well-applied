import { useCallback, useEffect, useRef, useState } from 'react';
import { postJson } from '@/lib/http';
import {
    cancelPreparation,
    startPreparation,
    type PreparationActionError,
} from '@/lib/preparation-actions';
import { createPoller } from '@/lib/preparation-poller';
import { isActiveState } from '@/lib/preparation-status';
import { cancel as cancelRoute } from '@/routes/preparations';
import { show as stateRoute } from '@/routes/applications/preparation';
import { store as startRoute } from '@/routes/jobs/preparations';
import type {
    PreparationPanel,
    PreparationState,
} from '@/types/application-preparation';

/**
 * One job's application-preparation state on the client: what the server handed the page, kept fresh by polling only
 * while an attempt is queued or running, and the two things a person can do (start an attempt, cancel a queued one).
 *
 * Every change comes from the server's own answer. Cancelling updates the screen only after the server confirms it; a
 * refusal (for example the worker claimed the attempt in the meantime) is shown and the real state is fetched. Nothing
 * here retries on its own: a failed or stopped attempt stays as it is until a person starts a new one.
 */
export function useApplicationPreparation(
    initial: PreparationPanel,
    /** Why a NEW preparation cannot be started (an expired or closed job); null when it can. Existing attempts, history and cancelling stay. */
    blocked: string | null = null,
) {
    const [panel, setPanel] = useState<PreparationPanel>(initial);
    const [busy, setBusy] = useState<'start' | 'cancel' | null>(null);
    const [error, setError] = useState<PreparationActionError | null>(null);
    const panelRef = useRef(panel);

    panelRef.current = panel;

    const applicationId = panel.state?.application_id ?? null;
    const active = isActiveState(panel.state);

    const fetchState =
        useCallback(async (): Promise<PreparationState | null> => {
            if (applicationId === null) {
                return null;
            }

            const response = await fetch(
                stateRoute.url({ application: applicationId }),
                {
                    headers: { Accept: 'application/json' },
                },
            );

            return response.ok
                ? ((await response.json()) as PreparationState)
                : null;
        }, [applicationId]);

    const apply = useCallback((state: PreparationState) => {
        // The server decides whether another start is allowed; the page only mirrors it.
        const running = isActiveState(state);

        setPanel((current) => ({
            ...current,
            state,
            can_prepare: !running && current.problem === null,
        }));
    }, []);

    useEffect(() => {
        if (!active) {
            return;
        }

        const poller = createPoller({ fetchState, onState: apply });

        poller.start();

        return () => poller.stop();
    }, [active, fetchState, apply]);

    const refresh = useCallback(async () => {
        const state = await fetchState().catch(() => null);

        if (state !== null) {
            apply(state);
        }
    }, [fetchState, apply]);

    const start = useCallback(
        async (resumeVariantId: number | null) => {
            if (blocked !== null) {
                return false;
            }

            setBusy('start');
            setError(null);

            const result = await startPreparation(
                startRoute.url({ jobPosting: panelRef.current.job_id }),
                resumeVariantId,
                postJson,
            );

            setBusy(null);

            if (result.ok) {
                apply(result.state);

                return true;
            }

            setError(result.error);

            return false;
        },
        [apply, blocked],
    );

    const cancel = useCallback(
        async (workflowRunId: number) => {
            setBusy('cancel');
            setError(null);

            const result = await cancelPreparation(
                cancelRoute.url({ workflowRun: workflowRunId }),
                postJson,
            );

            if (result.error !== null) {
                setError(result.error);
            }

            // The real state either way: a cancelled attempt must not stay visibly queued.
            await refresh();
            setBusy(null);

            return result.done;
        },
        [refresh],
    );

    return {
        panel,
        active,
        busy,
        error,
        blocked,
        clearError: () => setError(null),
        start,
        cancel,
        refresh,
    };
}
