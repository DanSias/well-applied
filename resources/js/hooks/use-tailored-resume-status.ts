import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { status as tailoredResumeStatus } from '@/routes/jobs/tailored-resume';
import type { TailoredResumeStatus } from '@/types/tailored-resume';

const POLL_INTERVAL_MS = 2000;

/**
 * Tracks the unified "Generate Tailored Resume" status by polling the
 * read-only status endpoint while a run is in progress.
 *
 * Auto-navigation: when, during this page session, the status goes from
 * running to ready, the browser is sent to that run's own preview_url,
 * which the server derives from the run's attempts and so is always the
 * newly generated variant, never a previously opened one. Simply
 * loading a page whose run is already ready does NOT navigate; it shows
 * a "Ready for review" link instead.
 */
export function useTailoredResumeStatus(
    jobId: number,
    initial: TailoredResumeStatus,
): { status: TailoredResumeStatus; refresh: () => void } {
    const [status, setStatus] = useState(initial);
    const sawRunning = useRef(initial.state === 'running');
    const navigated = useRef(false);

    // Re-sync when Inertia delivers fresh props (e.g. after the POST).
    useEffect(() => {
        setStatus(initial);

        if (initial.state === 'running') {
            sawRunning.current = true;
        }
    }, [initial]);

    const refresh = async () => {
        try {
            const response = await fetch(
                tailoredResumeStatus.url({ jobPosting: jobId }),
                { headers: { Accept: 'application/json' } },
            );

            if (!response.ok) {
                return;
            }

            const next = (await response.json()) as TailoredResumeStatus;

            if (next.state === 'running') {
                sawRunning.current = true;
            }

            setStatus(next);

            if (
                next.state === 'ready' &&
                sawRunning.current &&
                next.preview_url &&
                !navigated.current
            ) {
                navigated.current = true;
                router.visit(next.preview_url);
            }
        } catch {
            // A transient network error just means: try again next tick.
        }
    };

    useEffect(() => {
        if (status.state !== 'running') {
            return;
        }

        const timer = setInterval(refresh, POLL_INTERVAL_MS);

        return () => clearInterval(timer);
    }, [status.state, jobId]);

    return { status, refresh };
}
