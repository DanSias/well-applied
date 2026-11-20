import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { runOnce, deliverResult, runForever } from '../src/poll.js';

// A fake client — poll.js's own contract with client.js (claim()/
// reportResult()) is tested directly here, never against a real
// browser or real HTTP; client.test.js already covers the real HTTP
// wiring, and greenhouse-extractor.test.js already covers inspect().
function fakeClient({ claims = [], reportResultImpl } = {}) {
    let claimIndex = 0;
    const reportedCalls = [];

    return {
        calls: reportedCalls,
        async claim() {
            const next = claims[claimIndex] ?? null;
            claimIndex += 1;
            return next;
        },
        async reportResult(agentRunId, payload) {
            reportedCalls.push({ agentRunId, payload });
            if (reportResultImpl) {
                return reportResultImpl(agentRunId, payload, reportedCalls.length);
            }
            return { ok: true, status: 200, body: { accepted: true, duplicate: false } };
        },
    };
}

const silentLog = () => {};
const noRetryConfig = { resultRetryAttempts: 1, resultRetryBackoffMs: 0 };

describe('runOnce', () => {
    test('returns false and never calls inspect when there is no claimed work', async () => {
        const client = fakeClient({ claims: [null] });
        let inspectCalled = false;

        const gotWork = await runOnce(client, noRetryConfig, silentLog, async () => {
            inspectCalled = true;
            return { status: 'succeeded' };
        });

        assert.equal(gotWork, false);
        assert.equal(inspectCalled, false);
        assert.equal(client.calls.length, 0);
    });

    test('inspects the claimed URL and reports the result', async () => {
        const client = fakeClient({ claims: [{ agent_run_id: 7, application_url: 'https://example.com/jobs/1' }] });
        let inspectedUrl = null;

        const gotWork = await runOnce(client, noRetryConfig, silentLog, async (url) => {
            inspectedUrl = url;
            return { status: 'succeeded', inspection_outcome: 'complete', fields: [] };
        });

        assert.equal(gotWork, true);
        assert.equal(inspectedUrl, 'https://example.com/jobs/1');
        assert.equal(client.calls.length, 1);
        assert.equal(client.calls[0].agentRunId, 7);
        assert.equal(client.calls[0].payload.status, 'succeeded');
    });
});

describe('deliverResult', () => {
    test('delivers on the first attempt without retrying on success', async () => {
        const client = fakeClient();

        const response = await deliverResult(client, 1, { status: 'succeeded' }, { attempts: 3, backoffMs: 0 }, silentLog);

        assert.equal(response.ok, true);
        assert.equal(client.calls.length, 1);
    });

    test('does not retry a stale (409) rejection — that is a completed delivery, not a transient failure', async () => {
        const client = fakeClient({
            reportResultImpl: () => ({ ok: false, status: 409, body: { accepted: false, reason: 'agent_run_not_running' } }),
        });

        const response = await deliverResult(client, 1, { status: 'succeeded' }, { attempts: 3, backoffMs: 0 }, silentLog);

        assert.equal(response.status, 409);
        assert.equal(client.calls.length, 1);
    });

    test('retries a thrown transport error up to the configured attempt count, then gives up', async () => {
        const client = fakeClient({
            reportResultImpl: () => {
                throw new Error('network unreachable');
            },
        });

        const response = await deliverResult(client, 1, { status: 'succeeded' }, { attempts: 3, backoffMs: 0 }, silentLog);

        assert.equal(response, null);
        assert.equal(client.calls.length, 3);
    });

    test('recovers after a transient failure on a later attempt', async () => {
        let attempt = 0;
        const client = fakeClient({
            reportResultImpl: () => {
                attempt += 1;
                if (attempt < 2) {
                    throw new Error('temporary blip');
                }
                return { ok: true, status: 200, body: { accepted: true, duplicate: false } };
            },
        });

        const response = await deliverResult(client, 1, { status: 'succeeded' }, { attempts: 3, backoffMs: 0 }, silentLog);

        assert.equal(response.ok, true);
        assert.equal(client.calls.length, 2);
    });
});

describe('runForever', () => {
    test('stops as soon as shouldStop() reports true, having polled at least once', async () => {
        const client = fakeClient({ claims: [null, null, null] });
        let iterations = 0;

        await runForever(
            client,
            { ...noRetryConfig, pollIntervalMs: 0, maxBackoffMs: 0, careerToolkitUrl: 'http://fake', workerIdentity: 'test' },
            silentLog,
            () => {
                iterations += 1;
                return iterations > 2;
            },
            async () => ({ status: 'succeeded' }),
        );

        assert.ok(iterations > 2);
    });
});

describe('Phase 1: the claim drives the budget and the start URL', () => {
    test('passes the start URL and the budget Well Applied sent, in milliseconds', async () => {
        const client = fakeClient({ claims: [{ agent_run_id: 7, application_url: 'https://old.example.com/', start_url: 'https://start.example.com/apply', start_origin: 'user', budget_seconds: 90 }] });
        let seen;

        await runOnce(client, noRetryConfig, silentLog, async (url, options) => {
            seen = { url, options };
            return { status: 'succeeded' };
        });

        assert.deepEqual(seen, { url: 'https://start.example.com/apply', options: { budgetMs: 90_000, startOrigin: 'user' } });
    });

    test('an older Well Applied (no start_url or budget) still works: application_url and the worker default', async () => {
        const client = fakeClient({ claims: [{ agent_run_id: 8, application_url: 'https://old.example.com/' }] });
        let seen;

        await runOnce(client, noRetryConfig, silentLog, async (url, options) => {
            seen = { url, options };
            return { status: 'succeeded' };
        });

        assert.deepEqual(seen, { url: 'https://old.example.com/', options: { budgetMs: undefined, startOrigin: undefined } });
    });

    test('an inspection that throws still delivers a failed result at once, never leaving the claim to expire', async () => {
        const client = fakeClient({ claims: [{ agent_run_id: 9, start_url: 'https://x.example.com/', budget_seconds: 90 }] });

        const gotWork = await runOnce(client, noRetryConfig, silentLog, async () => {
            throw new Error('boom at https://x.example.com/apply?token=SECRET');
        });

        assert.equal(gotWork, true);
        assert.equal(client.calls.length, 1);
        const { payload } = client.calls[0];
        assert.equal(payload.status, 'failed');
        assert.equal(payload.failure_category, 'unexpected_error');
        assert.ok(!payload.failure_message.includes('SECRET'));
    });
});
