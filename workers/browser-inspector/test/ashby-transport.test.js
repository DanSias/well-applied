// The Ashby transport against a LOCAL HTTPS fixture server with fake DNS: no test here leaves the machine. The real destination is a
// fixed constant; `testing` (resolver, port, trusted CA) is how a test points the same code at 127.0.0.1.
import { test, describe, before, after, beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import https from 'node:https';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createAshbyTransport, AshbyTransportError, ASHBY_ENDPOINT, LIMITS } from '../src/transport/ashby.js';
import { buildRequest } from '../src/providers/ashby/request.js';

const dir = path.join(path.dirname(fileURLToPath(import.meta.url)), 'fixtures');
const read = (...p) => readFileSync(path.join(dir, ...p));
const fx = (n) => JSON.parse(read('ashby', n).toString());
const tlsFile = (n) => read('tls', n);
const ORG = { organizationHostedJobsPageName: 'nerdwallet', searchContext: 'JobPosting' };
const JOB = { organizationHostedJobsPageName: 'nerdwallet', jobPostingId: '32c62385-07d5-4d6d-960e-12c05ce0cbef' };

/** A local HTTPS server whose behaviour each test sets; it records every request it receives. */
function fixtureServer(cert, key) {
    const state = { requests: [], handler: null };
    const server = https.createServer({ cert, key }, (req, res) => {
        const chunks = [];
        req.on('data', (c) => chunks.push(c));
        req.on('end', () => {
            state.requests.push({ method: req.method, url: req.url, headers: req.headers, body: Buffer.concat(chunks).toString(), servername: req.socket.servername });
            state.handler(req, res, state.requests.at(-1));
        });
    });
    return { server, state };
}
const json = (body, status = 200, headers = {}) => (req, res) => { res.writeHead(status, { 'Content-Type': 'application/json', ...headers }); res.end(JSON.stringify(body)); };

const good = fixtureServer(tlsFile('ashby.pem'), tlsFile('ashby.key'));
const wrong = fixtureServer(tlsFile('wronghost.pem'), tlsFile('wronghost.key'));
const untrusted = fixtureServer(tlsFile('untrusted.pem'), tlsFile('untrusted.key'));
const ports = {};
before(async () => {
    for (const [name, s] of Object.entries({ good, wrong, untrusted })) {
        await new Promise((r) => s.server.listen(0, '127.0.0.1', r));
        ports[name] = s.server.address().port;
    }
});
after(() => Promise.all([good, wrong, untrusted].map((s) => new Promise((r) => { s.server.closeAllConnections?.(); s.server.close(r); }))));
beforeEach(() => { for (const s of [good, wrong, untrusted]) { s.state.requests.length = 0; s.state.handler = json({}); } });

const answers = (address = '127.0.0.1', family = 4) => async () => [{ address, family }];
const CA = tlsFile('ca.pem');
/** A transport aimed at a fixture. addressAllowed is overridden ONLY where the fixture itself lives on loopback. */
const transportFor = (which = 'good', over = {}) => createAshbyTransport({ testing: { resolve: answers(), addressAllowed: () => true, port: ports[which], ca: CA, ...over.testing }, deadline: over.deadline ?? null });
const fast = (ms = 150) => ({ expired: () => false, slice: (max) => Math.min(max, ms) });
const refusal = async (promise) => { try { await promise; } catch (e) { assert.ok(e instanceof AshbyTransportError, String(e)); return e; } assert.fail('expected a refusal'); };

