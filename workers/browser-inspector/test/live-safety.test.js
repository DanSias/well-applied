// The live adapter is NOT bundled into the production worker image, NOT wired into normal inspection, and cannot weaken any protection.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.join(path.dirname(fileURLToPath(import.meta.url)), '..');
const dir = path.join(ROOT, 'eval', 'live');
const files = readdirSync(dir).filter((f) => f.endsWith('.js'));
const code = (f) => readFileSync(path.join(dir, f), 'utf8').split('\n').filter((l) => !/^\s*(\/\/|\/\*|\*)/.test(l)).join('\n');
const imports = (f) => [...readFileSync(path.join(dir, f), 'utf8').matchAll(/^\s*import\b[^'"\n]*?from\s+['"]([^'"]+)['"]/gm)].map((m) => m[1]);
const walk = (d) => readdirSync(d, { withFileTypes: true }).flatMap((e) => (e.isDirectory() ? walk(path.join(d, e.name)) : [path.join(d, e.name)]));

test('each live module imports only what it needs: the inspector\'s own guard, launcher, address check and resolver, the runner core, and the standard library', () => {
    const allowed = {
        'adapter.js': ['../../src/guard.js', '../../src/browser.js', '../../src/budget.js', '../../src/url-safety.js', '../../src/resolution/resolve.js', './host-policy.js', './robots.js'],
        'host-policy.js': ['../../src/resolution/hosts.js', '../runner/pacer.js'],
        'robots.js': [],
        'run-live.js': ['node:fs', 'node:path', 'node:url', '../runner/batch.js', './adapter.js', './source-db.js'],
        'source-db.js': ['node:sqlite'],
    };
    assert.deepEqual([...files].sort(), Object.keys(allowed).sort());
    for (const f of files) assert.deepEqual(imports(f).sort(), [...allowed[f]].sort(), f);
});

test('only the source reader can open a database, and nothing else in the live adapter can: no network, no browser of its own, no interaction, no allow-list', () => {
    for (const f of files) {
        const text = code(f);
        assert.equal(/node:sqlite/.test(text), f === 'source-db.js', f);
        for (const w of [/\bfetch\s*\(/, /XMLHttpRequest|WebSocket/, /node:(net|http|https|dns|tls|dgram|child_process|worker_threads)/, /playwright/, /\.(click|fill|type|press|selectOption|setInputFiles|dispatchEvent)\s*\(/, /\.goto\s*\(|\.newPage|chromium\.launch/, /process\.env/, /Date\.now|new Date|Math\.random|setTimeout|setInterval/]) assert.equal(w.test(text), false, `${f} matches ${w}`);
        // allowHosts would exempt a host from EVERY check, including this adapter's own host policy.
        assert.equal(/allowHosts/.test(text), false, `${f} must not use the guard's test-only allow-list`);
    }
});

test('the guard is built from the inspector\'s own createGuard with only a REFUSING policy; the GET/HEAD rule, sandbox, isolation and relay are untouched', () => {
    assert.match(code('adapter.js'), /const real = createGuard\(\{ resolve: addressCheck \}\);\s+const layered = withRequestPolicy\(real, policy\);/, 'the guard keeps its own address check; the policy is a separate layer on top');
    const hp = code('host-policy.js');
    assert.match(hp, /await guard\.install\(context\);\s+await context\.route\('\*\*\/\*'/, 'the policy route is registered AFTER the guard\'s, so it runs first and falls back to it');
    assert.match(hp, /return route\.fallback\(\);/);
    assert.match(hp, /await guard\.attach\(page\);/, 'the guard\'s own redirect interception is attached first and left in place');
    assert.equal(/route\.(continue|fulfill|fetch)\(/.test(hp), false, 'the policy only refuses or falls back; it never sends, answers or alters a request');
    const changed = execFileSync('git', ['diff', '--name-only', 'master', '--', 'src', 'isolation', 'relay', 'compose.yaml', 'Dockerfile', '../application-preparer', 'package.json', 'package-lock.json'], { cwd: ROOT, encoding: 'utf8' }).trim().split('\n').filter(Boolean);
    const ok = (f) => f === 'src/inspect.js' || f.startsWith('src/resolution/');
    assert.deepEqual(changed.filter((f) => !ok(f.replace(/^workers\/browser-inspector\//, ''))), [], 'only the resolver and the observe event the walk emits may differ from master');
    for (const f of ['src/guard.js', 'src/browser.js', 'src/url-safety.js', 'src/page-kind.js', 'src/apply-links.js', 'src/budget.js', 'src/result.js', 'src/poll.js', 'src/client.js', 'src/config.js']) assert.equal(execFileSync('git', ['diff', '--name-only', 'master', '--', f], { cwd: ROOT, encoding: 'utf8' }).trim(), '', `${f} changed`);
});

test('the live adapter is not in the production image: the Dockerfile copies only src, package files and nothing from eval; no new dependency was added', () => {
    const docker = readFileSync(path.join(ROOT, 'Dockerfile'), 'utf8');
    assert.equal(/\beval\b/.test(docker.replace(/^#.*$/gm, '')), false);
    assert.match(docker, /COPY src \.\/src/);
    assert.equal(/COPY\s+\.\s|ADD\s/.test(docker), false);
    assert.deepEqual(Object.keys(JSON.parse(readFileSync(path.join(ROOT, 'package.json'), 'utf8')).dependencies), ['playwright']);
    assert.ok(readFileSync(path.join(ROOT, '.dockerignore'), 'utf8').split('\n').includes('test'));
});

test('nothing in src (the image) can reach the live adapter, the runner, a database or the harness, and normal inspection is not wired to any of it', () => {
    for (const f of walk(path.join(ROOT, 'src')).filter((n) => n.endsWith('.js'))) {
        const imps = [...readFileSync(f, 'utf8').matchAll(/^\s*(?:import|export)\b[^'"\n]*?from\s+['"]([^'"]+)['"]/gm)].map((m) => m[1]);
        for (const i of imps) assert.equal(/eval|node:sqlite|live|runner/.test(i) && !/\/resolution\//.test(f), false, `${path.relative(ROOT, f)} imports ${i}`);
        assert.equal(/node:sqlite/.test(readFileSync(f, 'utf8')), false, `${path.relative(ROOT, f)} uses sqlite`);
    }
    const inspect = readFileSync(path.join(ROOT, 'src/inspect.js'), 'utf8');
    assert.equal(/\bimport\b.*(eval|resolution|live)/.test(inspect), false);
    assert.equal(/import .*resolution|import .*\/live\//.test(readFileSync(path.join(ROOT, 'src/poll.js'), 'utf8')), false);
});

test('the live adapter never writes to the repository or a run directory by itself: only the CLI writes, and only under a fresh eval/runs directory', () => {
    for (const f of files) assert.equal(/writeFile|appendFile|mkdir|rename|unlink|rm\(|createWriteStream/.test(code(f)), false, f);
});

test('the robots snapshot is dated and says how it was obtained and that it is not authoritative', () => {
    const s = JSON.parse(readFileSync(path.join(dir, 'robots-snapshot.json'), 'utf8'));
    assert.match(s.retrieved_on, /^\d{4}-\d{2}-\d{2}$/);
    assert.match(s.how, /NOT a byte-for-byte copy/);
    assert.deepEqual(s.rules.map((r) => r.host).sort(), ['himalayas.app', 'jobicy.com']);
});
