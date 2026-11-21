#!/usr/bin/env node
// Resolution batch runner.
//   plan     reads a manifest and shows what would happen; runs nothing
//   dry-run  runs the real resolver against LOCAL FIXTURES only
//   live     DISABLED unless a separate authorization matches the manifest hash, case ids, source hosts and limits; reads source URLs read-only by id
//            and makes real, paced, guarded requests. It needs --supervised, and it refuses to reuse a run directory (no resume, no retry).
//
//   node eval/runner/cli.js plan    --manifest <batch.json> --expect-sha256 <hash> [--out <dir>]
//   node eval/runner/cli.js dry-run --manifest <batch.json> --expect-sha256 <hash> [--out <dir>]
//   node eval/runner/cli.js live    --manifest <batch.json> --expect-sha256 <hash> --authorization <file> --source-db <file> --supervised
//
// Exit codes: 0 ok · 2 usage · 3 refused before anything ran (hash, schema, limits, tracker config, authorization) · 4 the batch stopped early
import { existsSync, mkdirSync, readFileSync, renameSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { sha256, stableJson } from '../src/canon.js';
import { BatchRefused, runBatch } from './batch.js';
import { renderRun } from './report.js';

const [, , command, ...rest] = process.argv;
const flags = {};
const BOOLEAN = new Set(['supervised']);
for (let i = 0; i < rest.length; i++) if (rest[i].startsWith('--')) flags[rest[i].slice(2)] = BOOLEAN.has(rest[i].slice(2)) ? true : rest[++i];
const usage = (m) => {
    console.error(`${m}\nUsage: cli.js plan|dry-run --manifest <file> --expect-sha256 <64 hex> [--out <dir>]\n       cli.js live --manifest <file> --expect-sha256 <64 hex> --authorization <file> --source-db <file> --supervised`);
    process.exit(2);
};
if (!['plan', 'dry-run', 'live'].includes(command)) usage('unknown command');
if (!flags.manifest || !flags['expect-sha256']) usage('--manifest and --expect-sha256 are required');
if (command === 'live') {
    if (!flags.authorization || !flags['source-db']) usage('live needs --authorization and --source-db');
    if (!flags.supervised) usage('live needs --supervised: a person must be present and start it');
} else if (flags.authorization || flags['source-db'] || flags.supervised) usage(`${command} does not take live options`);

const manifestBytes = readFileSync(path.resolve(flags.manifest));
const runDir = (auth) => path.resolve('eval', 'runs', `live-${sha256(manifestBytes).slice(0, 12)}-${sha256(stableJson(auth)).slice(0, 12)}`);
let out = flags.out ? path.resolve(flags.out) : null;
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
    let report;
    if (command === 'live') {
        const authorization = JSON.parse(readFileSync(path.resolve(flags.authorization), 'utf8'));
        // One run per authorization: a directory is never reused, so there is no resume and no second attempt.
        out = out ?? runDir(authorization);
        if (existsSync(out)) throw new BatchRefused('run_directory_exists', `${path.relative(process.cwd(), out)} already exists: a run is never resumed or repeated; a new run needs a new authorization`);
        const { runLive } = await import('../live/run-live.js');
        report = await runLive({ manifestBytes, expectSha256: flags['expect-sha256'], authorization, sourceDb: path.resolve(flags['source-db']), clock, signal: abort.signal, onCheckpoint: (r) => write('checkpoint.json', stableJson(r)) });
    } else {
        if (command === 'dry-run') env = await (await import('./dry-run.js')).startDryRun();
        report = await runBatch({ manifestBytes, expectSha256: flags['expect-sha256'], mode: command, resolve: env?.resolve, getUrl: env?.getUrl, clock, signal: abort.signal, onCheckpoint: out ? (r) => write('checkpoint.json', stableJson(r)) : undefined });
    }
    if (out) {
        write('report.json', stableJson(report));
        write('report.md', renderRun(report));
        write('review-queue.json', stableJson({ schema: 'eval-resolution-review-queue/1', manifest_sha256: report.manifest.sha256, stop: report.outcome.stop, queue: report.review_queue, unresolved_for_a_person: report.cases.filter((c) => c.state !== 'planned' && c.resolution && c.resolution.status === 'unresolved').map((c) => ({ case: c.id, reason: c.reason })) }));
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
