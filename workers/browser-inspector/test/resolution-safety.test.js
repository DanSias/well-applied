// Destination Resolution is a thin, read-only layer over the inspector's own walk. These tests keep it that way: no second crawler,
// no interaction, no extraction, no new network path, and no change to the guard, the sandbox, the isolation or the relay.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { ALLOWED_NON_GET } from '../src/guard.js';

const ROOT = path.join(path.dirname(fileURLToPath(import.meta.url)), '..');
const dir = path.join(ROOT, 'src', 'resolution');
const files = readdirSync(dir).filter((f) => f.endsWith('.js'));
const code = (f) => readFileSync(path.join(dir, f), 'utf8').split('\n').filter((l) => !/^\s*(\/\/|\/\*|\*)/.test(l)).join('\n');
const imports = (f) => [...readFileSync(path.join(dir, f), 'utf8').matchAll(/^\s*import\b[^'"\n]*?from\s+['"]([^'"]+)['"]/gm)].map((m) => m[1]);

test('the component imports only the inspector\'s own walk, guard helpers, result vocabulary and host matching', () => {
    assert.deepEqual(files.sort(), ['hosts.js', 'resolve.js']);
    const allowed = new Set(['../inspect.js', '../guard.js', '../browser.js', '../result.js', '../page-kind.js', './hosts.js']);
    for (const f of files) for (const i of imports(f)) assert.ok(allowed.has(i), `${f} imports ${i}`);
    assert.deepEqual(imports('resolve.js').filter((i) => i === '../browser.js').length, 1);
    assert.match(readFileSync(path.join(dir, 'resolve.js'), 'utf8'), /import \{ safeRedact \} from '\.\.\/browser\.js'/, 'from the browser module it takes only the URL redactor');
});

test('it has no network or browser of its own, and cannot interact with a page', () => {
    for (const f of files) {
        const text = code(f);
        for (const w of [/\bfetch\s*\(/, /XMLHttpRequest|WebSocket/, /node:(net|http|https|dns|tls|dgram|child_process|fs|worker_threads)/, /playwright/, /\.(click|dblclick|fill|type|press|check|selectOption|setInputFiles|dispatchEvent|hover|tap)\s*\(/, /\.evaluate\s*\(|\.goto\s*\(|\.newPage|\.launch\s*\(|launchChromium/, /process\.env/, /setTimeout|setInterval/]) assert.equal(w.test(text), false, `${f} matches ${w}`);
    }
});

test('it never reads or extracts a form: no extractor, discovery or Laravel client', () => {
    for (const f of files) assert.equal(/extractors\/|discovery\/|client\.js|poll\.js|config\.js|providers\/|transport\//.test(readFileSync(path.join(dir, f), 'utf8').split('\n').filter((l) => /^\s*import/.test(l)).join('\n')), false, f);
});

test('it is not wired into production: nothing in src imports it, and the poll loop and client know nothing of it', () => {
    for (const f of readdirSync(path.join(ROOT, 'src'), { recursive: true }).filter((n) => n.endsWith('.js') && !n.startsWith('resolution'))) {
        assert.equal(/^\s*(import|export)\b.*resolution\//m.test(readFileSync(path.join(ROOT, 'src', f), 'utf8')), false, f);
    }
});

test('the three hooks the walk gained default to nothing, so an ordinary inspection is unchanged', () => {
    const text = readFileSync(path.join(ROOT, 'src', 'inspect.js'), 'utf8');
    assert.match(text, /onForm = null,\s+observe = null,\s+onCandidate = null,/);
    assert.match(text, /onForm \? onForm\(page, kind, \{ hop, state \}\) : reachedForm\(/);
    assert.match(text, /observe\?\./);
    assert.match(text, /if \(onCandidate\) \{/);
    assert.equal(/import .*resolution/.test(text), false, 'the inspector does not import the resolver: the dependency points one way');
});

test('the guard still allows no browser POST, and the protected files are byte-identical to master', () => {
    assert.deepEqual([...ALLOWED_NON_GET], []);
    let changed;
    try {
        changed = execFileSync('git', ['diff', '--name-only', 'master', '--', 'src/guard.js', 'src/browser.js', 'src/url-safety.js', 'src/page-kind.js', 'src/apply-links.js', 'src/budget.js', 'src/result.js', 'isolation', 'relay', 'compose.yaml', 'Dockerfile'], { cwd: ROOT, encoding: 'utf8' }).trim();
    } catch {
        return; // not a git checkout: nothing to compare
    }
    assert.equal(changed, '', `protected files changed: ${changed}`);
});
