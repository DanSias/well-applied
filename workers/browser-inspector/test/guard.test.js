// The navigation guard, against local fixture servers that COUNT what reaches them: the point of each case is that a
// forbidden request never arrives, not merely that the result says so.
import { test, describe, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { createGuard, verifyConnectedAddress, ALLOWED_NON_GET, MAX_REDIRECTS } from '../src/guard.js';
import { inspect } from '../src/inspect.js';
import { startSites } from './support/fixture-server.js';

const hits = { post: 0, formPost: 0, beacon: 0, download: 0, popup: 0, late: 0 };
let sites;

before(async () => {
    const chain = {};
    for (let i = 1; i <= 11; i++) {
        chain[`/r${i}`] = ({ origins }) => ({ status: 302, headers: { location: `${origins.main}/r${i + 1}` } });
    }
    chain['/r12'] = () => ({ body: '<title>End</title>end' });

    sites = await startSites({
        main: {
            ...chain,
            '/short-chain-start': ({ origins }) => ({ status: 302, headers: { location: `${origins.main}/short-chain-end` } }),
            '/short-chain-end': () => ({ body: '<title>Arrived</title><p>no way to apply here</p>' }),
            '/mid-then-private': ({ origins }) => ({ status: 302, headers: { location: `${origins.main}/mid-hop` } }),
            '/mid-hop': () => ({ status: 302, headers: { location: 'http://169.254.169.254/latest/meta-data/' } }),
            '/scheme-hop': () => ({ status: 302, headers: { location: 'file:///etc/passwd' } }),
            '/posts': () => ({
                body: `<title>Posts</title><p>nothing to apply to</p><script>
                    fetch('/api/post', { method: 'POST', body: 'x' }).catch(() => {});
                    navigator.sendBeacon('/api/beacon', 'x');
                    const img = new Image(); img.src = 'http://10.0.0.7/pixel.gif'; document.body.append(img);
                    setTimeout(() => { const f = document.createElement('form'); f.method = 'POST'; f.action = '/api/form'; document.body.append(f); f.submit(); }, 100);
                    window.open('/popup');
                </script>`,
            }),
            '/api/post': () => { hits.post++; return { body: '{}' }; },
            '/api/beacon': () => { hits.beacon++; return { body: '' }; },
            '/api/form': () => { hits.formPost++; return { body: '<title>Posted</title>posted' }; },
            '/popup': () => { hits.popup++; return { body: '<title>Popup</title>popup' }; },
            '/download': () => ({ headers: { 'content-type': 'application/octet-stream', 'content-disposition': 'attachment; filename=x.bin' }, body: 'bytes' }),
            '/links-to-download': ({ origins }) => ({ body: `<title>Jobs</title><a href="${origins.main}/download">Apply Now</a>` }),
        },
    });
});

after(() => sites.close());

const run = (path, extra = {}) =>
    inspect(`${sites.origins.main}${path}`, { guard: sites.guard(), launch: sites.launch(), timeouts: { settleMs: 400, networkIdleMs: 800 }, budgetMs: 60_000, ...extra });

describe('redirect hops are judged before they are followed', () => {
    test('an ordinary same-fixture redirect is followed and recorded', async () => {
        const result = await run('/short-chain-start');

        assert.equal(result.inspection_outcome, 'application_not_found');
        assert.deepEqual(result.diagnostics.navigation.map((n) => n.kind), ['redirect', 'start']);
    });

    test(`a chain longer than ${MAX_REDIRECTS} redirects is cut off`, async () => {
        const result = await run('/r1');

        assert.equal(result.inspection_outcome, 'needs_application_url');
        assert.equal(result.stop_reason, 'too_many_redirects');
    });

    test('a redirect to the cloud-metadata address after a legitimate hop is refused, and never requested', async () => {
        const result = await run('/mid-then-private');

        assert.equal(result.stop_reason, 'destination_prohibited');
    });

    test('a redirect to a non-http scheme is refused', async () => {
        const result = await run('/scheme-hop');

        assert.equal(result.stop_reason, 'destination_prohibited');
    });
});

describe('inspection is read-only', () => {
    test('a page that POSTs by fetch, beacon and form submission, and opens a popup, reaches nothing it asked for', async () => {
        const result = await run('/posts');

        assert.equal(result.status, 'succeeded');
        assert.deepEqual({ ...hits }, { post: 0, formPost: 0, beacon: 0, download: 0, popup: 0, late: 0 }, 'no write, and no popup, reached the server');
        assert.ok(result.warnings.includes('blocked_non_get'), 'blocked writes are reported as warnings');
        assert.ok(result.warnings.includes('blocked_prohibited'), 'a subresource aimed at a private address is blocked and reported');
    });

    test('downloads are off: a link to an attachment is followed as a navigation and nothing is saved', async () => {
        const result = await run('/links-to-download');

        // The attachment never renders as a page, so the inspection ends without reaching a form.
        assert.equal(result.status, 'succeeded');
        assert.notEqual(result.inspection_outcome, 'complete');
    });

    test('no non-GET exception exists today', () => {
        assert.deepEqual([...ALLOWED_NON_GET], []);
    });
});

describe('checkUrl', () => {
    test('refuses a public-looking name that RESOLVES to a private address, and asks DNS once per host', async () => {
        let lookups = 0;
        const guard = createGuard({
            resolve: async (url) => {
                lookups++;
                return url.hostname === 'rebinder.example.com' ? { safe: false, reason: 'resolved_to_private_address' } : { safe: true };
            },
        });

        assert.deepEqual(await guard.checkUrl('https://rebinder.example.com/apply'), { ok: false, reason: 'resolved_to_private_address' });
        assert.equal((await guard.checkUrl('https://rebinder.example.com/other')).ok, false);
        assert.equal((await guard.checkUrl('https://boards.greenhouse.io/acme/jobs/1')).ok, true);
        assert.equal(lookups, 2, 'one lookup per host, cached');
    });

    test('refuses unsafe schemes, credentials and private literals before any DNS lookup', async () => {
        const guard = createGuard({ resolve: async () => { throw new Error('DNS must not be consulted'); } });

        for (const url of ['file:///etc/passwd', 'javascript:alert(1)', 'ftp://example.com/x', 'https://u:p@example.com/', 'http://127.0.0.1/', 'http://[::1]/', 'http://10.1.2.3/', 'http://169.254.169.254/', 'http://100.64.0.1/', 'http://0.0.0.0/']) {
            assert.equal((await guard.checkUrl(url)).ok, false, url);
        }
    });

    test('the fixture allow-list is exact host:port and nothing broader', async () => {
        const guard = createGuard({ allowHosts: ['127.0.0.1:5555'], resolve: async () => ({ safe: false, reason: 'no' }) });

        assert.equal((await guard.checkUrl('http://127.0.0.1:5555/x')).ok, true);
        assert.equal((await guard.checkUrl('http://127.0.0.1:5556/x')).ok, false);
        assert.equal((await guard.checkUrl('http://localhost:5555/x')).ok, false);
    });
});

describe('verifyConnectedAddress', () => {
    const fakeResponse = (url, ip) => ({
        request: () => ({ url: () => url, redirectedFrom: () => null }),
        serverAddr: async () => (ip === null ? null : { ipAddress: ip, port: 443 }),
    });

    test('flags a connection that landed on a private address even though the name looked public', async () => {
        const verdict = await verifyConnectedAddress(fakeResponse('https://public.example.com/apply?token=x', '10.0.0.9'), createGuard());

        assert.deepEqual(verdict, { url: 'https://public.example.com/apply', reason: 'connected_to_private_address' });
    });

    test('accepts a public address, an IPv4-mapped public address, and a cached response with no address', async () => {
        const guard = createGuard();

        assert.equal(await verifyConnectedAddress(fakeResponse('https://a.example.com/', '93.184.216.34'), guard), null);
        assert.equal(await verifyConnectedAddress(fakeResponse('https://a.example.com/', '::ffff:93.184.216.34'), guard), null);
        assert.equal(await verifyConnectedAddress(fakeResponse('https://a.example.com/', null), guard), null);
    });

    test('flags an IPv4-mapped private address', async () => {
        const verdict = await verifyConnectedAddress(fakeResponse('https://a.example.com/', '::ffff:192.168.0.4'), createGuard());

        assert.equal(verdict?.reason, 'connected_to_private_address');
    });
});
