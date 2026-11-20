// The Ashby adapter is OFFLINE by construction: its sources import nothing that can open a connection, read a file or spawn a process.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const src = path.join(path.dirname(fileURLToPath(import.meta.url)), '..', 'src', 'providers', 'ashby');
const files = readdirSync(src).filter((f) => f.endsWith('.js'));

test('there are adapter sources to check', () => assert.ok(files.length >= 6, files.join(',')));

test('every import is a sibling, the pure lexicon, or node:crypto', () => {
    for (const f of files) {
        const text = readFileSync(path.join(src, f), 'utf8');
        for (const m of text.matchAll(/^\s*(?:import|export)\b[^'"\n]*?from\s+['"]([^'"]+)['"]/gm)) {
            assert.ok(/^\.\/[a-z-]+\.js$/.test(m[1]) || m[1] === '../../discovery/lexicon.js' || m[1] === 'node:crypto', `${f} imports ${m[1]}`);
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

test('the existing request guard and the worker entry points are untouched by it', () => {
    for (const f of ['guard.js', 'inspect.js', 'poll.js']) {
        assert.equal(readFileSync(path.join(src, '..', '..', f), 'utf8').includes('providers/ashby'), false, f);
    }
});
