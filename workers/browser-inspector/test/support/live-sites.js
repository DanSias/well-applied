// LOCAL HTTPS fixtures for the live adapter. Hosts named like real sites are served by test-only TLS servers on 127.0.0.1 and mapped inside the browser only;
// the browser trusts exactly one test public key. Synthetic: authored from URL shapes, never captured. Nothing here can reach the internet: the address check
// vouches for these fixture names only, and everything else goes through the REAL private-address check.
import https from 'node:https';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';
import { resolvesToPrivateAddress } from '../../src/url-safety.js';
import { fixture } from './fixture-server.js';

const tls = path.join(path.dirname(fileURLToPath(import.meta.url)), '..', 'fixtures', 'tls', 'live');
export const HOSTS = ['jobicy.com', 'himalayas.app', 'careers.acmecorp.com', 'job-boards.greenhouse.io', 'jobs.lever.co', 'go.linkforward-example.io', 'click.mailtrack-example.com', 'cdn.thirdparty-example.net'];
const UUID = '11111111-1111-4111-8111-111111111111';

export async function startLiveSites() {
    const hits = []; // [host, method, path]
    const ports = {};
    const origin = (host) => `https://${host}:${ports[host]}`;
    const page = (body) => () => ({ body });
    const link = (text, href) => `<title>Job</title><h1>Platform Engineer</h1><a href="${href}">${text}</a>`;

    const routes = {
        'jobicy.com': {
            '/jobs/301': () => ({ body: link('Apply Now', `${origin('careers.acmecorp.com')}/jobs/42`) }),
            '/jobs/302': () => ({ body: link('Apply Now', `${origin('careers.acmecorp.com')}/onward`) }),
            '/jobs/303': () => ({ body: link('Apply Now', `${origin('jobicy.com')}/go/abc123`) }),
            '/jobs/304': () => ({ body: link('Apply Now', `${origin('careers.acmecorp.com')}/jobs/42`) }),
            '/jobs/305': () => ({ body: `${link('Apply Now', `${origin('careers.acmecorp.com')}/jobs/42`)}<script src="${origin('cdn.thirdparty-example.net')}/x.js"></script><img src="${origin('cdn.thirdparty-example.net')}/pixel.gif">` }),
            '/jobs/306': page('<title>Job</title><h1>Engineer</h1><p>No way to apply here.</p>'),
            '/jobs/307': page('<title>Job</title><a href="http://10.0.0.5/apply">Apply Now</a>'),
            '/jobs/308': () => ({ hang: true }),
            '/jobs/309': () => ({ body: link('Apply Now', `${origin('click.mailtrack-example.com')}/c?url=${encodeURIComponent(`${origin('careers.acmecorp.com')}/jobs/42`)}`) }),
            '/jobs/310': () => ({ body: link('Apply Now', `${origin('careers.acmecorp.com')}/to-private`) }),
            '/go/abc123': () => ({ status: 302, headers: { location: `${origin('careers.acmecorp.com')}/jobs/42` } }),
        },
        'himalayas.app': {
            '/companies/acme/jobs/staff': () => ({ body: link('Apply now', `${origin('jobs.lever.co')}/acme/${UUID}/apply`) }),
            '/companies/acme/jobs/employer': () => ({ body: link('Apply now', `${origin('careers.acmecorp.com')}/jobs/42`) }),
            '/companies/acme/jobs/onward': () => ({ body: link('Apply now', `${origin('careers.acmecorp.com')}/onward`) }),
        },
        'careers.acmecorp.com': {
            '/jobs/42': () => ({ body: link('Apply for this job', `${origin('job-boards.greenhouse.io')}/acme/jobs/1?gh_jid=1`) }),
            '/onward': () => ({ status: 302, headers: { location: `${origin('go.linkforward-example.io')}/hop` } }),
            '/to-private': () => ({ status: 302, headers: { location: 'http://10.0.0.5:81/internal' } }),
        },
        'job-boards.greenhouse.io': { '/acme/jobs/1': () => ({ body: fixture('greenhouse-form.html') }) },
        'jobs.lever.co': { [`/acme/${UUID}/apply`]: () => ({ body: fixture('phase1/unknown-ats-form.html') }) },
        'go.linkforward-example.io': { '/hop': () => ({ status: 302, headers: { location: `${origin('job-boards.greenhouse.io')}/acme/jobs/1` } }) },
        'click.mailtrack-example.com': { '/c': () => ({ status: 302, headers: { location: `${origin('careers.acmecorp.com')}/jobs/42` } }) },
        'cdn.thirdparty-example.net': { '/x.js': () => ({ body: '/* x */', headers: { 'content-type': 'text/javascript' } }), '/pixel.gif': () => ({ body: 'GIF89a' }) },
    };

    const sockets = new Set();
    const servers = await Promise.all(HOSTS.map(async (host) => {
        const server = https.createServer({ key: readFileSync(path.join(tls, 'fixtures.key')), cert: readFileSync(path.join(tls, 'fixtures.pem')) }, (req, res) => {
            const u = new URL(req.url, 'https://x');
            hits.push([host, req.method, u.pathname]);
            const route = routes[host][u.pathname];
            if (!route) {
                res.writeHead(404, { 'content-type': 'text/html' });

                return res.end('<title>Not found</title>');
            }
            const out = route();
            if (out.hang) return;
            res.writeHead(out.status ?? 200, { 'content-type': 'text/html; charset=utf-8', ...(out.headers ?? {}) });
            res.end(out.body ?? '');
        });
        server.on('connection', (s) => {
            sockets.add(s);
            s.on('close', () => sockets.delete(s));
        });
        await new Promise((r) => server.listen(0, '127.0.0.1', r));
        ports[host] = server.address().port;

        return server;
    }));

    const spki = readFileSync(path.join(tls, 'spki.txt'), 'utf8').trim();
    const fixtureHosts = new Set(HOSTS);

    return {
        hits,
        origin,
        url: (host, p) => `${origin(host)}${p}`,
        hitsOn: (host, p = null) => hits.filter(([h, , path_]) => h === host && (p === null || path_ === p)).length,
        testing: (extra = {}) => ({
            // The fixture names are vouched for; EVERYTHING ELSE (an IP literal such as 10.0.0.5, any real name) goes through the real private-address check.
            addressCheck: async (url) => (fixtureHosts.has(url.hostname) ? { safe: true } : resolvesToPrivateAddress(url)),
            launch: () => chromium.launch({ headless: true, chromiumSandbox: process.env.BROWSER_WORKER_CHROMIUM_SANDBOX === '1', args: [`--host-resolver-rules=${HOSTS.map((h) => `MAP ${h} 127.0.0.1`).join(', ')}`, `--ignore-certificate-errors-spki-list=${spki}`] }),
            wrapGuard: (g) => ({ ...g, isAllowedHost: (u) => fixtureHosts.has(u.hostname) }),
            timeouts: { settleMs: 100, networkIdleMs: 800 },
            ...extra,
        }),
        close: () => {
            for (const s of sockets) s.destroy();

            return Promise.all(servers.map((s) => new Promise((r) => s.close(r))));
        },
    };
}
