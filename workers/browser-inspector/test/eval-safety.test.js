// The Stage 0 harness is offline, deterministic, separate from production, and stores no third-party page snapshots.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.join(path.dirname(fileURLToPath(import.meta.url)), '..');
const walk = (d) => readdirSync(d).sort().flatMap((n) => (statSync(path.join(d, n)).isDirectory() ? walk(path.join(d, n)) : [path.join(d, n)]));
const rel = (f) => path.relative(ROOT, f).split(path.sep).join('/');
const sources = walk(path.join(ROOT, 'eval', 'src'));
const code = (f) => readFileSync(f, 'utf8').split('\n').filter((l) => !/^\s*(\/\/|\/\*|\*)/.test(l)).join('\n');
const imports = (f) => [...readFileSync(f, 'utf8').matchAll(/^\s*import\b[^'"\n]*?from\s+['"]([^'"]+)['"]/gm)].map((m) => m[1]);

test('the scorer and report code import only the standard library and each other', () => {
    for (const f of sources) {
        for (const i of imports(f)) assert.ok(/^\.\/[a-z]+\.js$/.test(i) || ['node:fs', 'node:path', 'node:crypto', 'node:url', 'node:child_process'].includes(i), `${rel(f)} imports ${i}`);
        assert.equal(/\bimport\s*\(|\brequire\s*\(/.test(code(f)), false, `${rel(f)} loads code dynamically`);
    }
});

test('nothing in the harness can open a connection, drive a browser or reach the production database', () => {
    for (const f of [...sources, ...walk(path.join(ROOT, 'eval', 'tools')).filter((t) => t.endsWith('.js') || t.endsWith('.mjs'))]) {
        const text = code(f);
        for (const w of [/\bfetch\s*\(/, /XMLHttpRequest/, /WebSocket/, /node:(net|http|https|dns|tls|dgram|worker_threads)/, /from 'playwright'/, /sqlite|database\.sqlite|mysql|pgsql/i, /agent_runs|application_questions|job_postings/, /\.\.\/\.\.\/src\/(client|poll|config|isolation-check)/]) assert.equal(w.test(text), false, `${rel(f)} matches ${w}`);
    }
});

test('only the provenance module starts a process, and the scorer path is free of clocks, randomness and the environment', () => {
    for (const f of sources) {
        const text = code(f);
        assert.equal(/child_process/.test(text), path.basename(f) === 'provenance.js', rel(f));
        assert.equal(/Date\.now|new Date|Math\.random|process\.env|toISOString|performance\.now|setTimeout/.test(text), false, `${rel(f)} is non-deterministic`);
    }
    assert.equal(/execFileSync\('git', args/.test(readFileSync(path.join(ROOT, 'eval/src/provenance.js'), 'utf8')), true);
    assert.equal(/execSync|spawn|exec\(/.test(code(path.join(ROOT, 'eval/src/provenance.js'))), false, 'only read-only git queries via execFileSync');
});

test('the scorer does not import the inspector: it compares results, it never re-implements or runs extraction', () => {
    for (const f of sources) assert.equal(imports(f).some((i) => /\.\.\//.test(i)), false, rel(f));
});

test('only the replay tool reaches the inspector, and only through inspect() over local fixture servers', () => {
    const tools = walk(path.join(ROOT, 'eval', 'tools')).filter((t) => /\.m?js$/.test(t));
    const reaching = tools.filter((t) => imports(t).some((i) => /\.\.\/\.\.\/(src|test)\//.test(i)));
    assert.deepEqual(reaching.map(rel), ['eval/tools/replay-cases.js']);
    assert.deepEqual(imports(path.join(ROOT, 'eval/tools/replay-cases.js')).sort(), ['../../src/inspect.js', '../../test/support/fixture-server.js']);
});

test('production does not know the harness exists, and the image does not carry it', () => {
    for (const f of walk(path.join(ROOT, 'src'))) assert.equal(/\beval\/|\.\.\/eval|harness/.test(code(f)), false, rel(f));
    const docker = readFileSync(path.join(ROOT, 'Dockerfile'), 'utf8');
    assert.equal(/COPY\s+eval|COPY\s+\.\s/.test(docker), false);
    assert.match(docker, /COPY src \.\/src/);
    assert.equal(JSON.parse(readFileSync(path.join(ROOT, 'package.json'), 'utf8')).dependencies.playwright !== undefined, true);
});

test('the harness has no write path to Laravel: no worker client, no relay, no result POST', () => {
    for (const f of [...sources, ...walk(path.join(ROOT, 'eval', 'tools'))]) assert.equal(/\bclaim\b|\brelay\b|\bPOST\b|reportResult|WELL_APPLIED/.test(code(f)), false, rel(f));
});

test('no third-party page snapshot and no personal data is stored under eval/', () => {
    const files = walk(path.join(ROOT, 'eval'));
    assert.equal(files.some((f) => /\.(html?|png|jpe?g|pdf|har)$/i.test(f)), false, 'no page snapshots');
    for (const f of files) {
        const text = readFileSync(f, 'utf8');
        assert.equal(/[\w.+-]+@[\w-]+\.[\w.]+/.test(text), false, `${rel(f)} contains an email address`);
        assert.equal(/worker_identity|ai-box-browser-inspector|Authorization:|Bearer /.test(text), false, `${rel(f)} contains run metadata or credentials`);
    }
});
