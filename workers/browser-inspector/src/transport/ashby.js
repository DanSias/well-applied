// The ONLY code in this worker that sends a POST to anywhere but Well Applied (via the relay). It is a plain Node HTTPS client,
// not Chromium: the browser's GET/HEAD-only guard is untouched, and page JavaScript cannot reach this module (it runs in the
// worker's own process, and nothing here is exposed to the page or built from page content).
//
// What may leave, and where:
//   - exactly one destination, fixed below: https://jobs.ashbyhq.com:443/api/non-user-graphql?op=<operationName>. No caller supplies a
//     URL, host, port, path, header or method. The operationName in the query string is one of the two approved names.
//   - the body is whatever buildRequest() returns (pinned query document, exact variables), re-validated on every call.
//   - a small fixed header set: no cookies, no Authorization, no caller-supplied headers, no compression.
//   - at most LIMITS.maxRequests requests per transport (one per approved operation), one attempt each, no retry.
//
// How the connection is made: the hostname is resolved here; EVERY answer must be a public address (a single private,
// loopback, link-local, carrier-grade-NAT or otherwise prohibited answer refuses the whole lookup); the socket then connects to
// that vetted IPv4 address only, with TLS verified against the real hostname (SNI and certificate name), and the connected
// address is checked again. No redirect is followed; any 3xx is a refusal.
//
// Failures come in two kinds. 'availability': the service or network did not give a usable answer (timeout, refused connection,
// HTTP error, unparsable body); the caller may fall back to the read-only browser path. 'security': something that should never
// happen did (a prohibited address, a TLS failure, a redirect, an oversized body, a request this adapter would not allow); the
// caller must stop and say so. Nothing here retries or falls back by itself.
import https from 'node:https';
import dns from 'node:dns';
import { buildRequest, AshbyRequestError } from '../providers/ashby/request.js';
import { APPROVED_OPERATIONS } from '../providers/ashby/documents.js';
import { isPrivateAddress } from '../url-safety.js';

export const ASHBY_ENDPOINT = Object.freeze({ host: 'jobs.ashbyhq.com', port: 443, path: '/api/non-user-graphql' });
export const LIMITS = Object.freeze({
    maxRequests: 2,
    maxRequestBytes: 12_000,
    maxResponseBytes: 1_000_000,
    dnsTimeoutMs: 3_000,
    connectTimeoutMs: 5_000,
    requestTimeoutMs: 10_000,
});
// Fixed. No caller header is ever merged in. 'identity' keeps a compressed-body bomb out; Connection: close keeps one socket per attempt.
const HEADERS = Object.freeze({ 'Content-Type': 'application/json', Accept: 'application/json', 'Accept-Encoding': 'identity', 'User-Agent': 'well-applied-inspector', Connection: 'close' });
const TLS_FAILURE = /CERT|TLS|SSL|ALTNAME|UNABLE_TO_VERIFY|SELF_SIGNED|HOSTNAME_MISMATCH|DEPTH_ZERO/i;

export class AshbyTransportError extends Error {
    /** @param {'security'|'availability'} kind */
    constructor(kind, reason, detail = null) {
        super(detail ? `${reason}: ${detail}` : reason);
        this.name = 'AshbyTransportError';
        this.kind = kind;
        this.reason = reason;
        this.detail = detail;
    }
}
const security = (reason, detail) => new AshbyTransportError('security', reason, detail);
const availability = (reason, detail) => new AshbyTransportError('availability', reason, detail);

async function defaultResolve(hostname) {
    return dns.promises.lookup(hostname, { all: true });
}

const withTimeout = (promise, ms, onTimeout) =>
    new Promise((resolve, reject) => {
        const timer = setTimeout(() => reject(onTimeout()), ms);
        promise.then(
            (v) => { clearTimeout(timer); resolve(v); },
            (e) => { clearTimeout(timer); reject(e); },
        );
    });

/**
 * @param {object} [options]
 * @param {{expired: () => boolean, slice: (ms: number) => number}} [options.deadline] the inspection's own deadline: every wait is cut to what is left of it
 * @param {object} [options.testing] TESTS ONLY (a local HTTPS fixture with fake DNS). Production code never passes this, and
 *   nothing reads it from the environment, a claim or a page; test/ashby-transport.test.js asserts that. It can replace the
 *   resolver, the address rule, the port and the trusted CAs, but never the host, path, headers or method.
 */
