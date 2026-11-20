import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import net from 'node:net';
import {
    checkIsolation,
    createIsolationGate,
    parseTargets,
    processFailures,
    tcpProbe,
    DEFAULT_BLOCKED_TARGETS,
} from '../src/isolation-check.js';
import { runForever } from '../src/poll.js';
import { loadConfig } from '../src/config.js';

const GOOD_STATUS = 'Name:\tnode\nUid:\t1000\t1000\t1000\t1000\nNoNewPrivs:\t1\nSeccomp:\t2\nCapEff:\t0000000000000000\n';
const rejected = async (target) => ({ target, outcome: 'rejected', code: 'EHOSTUNREACH' });

describe('canary evidence', () => {
    test('passes only when every canary was refused with "host unreachable" and privileges are dropped', async () => {
        const verdict = await checkIsolation({ probe: rejected, readStatus: () => GOOD_STATUS });

        assert.equal(verdict.ok, true);
        assert.equal(verdict.checks.length, DEFAULT_BLOCKED_TARGETS.length);
    });


    for (const [label, outcome] of [
        ['connected (the packet got through)', { outcome: 'connected' }],
        ['connection refused (it reached a real machine)', { outcome: 'refused', code: 'ECONNREFUSED' }],
        ['timed out (nothing refused it, so no rule answered)', { outcome: 'timeout' }],
        ['an unrelated error', { outcome: 'error', code: 'EAI_AGAIN' }],
    ]) {
        test(`fails closed when a canary is ${label}`, async () => {
            const probe = async (target) => (target === '169.254.169.254:80' ? { target, ...outcome } : rejected(target));
            const verdict = await checkIsolation({ probe, readStatus: () => GOOD_STATUS, sleep: async () => {} });

            assert.equal(verdict.ok, false);
            assert.match(verdict.failures[0], /169\.254\.169\.254:80 was not refused/);
        });
    }

    test('includes the operator\'s extra canaries (this deployment\'s gateways, the host and the Mac)', async () => {
        const seen = [];
        await checkIsolation({
            extraTargets: ['172.29.60.1:9', '100.84.185.81:9'],
            probe: async (t) => (seen.push(t), rejected(t)),
            readStatus: () => GOOD_STATUS,
        });

        assert.ok(seen.includes('172.29.60.1:9') && seen.includes('100.84.185.81:9'));
    });

    test('refusals lost to the ICMP rate limit are retried one by one, but only when the policy has shown itself', async () => {
        // The first six canaries are refused; the rest time out (rate-limited), then are refused when asked again.
        const attempts = new Map();
        const probe = async (target) => {
            const n = (attempts.get(target) ?? 0) + 1;
            attempts.set(target, n);
            const limited = [...attempts.keys()].indexOf(target) >= 6;

            return limited && n === 1 ? { target, outcome: 'timeout' } : { target, outcome: 'rejected', code: 'EHOSTUNREACH' };
        };
        const extra = ['172.29.60.1:9', '172.29.61.1:9', '100.84.185.81:9', '192.168.1.111:9'];
        const verdict = await checkIsolation({ extraTargets: extra, probe, readStatus: () => GOOD_STATUS, sleep: async () => {} });

        assert.equal(verdict.ok, true, verdict.failures.join('; '));
        assert.ok([...attempts.values()].some((n) => n === 2), 'a rate-limited canary was asked a second time');
    });

    test('with no canary refused at all (no policy), timeouts are failures at once, with no slow retries', async () => {
        let asked = 0;
        const probe = async (target) => (asked++, { target, outcome: 'timeout' });
        const verdict = await checkIsolation({ probe, readStatus: () => GOOD_STATUS, sleep: async () => { throw new Error('must not wait'); } });

        assert.equal(verdict.ok, false);
        assert.equal(asked, DEFAULT_BLOCKED_TARGETS.length, 'each canary asked exactly once');
    });

    test('a real connection to a closed local port is "refused", never mistaken for the policy', async () => {
        const server = net.createServer().listen(0, '127.0.0.1');
        await new Promise((r) => server.once('listening', r));
        const port = server.address().port;
        await new Promise((r) => server.close(r));

        assert.equal((await tcpProbe(`127.0.0.1:${port}`, 1000)).outcome, 'refused');
    });
});

