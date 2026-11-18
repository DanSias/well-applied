import type { PreparationState } from '@/types/application-preparation';
import { isActiveState } from '@/lib/preparation-status';

/**
 * Polls an attempt's state for as long as it is active and stops by itself the moment it is not. No framework, so the
 * lifecycle can be tested with fake timers:
 *
 * - it asks first after one interval, never in a tight loop, and never has two requests in flight;
 * - a failed request (a network blip) is ignored and tried again on the next tick;
 * - when a response shows the attempt is no longer queued or running (finished, failed, cancelled) it stops, and no
 *   further request is made;
 * - `stop()` is final and also ignores a response that arrives afterwards.
 */
export type Poller = { start: () => void; stop: () => void };

export function createPoller(options: {
    fetchState: () => Promise<PreparationState | null>;
    onState: (state: PreparationState) => void;
    intervalMs?: number;
}): Poller {
    const intervalMs = options.intervalMs ?? 2000;
    let timer: ReturnType<typeof setTimeout> | null = null;
    let stopped = true;
    let inFlight = false;

    const schedule = () => {
        if (stopped) {
            return;
        }

        timer = setTimeout(tick, intervalMs);
    };

    const tick = async () => {
        timer = null;

        if (stopped || inFlight) {
            return;
        }

        inFlight = true;

        try {
            const state = await options.fetchState();

            if (stopped) {
                return;
            }

            // An empty answer (the request failed) says nothing about the attempt: keep asking.
            if (state !== null) {
                options.onState(state);

                if (!isActiveState(state)) {
                    stopped = true;

                    return;
                }
            }
        } catch {
            // A transient failure: the next tick tries again.
        } finally {
            inFlight = false;
        }

        schedule();
    };

    return {
        start: () => {
            if (!stopped) {
                return;
            }

            stopped = false;
            schedule();
        },
        stop: () => {
            stopped = true;

            if (timer !== null) {
                clearTimeout(timer);
                timer = null;
            }
        },
    };
}
