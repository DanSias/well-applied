// Recomputes every evidence hash in eval/manifest.json from the files on disk. Run it ONLY after deliberately changing
// evidence, and review the manifest diff: that diff is the record that the change was intended. Also writes
// eval/results/INDEX.json (the hash of every replayed result) so altered results are detected too.
import { readFileSync, readdirSync, existsSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { sha256 } from '../src/canon.js';

const root = path.join(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const manifestFile = path.join(root, 'eval', 'manifest.json');
const manifest = JSON.parse(readFileSync(manifestFile, 'utf8'));
for (const c of manifest.cases) for (const e of c.evidence) e.sha256 = sha256(readFileSync(path.join(root, e.path)));
writeFileSync(manifestFile, `${JSON.stringify(manifest, null, 2)}\n`);

const resultsDir = path.join(root, 'eval', 'results');
const index = {};
for (const config of readdirSync(resultsDir, { withFileTypes: true }).filter((d) => d.isDirectory()).map((d) => d.name).sort()) {
    index[config] = {};
    for (const f of readdirSync(path.join(resultsDir, config)).filter((n) => n.endsWith('.result.json')).sort()) index[config][f.replace('.result.json', '')] = sha256(readFileSync(path.join(resultsDir, config, f)));
}
writeFileSync(path.join(resultsDir, 'INDEX.json'), `${JSON.stringify(index, null, 2)}\n`);
console.log(`sealed ${manifest.cases.length} cases and ${Object.values(index).reduce((n, c) => n + Object.keys(c).length, 0)} results`);