export function createAshbyTransport({ deadline = null, testing = null } = {}) {
    const resolve = testing?.resolve ?? defaultResolve;
    const addressAllowed = testing?.addressAllowed ?? ((address) => !isPrivateAddress(address));
    const port = testing?.port ?? ASHBY_ENDPOINT.port;
    const tls = testing?.ca ? { ca: testing.ca } : {};
    const sent = new Set();

    const budgetMs = (max) => (deadline === null ? max : deadline.slice(max));

    /** Resolves the fixed host and returns the one IPv4 address to connect to, or throws. */
    async function vetAddress() {
        let answers;
        try {
            answers = await withTimeout(resolve(ASHBY_ENDPOINT.host), budgetMs(LIMITS.dnsTimeoutMs), () => availability('dns_timeout'));
        } catch (error) {
            if (error instanceof AshbyTransportError) throw error;
            throw availability('dns_failed', error?.code ?? null);
        }
        if (!Array.isArray(answers) || answers.length === 0) throw availability('dns_failed', 'no answers');
        const banned = answers.filter((a) => typeof a?.address !== 'string' || !addressAllowed(a.address));
        if (banned.length > 0) throw security('prohibited_address', 'a DNS answer was not a public address');
        const v4 = answers.find((a) => a.family === 4);
        if (!v4) throw availability('dns_no_ipv4');

        return v4.address;
    }

    function post(address, path, payload) {
        return new Promise((resolvePost, rejectPost) => {
            let settled = false;
            let req;
            const timers = [];
            const finish = (fn, value) => {
                if (settled) return;
                settled = true;
                timers.forEach(clearTimeout);
                fn(value);
            };
            const fail = (error) => {
                finish(rejectPost, error);
                req?.destroy();
            };

            const requestMs = budgetMs(LIMITS.requestTimeoutMs);
            timers.push(setTimeout(() => fail(availability('request_timeout')), requestMs));
            const connectTimer = setTimeout(() => fail(availability('connect_timeout')), Math.min(budgetMs(LIMITS.connectTimeoutMs), requestMs));
            timers.push(connectTimer);

            req = https.request(
                {
                    method: 'POST',
                    host: address,
                    port,
                    path,
                    family: 4,
                    agent: false,
                    servername: ASHBY_ENDPOINT.host,
                    rejectUnauthorized: true,
                    headers: { ...HEADERS, Host: ASHBY_ENDPOINT.host, 'Content-Length': Buffer.byteLength(payload) },
                    ...tls,
                },
                (res) => {
                    if (res.socket?.remoteAddress !== address) return fail(security('address_mismatch'));
                    const status = res.statusCode ?? 0;
                    if (status >= 300 && status < 400) return fail(security('redirect_refused', String(status)));
                    if (status !== 200) return fail(availability('http_status', String(status)));
                    if (!/^application\/json\b/i.test(String(res.headers['content-type'] ?? ''))) return fail(availability('not_json'));
                    if (res.headers['content-encoding'] && res.headers['content-encoding'] !== 'identity') return fail(availability('unexpected_encoding'));
                    if (Number(res.headers['content-length']) > LIMITS.maxResponseBytes) return fail(security('response_too_large'));

                    const chunks = [];
                    let total = 0;
                    res.on('data', (chunk) => {
                        total += chunk.length;
                        if (total > LIMITS.maxResponseBytes) return fail(security('response_too_large'));
                        chunks.push(chunk);
                    });
                    res.on('error', (error) => fail(availability('connection_failed', error?.code ?? null)));
                    res.on('aborted', () => fail(availability('connection_failed', 'aborted')));
                    res.on('end', () => {
                        try {
                            finish(resolvePost, { status, json: JSON.parse(Buffer.concat(chunks).toString('utf8')), remoteAddress: address });
                        } catch {
                            fail(availability('malformed_body'));
                        }
                    });
                },
            );
            req.on('socket', (socket) => socket.once('connect', () => clearTimeout(connectTimer)));
            req.on('error', (error) => {
                const code = error?.code ?? '';
                fail(TLS_FAILURE.test(code) || TLS_FAILURE.test(String(error?.message)) ? security('tls_failure', code || null) : availability('connection_failed', code || null));
            });
            req.end(payload);
        });
    }

    /**
     * One request, one attempt. Throws AshbyTransportError; never returns a partial or unvalidated body.
     * @param {string} operationName one of the approved operations
     * @param {object} variables validated again by buildRequest()
     * @returns {Promise<{json: object, ms: number}>}
     */
    async function send(operationName, variables) {
        if (sent.size >= LIMITS.maxRequests) throw security('request_budget_exhausted');
        if (sent.has(operationName)) throw security('repeat_operation', 'one attempt per operation');

        let body;
        try {
            body = buildRequest(operationName, variables);
        } catch (error) {
            if (error instanceof AshbyRequestError) throw security('request_refused', error.reason);
            throw error;
        }
        const payload = JSON.stringify(body);
        if (Buffer.byteLength(payload) > LIMITS.maxRequestBytes) throw security('request_refused', 'request_too_large');
        // buildRequest() proved operationName is an approved key, so this interpolates one of two known constants.
        if (!Object.hasOwn(APPROVED_OPERATIONS, body.operationName)) throw security('request_refused', 'unknown_operation');
        if (deadline?.expired()) throw availability('deadline_exceeded');

        sent.add(operationName);
        const startedAt = Date.now();
        const address = await vetAddress();
        const { json } = await post(address, `${ASHBY_ENDPOINT.path}?op=${body.operationName}`, payload);

        return { json, ms: Date.now() - startedAt };
    }

    return { send, requestsUsed: () => sent.size };
}
