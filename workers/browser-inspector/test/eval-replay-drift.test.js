// The replayed results the harness scores are exactly what the real inspect() pipeline produces from the committed
// fixtures today. Real Chromium, local fixture servers only (the same arrangement as the existing discovery tests).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { replayAll } from '../eval/tools/replay-cases.js';

const ROOT = path.join(path.dirname(fileURLToPath(import.meta.url)), '..');

test('committed replay results equal a fresh replay of the committed fixtures, for both configurations', async () => {
    const all = await replayAll();
    let checked = 0;
    for (const [config, cases] of Object.entries(all)) {
        for (const [id, result] of Object.entries(cases)) {
            const committed = readFileSync(path.join(ROOT, 'eval', 'results', config, `${id}.result.json`), 'utf8');
            assert.equal(`${JSON.stringify(result, null, 2)}\n`, committed, `${config}/${id} drifted: run node eval/tools/refresh-results.mjs && node eval/tools/seal-manifest.mjs and review the diff`);
            checked++;
        }
    }
    assert.equal(checked, 18);
});

test('replaying twice gives identical bytes (no ports, timings or timestamps leak into a result)', async () => {
    const a = await replayAll(['generic-on']);
    const b = await replayAll(['generic-on']);
    assert.equal(JSON.stringify(a), JSON.stringify(b));
});
