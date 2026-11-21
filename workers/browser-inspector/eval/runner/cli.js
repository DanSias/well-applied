#!/usr/bin/env node
// Offline resolution batch runner. THIS MILESTONE HAS NO LIVE MODE: `plan` reads a manifest and shows what would happen; `dry-run` runs the real
// resolver against local fixtures only. Neither authorises or performs a live request.
//
//   node eval/runner/cli.js plan    --manifest <batch.json> --expect-sha256 <hash> [--out <dir>]
//   node eval/runner/cli.js dry-run --manifest <batch.json> --expect-sha256 <hash> [--out <dir>]
//
// Exit codes: 0 ok · 2 usage · 3 refused before anything ran (hash, schema, limits, tracker config) · 4 the batch stopped early
import { mkdirSync, readFileSync, renameSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { stableJson } from '../src/canon.js';
import { BatchRefused, runBatch } from './batch.js';
import { renderRun } from './report.js';

const [, , command, ...rest] = process.argv;
const flags = {};
for (let i = 0; i < rest.length; i++) if (rest[i].startsWith('--')) flags[rest[i].slice(2)] = rest[++i];
const usage = (m) => {
    console.error(`${m}\nUsage: cli.js plan|dry-run --manifest <file> --expect-sha256 <64 hex> [--out <dir>]\n(there is no live mode in this milestone)`);
    process.exit(2);
};
if (!['plan', 'dry-run'].includes(command)) usage(command === 'live' ? 'there is no live mode: a live run needs a separate approval and a separate milestone' : 'unknown command');
if (!flags.manifest || !flags['expect-sha256']) usage('--manifest and --expect-sha256 are required');

const out = flags.out ? path.resolve(flags.out) : null;
const write = (name, text) => {
    mkdirSync(out, { recursive: true });
    writeFileSync(path.join(out, `${name}.tmp`), text);
    renameSync(path.join(out, `${name}.tmp`), path.join(out, name));
};
const clock = { now: () => Date.now(), sleep: (ms) => new Promise((r) => setTimeout(r, ms)) };
const abort = new AbortController();
process.on('SIGINT', () => abort.abort());

let env = null;
try {
    if (command === 'dry-run') env = await (await import('./dry-run.js')).startDryRun();
    const report = await runBatch({
        manifestBytes: readFileSync(path.resolve(flags.manifest)),
        expectSha256: flags['expect-sha256'],
        mode: command,
        resolve: env?.resolve,
        getUrl: env?.getUrl,
        clock,
        signal: abort.signal,
        onCheckpoint: out ? (r) => write('checkpoint.json', stableJson(r)) : undefined,
    });
    if (out) {
        write('report.json', stableJson(report));
        write('report.md', renderRun(report));
    } else process.stdout.write(renderRun(report));
    process.exitCode = report.outcome.stop ? 4 : 0;
} catch (e) {
    if (e instanceof BatchRefused) {
        console.error(`refused (${e.code}): ${e.message}`);
        process.exitCode = 3;
    } else throw e;
} finally {
    await env?.close();
}
