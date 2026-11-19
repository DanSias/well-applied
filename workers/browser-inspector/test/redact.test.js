import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { redactText, redactUrl, safeMessage, describeRejection } from '../src/redact.js';
import { runOnce, deliverResult } from '../src/poll.js';

describe('redaction', () => {
    test('URLs lose their query, fragment and credentials, wherever they appear', () => {
        const out = redactText('goto https://user:pw@employer.example.com/apply?token=SECRET&gh_jid=1#x failed, see (https://a.example.org/p?q=1)');

        assert.ok(!/SECRET|pw@|gh_jid|q=1/.test(out));
        assert.ok(out.includes('https://employer.example.com/apply') && out.includes('https://a.example.org/p'));
    });

    test('bearer tokens and token-like assignments are masked even outside a URL', () => {
        const out = redactText('Authorization: Bearer abc.DEF-123_xyz== and api_key=hunter2 and "password": "p4ss"');

        assert.ok(!/abc\.DEF|hunter2|p4ss/.test(out));
        assert.match(out, /Bearer \[redacted\]/);
    });

    test('messages are bounded, and non-URLs are untouched', () => {
        assert.equal(redactUrl('not a url'), null);
        assert.equal(redactText('x'.repeat(1000)).length, 300);
        assert.equal(safeMessage(new Error('plain failure')), 'plain failure');
        assert.equal(safeMessage(undefined), '');
    });

    test('a rejected result is described by status, a short message and field names, never their values', () => {
        const text = describeRejection({ status: 422, body: { message: 'The final url field is invalid.', errors: { final_url: ['https://x.example.com/a?token=SECRET'], 'fields.0.label': ['no'] } } });

        assert.match(text, /status 422/);
        assert.match(text, /fields: final_url, fields\.0\.label/);
        assert.ok(!text.includes('SECRET'));
    });
});

describe('what the poll loop writes to its log', () => {
    test('a thrown inspection, a failed delivery and a rejected result never log a URL query or token', async () => {
        const logs = [];
        const log = (l) => logs.push(l);
        const delivered = [];
        const client = {
            claim: async () => ({ agent_run_id: 5, start_url: 'https://x.example.com/', budget_seconds: 90 }),
            reportResult: async (id, payload) => {
                delivered.push(payload);

                return { ok: false, status: 422, body: { message: 'bad https://x.example.com/p?token=SECRET', errors: { final_url: ['https://x.example.com/?token=SECRET'] } } };
            },
        };

        await runOnce(client, { resultRetryAttempts: 1, resultRetryBackoffMs: 0 }, log, async () => {
            throw new Error('boom https://x.example.com/apply?token=SECRET Bearer abcdef123456');
        });

        const all = logs.join('\n') + JSON.stringify(delivered);
        assert.ok(!/SECRET|abcdef123456/.test(all), all);
        assert.match(all, /rejected \(status 422/);
    });

    test('a transport error on delivery is redacted too', async () => {
        const logs = [];
        const client = { reportResult: async () => { throw new Error('connect ECONNREFUSED http://api-relay:8081/x?token=SECRET'); } };

        await deliverResult(client, 1, {}, { attempts: 1, backoffMs: 0 }, (l) => logs.push(l));

        assert.ok(!logs.join('').includes('SECRET'));
    });
});
