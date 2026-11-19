// Local fixture sites for navigation tests: no live network, ever. Two or more servers on 127.0.0.1 stand in for
// "the job board" and "the employer", plus (via Chromium's host-resolver rules) hosts named like real ATSs, all served from
// disk. The guard used in these tests only allows those exact host:port pairs, and its DNS check refuses everything else,
// so a stray subresource (a CDN script reference in a fixture) is blocked instead of leaving the machine.
import http from 'node:http';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import { chromium } from 'playwright';
import { createGuard } from '../../src/guard.js';

const dir = path.join(path.dirname(fileURLToPath(import.meta.url)), '..', 'fixtures');

export function fixture(name) {
    return readFileSync(path.join(dir, name), 'utf8');
}

/**
 * @param {Record<string, (ctx: {origins: Record<string,string>}) => {status?: number, headers?: object, body?: string, hang?: boolean}>} routes
 *   keyed by path. `%%name%%` in a body is replaced by that server's origin.
 */
export async function startSite(routes, origins) {
    const sockets = new Set();
    const server = http.createServer((req, res) => {
        const url = new URL(req.url, 'http://x');
        const route = routes[url.pathname];
        if (!route) {
            res.writeHead(404, { 'content-type': 'text/html' });
            return res.end('<html><title>Not found</title><body>Not found</body></html>');
        }
        const out = route({ origins, method: req.method });
        if (out.hang) return; // never answers: a request that stays open
        const body = (out.body ?? '').replace(/%%(\w+)%%/g, (_, k) => origins[k] ?? '');
        res.writeHead(out.status ?? 200, { 'content-type': 'text/html; charset=utf-8', ...(out.headers ?? {}) });
        res.end(body);
    });
    server.on('connection', (s) => {
        sockets.add(s);
        s.on('close', () => sockets.delete(s));
    });
    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));

    return {
        port: server.address().port,
        close: () => {
            for (const s of sockets) s.destroy();
            return new Promise((resolve) => server.close(resolve));
        },
    };
}

/**
 * Starts the named sites, wiring every site's origin into every other's `%%name%%` placeholders.
 * Each site is reachable as http://127.0.0.1:PORT; `named` hosts (e.g. jobs.lever.co) are mapped to 127.0.0.1 inside the
 * browser only, at the same port.
 */
export async function startSites(definitions) {
    const origins = {};
    const sites = {};

    // Reserve ports first so bodies can reference one another.
    for (const [name, routes] of Object.entries(definitions)) {
        sites[name] = await startSite(routes, origins);
        origins[name] = `http://127.0.0.1:${sites[name].port}`;
    }

    const hosts = Object.values(sites).map((s) => `127.0.0.1:${s.port}`);

    return {
        origins,
        hosts,
        site: (name) => sites[name],
        guard: (extraHosts = []) =>
            createGuard({
                allowHosts: [...hosts, ...extraHosts],
                // Nothing else is reachable: a stray reference is refused, never fetched.
                resolve: async () => ({ safe: false, reason: 'test_no_network' }),
            }),
        launch: (mapped = []) => () =>
            chromium.launch({
                headless: true,
                // As in production: Playwright adds --no-sandbox unless this is true. isolation/validate-hardening.sh sets it,
                // so the whole browser suite runs under Chromium's own sandbox where the container allows it.
                chromiumSandbox: process.env.BROWSER_WORKER_CHROMIUM_SANDBOX === '1',
                args: mapped.length ? [`--host-resolver-rules=${mapped.join(', ')}`] : [],
            }),
        close: () => Promise.all(Object.values(sites).map((s) => s.close())),
    };
}
