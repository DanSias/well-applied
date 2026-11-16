import { router } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import { runFinishedToast } from '@/lib/sources';
import { notify } from '@/lib/toast';
import type { SourceProvider } from '@/types/sources';

/** How often the page asks for fresh data while something is queued or running. */
export const POLL_MS = 3000;

/**
 * Keeps the Sources page current while a provider is queued or running, and only then: it reloads just the props that
 * can change (`summary`, `providers`, `activity`, and the drawer's `selected`), so the URL, the drawer, both page
 * numbers, scroll and focus are untouched. When a provider the page was watching stops being active, it announces the
 * actual outcome from its latest attempt, once. A hidden tab does not poll; it catches up when it is visible again.
 */
export function useActiveRunPolling(providers: SourceProvider[]) {
    const watching = useRef<Map<string, SourceProvider['run_now']['active']>>(
        new Map(),
    );
    const inFlight = useRef(false);
    const anyActive = providers.some((p) => p.run_now.active !== null);

    useEffect(() => {
        for (const provider of providers) {
            const before = watching.current.get(provider.provider) ?? null;

            if (before !== null && provider.run_now.active === null) {
                const toast = runFinishedToast(provider);

                if (toast !== null) {
                    notify[toast.kind](toast.title, {
                        description: toast.description,
                    });
                }
            }

            watching.current.set(provider.provider, provider.run_now.active);
        }
    }, [providers]);

    useEffect(() => {
        if (!anyActive) {
            return;
        }

        const timer = window.setInterval(() => {
            if (inFlight.current || document.visibilityState === 'hidden') {
                return;
            }

            inFlight.current = true;
            router.reload({
                only: ['summary', 'providers', 'activity', 'selected'],
                onFinish: () => {
                    inFlight.current = false;
                },
            });
        }, POLL_MS);

        return () => window.clearInterval(timer);
    }, [anyActive]);
}