describe('a request to the fixed destination', () => {
    test('is a POST of exactly buildRequest() to the fixed path, with a small fixed header set and no cookies or credentials', async () => {
        good.state.handler = json({ data: {} });
        const t = transportFor();
        await t.send('ApiOrganizationFromHostedJobsPageName', ORG);
        await t.send('ApiJobPosting', JOB);

        assert.deepEqual(good.state.requests.map((r) => [r.method, r.url]), [
            ['POST', `${ASHBY_ENDPOINT.path}?op=ApiOrganizationFromHostedJobsPageName`],
            ['POST', `${ASHBY_ENDPOINT.path}?op=ApiJobPosting`],
        ]);
        assert.deepEqual(JSON.parse(good.state.requests[0].body), buildRequest('ApiOrganizationFromHostedJobsPageName', ORG));
        assert.deepEqual(JSON.parse(good.state.requests[1].body), buildRequest('ApiJobPosting', JOB));
        for (const r of good.state.requests) {
            assert.equal(r.headers.host, ASHBY_ENDPOINT.host, 'the Host header is the real name, not the address');
            assert.equal(r.servername, ASHBY_ENDPOINT.host, 'TLS SNI is the real name');
            assert.deepEqual(Object.keys(r.headers).sort(), ['accept', 'accept-encoding', 'connection', 'content-length', 'content-type', 'host', 'user-agent']);
            assert.equal(r.headers['accept-encoding'], 'identity');
        }
    });

    test('takes no destination: only an operation name and variables, and anything else is ignored', async () => {
        assert.equal(createAshbyTransport().send.length, 2);
        good.state.handler = json({ data: {} });
        await transportFor().send('ApiJobPosting', JOB, 'https://evil.example/x', { headers: { Cookie: 'a=b' } });
        assert.equal(good.state.requests.length, 1);
        assert.ok(!('cookie' in good.state.requests[0].headers));
    });

    test('has the fixed destination constants and a frozen configuration', () => {
        assert.deepEqual({ ...ASHBY_ENDPOINT }, { host: 'jobs.ashbyhq.com', port: 443, path: '/api/non-user-graphql' });
        assert.ok(Object.isFrozen(ASHBY_ENDPOINT) && Object.isFrozen(LIMITS));
    });

    test('never sends an operation, variable or document the adapter does not allow (a security refusal, and nothing is sent)', async () => {
        const t = transportFor();
        for (const [op, vars, reason] of [
            ['DeleteEverything', JOB, 'unknown_operation'],
            ['ApiJobPosting', { ...JOB, extra: 'x' }, 'unexpected_variable'],
            ['ApiJobPosting', { organizationHostedJobsPageName: 'nerdwallet' }, 'missing_variable'],
            ['ApiJobPosting', { ...JOB, jobPostingId: 'not-a-uuid' }, 'invalid_variable'],
            ['ApiJobPosting', { ...JOB, organizationHostedJobsPageName: '../../x' }, 'invalid_variable'],
            ['__proto__', JOB, 'unknown_operation'],
        ]) {
            const e = await refusal(t.send(op, vars));
            assert.deepEqual([e.kind, e.reason, e.detail], ['security', 'request_refused', reason], op);
        }
        assert.equal(good.state.requests.length, 0);
        assert.equal(t.requestsUsed(), 0);
    });

    test('allows one attempt per operation and two requests in all', async () => {
        good.state.handler = json({ data: {} });
        const t = transportFor();
        await t.send('ApiJobPosting', JOB);
        const again = await refusal(t.send('ApiJobPosting', JOB));
        assert.deepEqual([again.kind, again.reason], ['security', 'repeat_operation']);
        await t.send('ApiOrganizationFromHostedJobsPageName', ORG);
        assert.equal(good.state.requests.length, 2);
        assert.equal(t.requestsUsed(), 2);
        assert.equal((await refusal(t.send('ApiJobPosting', JOB))).reason, 'request_budget_exhausted');
    });

    test('a failed attempt is not retried and still counts', async () => {
        good.state.handler = json({}, 503);
        const t = transportFor();
        assert.equal((await refusal(t.send('ApiJobPosting', JOB))).reason, 'http_status');
        assert.equal(good.state.requests.length, 1);
        assert.equal((await refusal(t.send('ApiJobPosting', JOB))).reason, 'repeat_operation');
        assert.equal(good.state.requests.length, 1);
    });
});

