import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { createDeadline, DEFAULT_BUDGET_MS, NAVIGATION_TIMEOUT_MS, NETWORK_IDLE_TIMEOUT_MS, CLEANUP_RESERVE_MS } from '../src/budget.js';

describe('the execution budget', () => {
    test('initial budgets: 90s total, 20s per navigation, 8s best-effort network idle', () => {
        assert.equal(DEFAULT_BUDGET_MS, 90_000);
        assert.equal(NAVIGATION_TIMEOUT_MS, 20_000);
        assert.equal(NETWORK_IDLE_TIMEOUT_MS, 8_000);
    });

    test('the budget plus the delivery allowance fits inside the lease', () => {
        assert.ok(DEFAULT_BUDGET_MS + 30_000 <= 120_000);
    });

    test('three pages at their worst would not fit, which is why every step is cut to what is left (the budget is enforced, not hoped for)', () => {
        const perPage = NAVIGATION_TIMEOUT_MS + NETWORK_IDLE_TIMEOUT_MS + 1_500;
        assert.ok(3 * perPage + CLEANUP_RESERVE_MS > DEFAULT_BUDGET_MS, 'the unclamped worst case is over budget');

        let clock = 0;
        const deadline = createDeadline(DEFAULT_BUDGET_MS, () => clock);
        let spent = 0;
        for (let page = 0; page < 3; page++) {
            for (const step of [NAVIGATION_TIMEOUT_MS, NETWORK_IDLE_TIMEOUT_MS, 1_500]) {
                const allowed = deadline.slice(step);
                clock += allowed;
                spent += allowed;
            }
        }
        assert.ok(spent <= DEFAULT_BUDGET_MS - CLEANUP_RESERVE_MS + 3, 'what the steps may spend never passes the work budget');
        assert.equal(deadline.expired(), true);
    });

    test('a step is never given more than what is left, and the deadline expires on time', () => {
        let clock = 1_000;
        const deadline = createDeadline(30_000, () => clock);

        assert.equal(deadline.slice(20_000), 20_000);
        clock += 20_000;
        assert.equal(deadline.slice(20_000), 5_000, '30s - 5s cleanup reserve - 20s used');
        assert.equal(deadline.expired(), false);
        clock += 5_000;
        assert.equal(deadline.expired(), true);
        assert.equal(deadline.remaining(), 0);
        assert.equal(deadline.slice(20_000), 1, 'never zero or negative');
    });

    test('untilStop is where the hard stop timer is set', () => {
        const deadline = createDeadline(90_000, () => 0);

        assert.equal(deadline.untilStop(), 85_000);
    });
});
