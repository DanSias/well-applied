// The batch runner is offline, has no live mode, and cannot reach production data. These tests keep it that way.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { NAMES } from './support/resolution-sites.js';

const ROOT = path.join(path.dirname(fileURLToPath(import.meta.url)), '..');
const dir = path.join(ROOT, 'eval', 'runner');
const js = readdirSync(dir).filter((f) => f.endsWith('.js'));
const code = (f) => readFileSync(path.join(dir, f), 'utf8').split('\n').filter((l) => !/^\s*(\/\/|\/\*|\*|#!)/.test(l)).join('\n');
const imports = (f) => [...readFileSync(path.join(dir, f), 'utf8').matchAll(/^\s*import\b[^'"\n]*?from\s+['"]([^'"]+)['"]/gm)].map((m) => m[1]);

test('the core (batch, sanitize, report) is pure: it imports only the canonical hasher and its siblings, and cannot touch a file, the network, a browser or a clock', () => {
    for (const f of ['batch.js', 'sanitize.js', 'report.js', 'authorization.js', 'pacer.js']) {
        for (const i of imports(f)) assert.ok(['../src/canon.js', './sanitize.js', './authorization.js', './pacer.js'].includes(i), `${f} imports ${i}`);
        const text = code(f);
        for (const w of [/node:/, /\bfetch\s*\(/, /playwright/, /process\./, /Date\.now|new Date|Math\.random|setTimeout|setInterval/, /\bimport\s*\(|\brequire\s*\(/]) assert.equal(w.test(text), false, `${f} matches ${w}`);
    }
});

test('only the dry-run module reaches the inspector, and only the resolver and the local fixture sites', () => {
    assert.deepEqual(imports('dry-run.js').sort(), ['../../src/resolution/resolve.js', '../../test/support/resolution-sites.js']);
    for (const f of js.filter((x) => x !== 'dry-run.js')) assert.equal(imports(f).some((i) => /\.\.\/\.\.\/(src|test)\//.test(i)), false, f);
});

test('nothing in the runner can reach a database, a production table, the relay or the Laravel client', () => {
    for (const f of js) {
        const text = code(f);
        for (const w of [/sqlite|mysql|pgsql|database/i, /job_postings|applications|agent_runs|application_questions|workflow_|career_profile|opportunit/i, /\brelay\b|client\.js|poll\.js|config\.js/, /node:(net|http|https|dns|tls|dgram|child_process|worker_threads)/, /\bfetch\s*\(/]) assert.equal(w.test(text), false, `${f} matches ${w}`);
    }
});

test('live mode exists only behind an authorization: the core validates it, the CLI needs --supervised and never reuses a run directory', () => {
    const cli = readFileSync(path.join(dir, 'cli.js'), 'utf8');
    assert.match(cli, /\['plan', 'dry-run', 'live'\]\.includes\(command\)/);
    assert.match(cli, /live needs --supervised/);
    assert.match(cli, /already exists: a run is never resumed or repeated/);
    const batch = readFileSync(path.join(dir, 'batch.js'), 'utf8');
    assert.match(batch, /validateAuthorization\(authorization, \{ manifest, manifestSha, now: clock\.now\(\) \}\)/);
    assert.match(batch, /if \(mode === 'live'\) \{/);
    assert.equal(/testing/.test(cli), false, 'the CLI never passes the tests-only options to the live adapter');
});

test('tracker redirects default to off in the runner\'s own defaults, and the resolver is only ever given a per-case decision', () => {
    const batch = readFileSync(path.join(dir, 'batch.js'), 'utf8');
    assert.match(batch, /allow_tracker_redirects: false, tracker_approval: null/);
    assert.match(batch, /allowTrackerRedirects: trackerApproved\.has\(c\.id\)/);
});

test('the dry-run batch names only local fixture hosts, and the shipped samples contain no query string, port or credential', () => {
    const batch = JSON.parse(readFileSync(path.join(dir, 'fixtures/dry-run-batch.json'), 'utf8'));
    const hosts = new Set(Object.values(NAMES));
    for (const c of batch.cases) assert.ok(hosts.has(new URL(c.fixture.logical_url).hostname), c.id);
    for (const f of ['samples/dry-run/report.json', 'samples/dry-run/report.md', 'samples/plan/report.json', 'samples/plan/report.md']) {
        const text = readFileSync(path.join(dir, f), 'utf8');
        assert.equal(/\?[a-z_]+=|:\d{4,5}\b|cookie|authorization|token|"password"/i.test(text), false, f);
    }
});

test('no committed file in the runner directory is a page snapshot or carries personal data', () => {
    const all = readdirSync(dir, { recursive: true });
    assert.equal(all.some((f) => /\.(html?|png|jpe?g|pdf|har)$/i.test(f)), false);
    for (const f of all.filter((n) => /\.(js|json|md)$/.test(n))) assert.equal(/[\w.+-]+@[\w-]+\.[\w.]+/.test(readFileSync(path.join(dir, f), 'utf8')), false, f);
});
