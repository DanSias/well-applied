// The one execution budget for an inspection, shared by every step (URL checks, each navigation, extraction, cleanup).
// Laravel decides the number (the claim lease minus a delivery allowance) and sends it with the claim; the worker never
// picks its own. Nothing here reads a clock except through `now`, so a test can drive it with a fake one.

/** Initial budgets, validated by tests; see README.md "Time budget". */
export const DEFAULT_BUDGET_MS = 90_000;
export const NAVIGATION_TIMEOUT_MS = 20_000;
export const NETWORK_IDLE_TIMEOUT_MS = 8_000;
export const SETTLE_MS = 1_500;
/** Held back from the budget for closing the browser and returning the result once the deadline fires. */
export const CLEANUP_RESERVE_MS = 5_000;

export class DeadlineExceeded extends Error {
    constructor(message) {
        super(message);
        this.name = 'DeadlineExceeded';
    }
}

/**
 * @param {number} totalMs
 * @param {() => number} [now]
 */
export function createDeadline(totalMs, now = Date.now) {
    const startedAt = now();
    const workMs = Math.max(1_000, totalMs - CLEANUP_RESERVE_MS);

    return {
        totalMs,
        /** Milliseconds of work left before the deadline fires (cleanup time already held back). */
        remaining: () => Math.max(0, startedAt + workMs - now()),
        elapsed: () => now() - startedAt,
        expired: () => now() >= startedAt + workMs,
        /** A step's own timeout, never longer than what is left. */
        slice(maxMs) {
            return Math.max(1, Math.min(maxMs, startedAt + workMs - now()));
        },
        /** Milliseconds from now until the hard stop. */
        untilStop: () => Math.max(0, startedAt + workMs - now()),
    };
}
