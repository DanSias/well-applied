// Per-host pacing with an injected clock. `waitFor(host)` returns after at least `gapMs` since the host was last marked, `mark(host)` records a request.
// Shared by the runner (between cases) and the live adapter (before every navigation inside a case, redirects and new destination hosts included).
export class PacingPastDeadline extends Error {}

export function createPacer({ clock, gapMs }) {
    const last = new Map();

    return {
        gapMs,
        last: (host) => last.get(host) ?? null,
        mark(host, at = clock.now()) {
            last.set(host, at);
        },
        /** @returns {Promise<number>} milliseconds waited. Throws PacingPastDeadline (and does not wait) if the wait would reach the deadline. */
        async waitFor(host, { deadline = Infinity } = {}) {
            const wait = (last.get(host) ?? -Infinity) + gapMs - clock.now();
            if (wait <= 0) return 0;
            if (clock.now() + wait >= deadline) throw new PacingPastDeadline(`pacing ${host} would pass the deadline`);
            await clock.sleep(wait);

            return wait;
        },
    };
}