describe('process privileges', () => {
    test('capabilities, a missing no-new-privileges and root are each a failure', () => {
        assert.deepEqual(processFailures(GOOD_STATUS), []);
        assert.match(processFailures(GOOD_STATUS.replace('CapEff:\t0000000000000000', 'CapEff:\t00000000a80425fb'))[0], /holds capabilities/);
        assert.match(processFailures(GOOD_STATUS.replace('NoNewPrivs:\t1', 'NoNewPrivs:\t0'))[0], /no-new-privileges/);
        assert.match(processFailures(GOOD_STATUS.replace('Uid:\t1000', 'Uid:\t0'))[0], /root/);
        assert.match(processFailures(GOOD_STATUS.replace('Seccomp:\t2', 'Seccomp:\t0'))[0], /no seccomp filter.*unconfined/);
    });

    test('an unreadable or unfamiliar status is a failure, not a pass', async () => {
        assert.equal(processFailures('').length, 4);
        const verdict = await checkIsolation({
            probe: rejected,
            readStatus: () => {
                throw Object.assign(new Error('x'), { code: 'ENOENT' });
            },
        });
        assert.equal(verdict.ok, false);
    });
});

describe('the claim gate', () => {
    test('caches a pass for its ttl, re-checks after it, and never caches a failure', async () => {
        let clock = 0;
        let calls = 0;
        let ok = true;
        const gate = createIsolationGate(async () => (calls++, { ok, failures: ok ? [] : ['x'] }), { ttlMs: 1000, now: () => clock });

        await gate();
        clock = 500;
        await gate();
        assert.equal(calls, 1);

        clock = 1500;
        ok = false;
        assert.equal((await gate()).ok, false);
        assert.equal((await gate()).ok, false);
        assert.equal(calls, 3, 'a failure is re-checked every time');
    });

    test('the poll loop refuses to claim when the gate fails, and says why', async () => {
        const logs = [];
        let claimed = 0;
        const client = { claim: async () => (claimed++, null) };
        const gate = async () => ({ ok: false, failures: ['169.254.169.254:80 was not refused (connected)'] });

        const reason = await runForever(client, { pollIntervalMs: 1, maxBackoffMs: 1 }, (l) => logs.push(l), () => false, async () => ({}), gate);

        assert.equal(reason, 'isolation_failed');
        assert.equal(claimed, 0, 'no work was ever claimed');
        assert.match(logs.join('\n'), /ISOLATION CHECK FAILED/);
    });

    test('a passing gate does not change normal polling', async () => {
        let polls = 0;
        const client = { claim: async () => (polls++, null) };

        const reason = await runForever(client, { pollIntervalMs: 1, maxBackoffMs: 1 }, () => {}, () => polls >= 2, async () => ({}), async () => ({ ok: true, failures: [] }));

        assert.equal(reason, 'stopped');
        assert.equal(polls, 2);
    });
});

describe('configuration', () => {
    test('isolation is required only when the deployment says so, and canaries parse strictly', () => {
        const base = { CAREER_TOOLKIT_URL: 'http://api-relay:8081', BROWSER_WORKER_TOKEN: 't' };

        assert.equal(loadConfig(base).isolation.required, false);
        const config = loadConfig({ ...base, BROWSER_WORKER_REQUIRE_ISOLATION: '1', BROWSER_WORKER_ISOLATION_CANARIES: '172.29.60.1:9, bad ,10.0.0.1:x,100.84.185.81:9' });
        assert.equal(config.isolation.required, true);
        assert.deepEqual(config.isolation.extraTargets, ['172.29.60.1:9', '100.84.185.81:9']);
        assert.deepEqual(parseTargets(undefined), []);
    });
});
