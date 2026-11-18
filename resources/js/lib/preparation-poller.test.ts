import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createPoller } from './preparation-poller';
import { stateOf } from './preparation-test-data';

beforeEach(() => vi.useFakeTimers());
afterEach(() => vi.useRealTimers());

describe('the preparation poller', () => {
    it('asks after one interval, keeps going while the attempt is active, and stops once it is terminal', async () => {
        const responses = [
            stateOf('queued'),
            stateOf('running'),
            stateOf('succeeded'),
        ];
        const fetchState = vi.fn(async () => responses.shift() ?? null);
        const seen: string[] = [];
        const poller = createPoller({
            fetchState,
            onState: (s) => seen.push(s.attempt!.status),
            intervalMs: 1000,
        });

        poller.start();
        expect(fetchState).not.toHaveBeenCalled();
        await vi.advanceTimersByTimeAsync(1000);
        await vi.advanceTimersByTimeAsync(1000);
        await vi.advanceTimersByTimeAsync(1000);
        expect(seen).toEqual(['queued', 'running', 'succeeded']);

        // Terminal: no further request, ever.
        await vi.advanceTimersByTimeAsync(60_000);
        expect(fetchState).toHaveBeenCalledTimes(3);
    });

    it.each(['failed', 'cancelled'] as const)(
        'stops on %s too',
        async (terminal) => {
            const fetchState = vi.fn(async () => stateOf(terminal));
            const poller = createPoller({
                fetchState,
                onState: () => {},
                intervalMs: 500,
            });

            poller.start();
            await vi.advanceTimersByTimeAsync(5000);

            expect(fetchState).toHaveBeenCalledTimes(1);
        },
    );

    it('survives a failed or empty request and tries again', async () => {
        const fetchState = vi
            .fn()
            .mockRejectedValueOnce(new Error('offline'))
            .mockResolvedValueOnce(null)
            .mockResolvedValue(stateOf('running'));
        const seen = vi.fn();
        const poller = createPoller({
            fetchState,
            onState: seen,
            intervalMs: 100,
        });

        poller.start();
        await vi.advanceTimersByTimeAsync(300);

        expect(fetchState).toHaveBeenCalledTimes(3);
        expect(seen).toHaveBeenCalledTimes(1);
        poller.stop();
    });

    it('never has two requests in flight', async () => {
        let resolve: (s: ReturnType<typeof stateOf>) => void = () => {};
        const fetchState = vi.fn(
            () => new Promise<ReturnType<typeof stateOf>>((r) => (resolve = r)),
        );
        const poller = createPoller({
            fetchState,
            onState: () => {},
            intervalMs: 100,
        });

        poller.start();
        await vi.advanceTimersByTimeAsync(1000);

        expect(fetchState).toHaveBeenCalledTimes(1);
        resolve(stateOf('queued'));
        poller.stop();
    });

    it('stop is final: it cancels the timer and ignores a response that arrives afterwards', async () => {
        let resolve: (s: ReturnType<typeof stateOf>) => void = () => {};
        const fetchState = vi.fn(
            () => new Promise<ReturnType<typeof stateOf>>((r) => (resolve = r)),
        );
        const onState = vi.fn();
        const poller = createPoller({ fetchState, onState, intervalMs: 100 });

        poller.start();
        await vi.advanceTimersByTimeAsync(100);
        poller.stop();
        resolve(stateOf('running'));
        await vi.advanceTimersByTimeAsync(10_000);

        expect(onState).not.toHaveBeenCalled();
        expect(fetchState).toHaveBeenCalledTimes(1);
    });

    it('does not double-start, and can be started again after a stop', async () => {
        const fetchState = vi.fn(async () => stateOf('queued'));
        const poller = createPoller({
            fetchState,
            onState: () => {},
            intervalMs: 100,
        });

        poller.start();
        poller.start();
        await vi.advanceTimersByTimeAsync(100);
        expect(fetchState).toHaveBeenCalledTimes(1);

        poller.stop();
        poller.start();
        await vi.advanceTimersByTimeAsync(100);
        expect(fetchState).toHaveBeenCalledTimes(2);
        poller.stop();
    });
});
