// Regenerates test/fixtures/universal/contract/*.json. Run by hand after an intended change to discovery output:
//   node test/support/refresh-contract-snapshots.mjs
// Never part of `npm test` (the drift test compares, it does not write).
import { mkdirSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { buildSnapshots } from './contract-snapshots.js';

const dir = path.join(path.dirname(fileURLToPath(import.meta.url)), '..', 'fixtures', 'universal', 'contract');
mkdirSync(dir, { recursive: true });
for (const [name, snapshot] of Object.entries(await buildSnapshots())) {
    writeFileSync(path.join(dir, `${name}.result.json`), `${JSON.stringify(snapshot, null, 2)}\n`);
    console.log(`wrote ${name}.result.json (${snapshot.fields.length} fields, ${snapshot.inspection_outcome})`);
}