describe('DNS answers', () => {
    test('a private, loopback, link-local, metadata, carrier-grade or mapped answer refuses the lookup and nothing connects', async () => {
        for (const address of ['10.0.0.5', '127.0.0.1', '192.168.1.111', '169.254.169.254', '100.84.185.81', '172.16.0.1', '::1', 'fe80::1', '::ffff:10.0.0.5']) {
            const t = createAshbyTransport({ testing: { resolve: answers(address, address.includes(':') ? 6 : 4), port: ports.good, ca: CA } });
            const e = await refusal(t.send('ApiJobPosting', JOB));
            assert.deepEqual([e.kind, e.reason], ['security', 'prohibited_address'], address);
        }
        assert.equal(good.state.requests.length, 0);
    });

    test('one prohibited answer among public ones refuses the whole lookup', async () => {
        const t = createAshbyTransport({ testing: { resolve: async () => [{ address: '93.184.216.34', family: 4 }, { address: '10.0.0.5', family: 4 }], port: ports.good, ca: CA } });
        assert.equal((await refusal(t.send('ApiJobPosting', JOB))).reason, 'prohibited_address');
    });

    test('a malformed answer is refused, not trusted', async () => {
        const t = createAshbyTransport({ testing: { resolve: async () => [{ family: 4 }], port: ports.good, ca: CA } });
        assert.equal((await refusal(t.send('ApiJobPosting', JOB))).reason, 'prohibited_address');
    });

    test('no answer, a failed lookup, an IPv6-only answer and a lookup that hangs are availability failures', async () => {
        const cases = {
            dns_failed: async () => [],
            dns_failed2: async () => { throw Object.assign(new Error('x'), { code: 'ENOTFOUND' }); },
            dns_no_ipv4: async () => [{ address: '2606:4700::1111', family: 6 }],
            dns_timeout: () => new Promise(() => {}),
        };
        for (const [name, resolve] of Object.entries(cases)) {
            const e = await refusal(createAshbyTransport({ testing: { resolve, port: ports.good, ca: CA }, deadline: fast(80) }).send('ApiJobPosting', JOB));
            assert.deepEqual([e.kind, e.reason], ['availability', name.replace(/2$/, '')], name);
        }
    });

    test('connects only to the validated address, never to a name', async () => {
        good.state.handler = json({ data: {} });
        let asked = null;
        const t = createAshbyTransport({ testing: { resolve: async (h) => { asked = h; return [{ address: '127.0.0.1', family: 4 }]; }, addressAllowed: () => true, port: ports.good, ca: CA } });
        await t.send('ApiJobPosting', JOB);
        assert.equal(asked, 'jobs.ashbyhq.com');
    });
});

describe('what the server may answer', () => {
    const run = (handler, over) => { good.state.handler = handler; return refusal(transportFor('good', over).send('ApiJobPosting', JOB)); };

    test('a redirect is a security refusal and is never followed', async () => {
        for (const status of [301, 302, 303, 307, 308]) {
            const e = await run((req, res) => { res.writeHead(status, { Location: 'https://127.0.0.1/elsewhere' }); res.end(); });
            assert.deepEqual([e.kind, e.reason, e.detail], ['security', 'redirect_refused', String(status)]);
        }
        assert.equal(good.state.requests.length, 5, 'one request per attempt, no second hop');
    });

    test('an HTTP error is an availability failure', async () => {
        for (const status of [400, 403, 404, 429, 500, 502, 503]) {
            const e = await run(json({}, status));
            assert.deepEqual([e.kind, e.reason, e.detail], ['availability', 'http_status', String(status)]);
        }
    });

    test('a non-JSON, encoded or malformed body is an availability failure', async () => {
        assert.equal((await run((req, res) => { res.writeHead(200, { 'Content-Type': 'text/html' }); res.end('<html>'); })).reason, 'not_json');
        assert.equal((await run((req, res) => { res.writeHead(200, { 'Content-Type': 'application/json', 'Content-Encoding': 'gzip' }); res.end('x'); })).reason, 'unexpected_encoding');
        for (const body of ['{"data":', 'not json', '', '[1,2', '\u0000']) {
            const e = await run((req, res) => { res.writeHead(200, { 'Content-Type': 'application/json' }); res.end(body); });
            assert.deepEqual([e.kind, e.reason], ['availability', 'malformed_body'], JSON.stringify(body));
        }
    });

    test('an oversized response is a security refusal, by declared length and by streamed bytes', async () => {
        const declared = await run((req, res) => { res.writeHead(200, { 'Content-Type': 'application/json', 'Content-Length': String(LIMITS.maxResponseBytes + 1) }); res.write('{'); setTimeout(() => res.destroy(), 50); });
        assert.deepEqual([declared.kind, declared.reason], ['security', 'response_too_large']);

        const streamed = await run((req, res) => {
            res.writeHead(200, { 'Content-Type': 'application/json' });
            const chunk = Buffer.alloc(64 * 1024, 0x20);
            let sent = 0;
            const pump = () => { while (sent <= LIMITS.maxResponseBytes + chunk.length) { sent += chunk.length; if (!res.write(chunk)) return res.once('drain', pump); } res.end('{}'); };
            pump();
        });
        assert.deepEqual([streamed.kind, streamed.reason], ['security', 'response_too_large']);
    });

    test('a response just under the limit is accepted', async () => {
        const pad = 'x'.repeat(LIMITS.maxResponseBytes - 100);
        good.state.handler = json({ pad });
        const r = await transportFor().send('ApiJobPosting', JOB);
        assert.equal(r.json.pad.length, pad.length);
    });

    test('set-cookie is ignored and never replayed', async () => {
        good.state.handler = json({ data: {} }, 200, { 'Set-Cookie': 'sid=abc; Path=/' });
        const t = transportFor();
        await t.send('ApiOrganizationFromHostedJobsPageName', ORG);
        await t.send('ApiJobPosting', JOB);
        assert.ok(good.state.requests.every((r) => !('cookie' in r.headers)));
    });
});

