// Static guarantees about WHERE the Ashby transport lives and what can reach it. These read the sources, so a later edit that
// widens the transport's reach, or hands the browser a POST path, fails here rather than in review.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { ALLOWED_NON_GET } from '../src/guard.js';

const root = path.join(path.dirname(fileURLToPath(import.meta.url)), '..', 'src');
const walk = (d) => readdirSync(d).flatMap((f) => (statSync(path.join(d, f)).isDirectory() ? walk(path.join(d, f)) : [path.join(d, f)])).filter((f) => f.endsWith('.js'));
const files = walk(root).map((f) => [path.relative(root, f), readFileSync(f, 'utf8')]);
const code = (text) => text.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');
const withText = (re) => files.filter(([, t]) => re.test(code(t))).map(([f]) => f).sort();

test('only the transport module opens an HTTPS connection; fetch is only the Well Applied client', () => {
    assert.deepEqual(withText(/node:https|node:http2?['"]|from ['"]undici|require\(['"]https?['"]\)/), ['transport/ashby.js']);
    assert.deepEqual(withText(/\bfetch\s*\(/), ['client.js']);
    assert.deepEqual(withText(/\bXMLHttpRequest\b|\bWebSocket\b|\bnode:tls\b|\bnode:dgram\b/), []);
});

test('the browser side imports nothing from the transport, and the transport imports nothing from the browser side', () => {
    const browserSide = files.filter(([f]) => /^(browser|guard|page-context|page-kind|apply-links)\.js$|^discovery\/|^extractors\//.test(f));
    assert.ok(browserSide.length > 8);
    for (const [f, t] of browserSide) assert.ok(!/transport\//.test(code(t)), `${f} reaches the transport`);
    for (const [f, t] of files.filter(([f]) => f.startsWith('transport/'))) assert.ok(!/from ['"]\.\.\/(browser|guard|discovery|extractors|page-)/.test(code(t)), `${f} reaches the browser side`);
});

test('nothing exposes a worker function to page JavaScript', () => {
    assert.deepEqual(withText(/\.exposeFunction\b|\.exposeBinding\b|\.addInitScript\b|\.addScriptTag\b/), []);
});

test('the browser guard still allows no non-GET request, and knows nothing about Ashby', () => {
    assert.deepEqual([...ALLOWED_NON_GET], []);
    const guard = code(files.find(([f]) => f === 'guard.js')[1]);
    assert.ok(!/ashby/i.test(guard));
    assert.deepEqual([...guard.matchAll(/ALLOWED_METHODS = new Set\((\[[^\]]*\])\)/g)].map((m) => m[1]), ["['GET', 'HEAD']"]);
});

test('the transport destination is a constant: no URL, host or header comes from a parameter, the environment or a claim', () => {
    const t = code(files.find(([f]) => f === 'transport/ashby.js')[1]);
    assert.ok(!/process\.env/.test(t));
    assert.deepEqual([...t.matchAll(/host: ([^,\n]+),/g)].map((m) => m[1]), ["'jobs.ashbyhq.com'", 'address']); // the constant's own definition, and the vetted address
    assert.ok(/ASHBY_ENDPOINT = Object\.freeze\(\{ host: 'jobs\.ashbyhq\.com', port: 443, path: '\/api\/non-user-graphql' \}\)/.test(t));
    assert.ok(/servername: ASHBY_ENDPOINT\.host/.test(t) && /rejectUnauthorized: true/.test(t));
    assert.ok(!/rejectUnauthorized: false|NODE_TLS_REJECT_UNAUTHORIZED/.test(t));
    assert.ok(!/redirect|followRedirect|maxRedirects/i.test(t.replace(/redirect_refused|3xx|status >= 300/g, '')));
    assert.ok(!/\.setHeader\(|headers: \{[^}]*\.\.\.(options|opts|extra|callerHeaders)/.test(t));
});

test('`testing` is passed by tests only: production code never supplies it', () => {
    assert.deepEqual(withText(/\btesting\s*[:=]|testing:\s*\{/).filter((f) => f !== 'transport/ashby.js'), []);
    const inspect = code(files.find(([f]) => f === 'inspect.js')[1]);
    assert.ok(/createAshbyTransport\(\{ deadline: d \}\)/.test(inspect));
});

test('the worker\'s own environment reads for this feature are the one flag, exact-match', () => {
    assert.deepEqual(withText(/BROWSER_WORKER_ASHBY_SCHEMA/), ['transport/flag.js']);
    assert.ok(/=== '1'/.test(code(files.find(([f]) => f === 'transport/flag.js')[1])));
});
