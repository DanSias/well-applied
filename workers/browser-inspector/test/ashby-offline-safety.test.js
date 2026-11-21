// The Ashby adapter is OFFLINE by construction: its sources import nothing that can open a connection, read a file or spawn a process.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const src = path.join(path.dirname(fileURLToPath(import.meta.url)), '..', 'src', 'providers', 'ashby');
const files = readdirSync(src).filter((f) => f.endsWith('.js'));

test('there are adapter sources to check', () => assert.ok(files.length >= 6, files.join(',')));

// result.js is the contract's enum mirror and has no imports of its own (asserted below).
test('result.js itself imports nothing', () => assert.equal(/^\s*import\b/m.test(readFileSync(path.join(src, '..', '..', 'result.js'), 'utf8')), false));

test('every import is a sibling, the pure lexicon or result constants, or node:crypto', () => {
    for (const f of files) {
        const text = readFileSync(path.join(src, f), 'utf8');
        for (const m of text.matchAll(/^\s*(?:import|export)\b[^'"\n]*?from\s+['"]([^'"]+)['"]/gm)) {
            assert.ok(/^\.\/[a-z-]+\.js$/.test(m[1]) || m[1] === '../../discovery/lexicon.js' || m[1] === '../../result.js' || m[1] === 'node:crypto', `${f} imports ${m[1]}`);
        }
        assert.equal(/\bimport\s*\(/.test(text), false, `${f} uses dynamic import`);
        assert.equal(/\brequire\s*\(/.test(text), false, `${f} uses require`);
    }
});

test('no network, process, file, timer or global-fetch use', () => {
    for (const f of files) {
        const code = readFileSync(path.join(src, f), 'utf8').split('\n').filter((l) => !/^\s*\/\//.test(l)).join('\n');
        for (const word of [/\bfetch\s*\(/, /XMLHttpRequest/, /WebSocket/, /\bhttps?\./, /child_process/, /node:(net|http|https|fs|dns|tls|dgram|child_process|worker_threads)/, /process\.env/, /setTimeout|setInterval/, /\bglobalThis\b/]) {
            assert.equal(word.test(code), false, `${f} matches ${word}`);
        }
    }
});

test('the adapter itself stays offline; only the guard and poll loop know nothing of it, and inspect.js reaches it solely through the transport milestone', () => {
    const read = (f) => readFileSync(path.join(src, '..', '..', f), 'utf8');
    for (const f of ['guard.js', 'poll.js']) assert.equal(read(f).includes('providers/ashby'), false, f);
    // inspect.js (since the transport milestone, behind BROWSER_WORKER_ASHBY_SCHEMA) imports exactly the link parser from the adapter.
    assert.deepEqual([...read('inspect.js').matchAll(/from '(\.\/providers\/ashby\/[^']+)'/g)].map((m) => m[1]), ['./providers/ashby/target.js']);
});
