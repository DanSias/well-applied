// Regenerates eval/results/<config>/<case>.result.json from the committed fixtures. Local fixture servers only: nothing
// leaves the machine. Review the diff: a changed result is a changed inspector.
import { mkdirSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { replayAll } from './replay-cases.js';

const root = path.join(path.dirname(fileURLToPath(import.meta.url)), '..', 'results');
const all = await replayAll();
for (const [config, cases] of Object.entries(all)) {
    mkdirSync(path.join(root, config), { recursive: true });
    for (const [id, result] of Object.entries(cases)) writeFileSync(path.join(root, config, `${id}.result.json`), `${JSON.stringify(result, null, 2)}\n`);
}
console.log(`wrote ${Object.values(all).reduce((n, c) => n + Object.keys(c).length, 0)} results`);