describe('time and the shared deadline', () => {
    test('a server that never answers is a request timeout, cut to the deadline', async () => {
        good.state.handler = () => {};
        const started = Date.now();
        const e = await refusal(transportFor('good', { deadline: fast(150) }).send('ApiJobPosting', JOB));
        assert.deepEqual([e.kind, e.reason], ['availability', 'request_timeout']);
        assert.ok(Date.now() - started < 2000);
    });

    test('a body that stalls after the headers is a timeout too', async () => {
        good.state.handler = (req, res) => { res.writeHead(200, { 'Content-Type': 'application/json' }); res.write('{"a":'); };
        assert.equal((await refusal(transportFor('good', { deadline: fast(150) }).send('ApiJobPosting', JOB))).reason, 'request_timeout');
    });

    test('an expired deadline sends nothing', async () => {
        const e = await refusal(transportFor('good', { deadline: { expired: () => true, slice: () => 1 } }).send('ApiJobPosting', JOB));
        assert.deepEqual([e.kind, e.reason], ['availability', 'deadline_exceeded']);
        assert.equal(good.state.requests.length, 0);
    });

    test('a refused connection is an availability failure', async () => {
        const dead = await new Promise((r) => { const s = https.createServer(); s.listen(0, '127.0.0.1', () => { const p = s.address().port; s.close(() => r(p)); }); });
        const e = await refusal(createAshbyTransport({ testing: { resolve: answers(), addressAllowed: () => true, port: dead, ca: CA } }).send('ApiJobPosting', JOB));
        assert.deepEqual([e.kind, e.reason, e.detail], ['availability', 'connection_failed', 'ECONNREFUSED']);
    });
});

describe('TLS', () => {
    test('a certificate for another name is a security refusal, and nothing is sent', async () => {
        const e = await refusal(transportFor('wrong').send('ApiJobPosting', JOB));
        assert.deepEqual([e.kind, e.reason], ['security', 'tls_failure']);
        assert.equal(wrong.state.requests.length, 0);
    });

    test('a self-signed certificate for the right name is a security refusal', async () => {
        const e = await refusal(transportFor('untrusted').send('ApiJobPosting', JOB));
        assert.deepEqual([e.kind, e.reason], ['security', 'tls_failure']);
        assert.equal(untrusted.state.requests.length, 0);
    });

    test('a certificate from a CA the worker does not trust is a security refusal (no test CA = the real trust store)', async () => {
        const t = createAshbyTransport({ testing: { resolve: answers(), addressAllowed: () => true, port: ports.good } });
        const e = await refusal(t.send('ApiJobPosting', JOB));
        assert.deepEqual([e.kind, e.reason], ['security', 'tls_failure']);
        assert.equal(good.state.requests.length, 0);
    });

    test('verification is on: the trusted fixture succeeds only because its chain and name are valid', async () => {
        good.state.handler = json({ ok: true });
        assert.deepEqual((await transportFor().send('ApiJobPosting', JOB)).json, { ok: true });
    });
});
