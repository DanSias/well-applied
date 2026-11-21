#!/usr/bin/env node
// Usage:
//   node eval/src/cli.js run --config generic-on --out eval/reports/<name> [--inspector-commit <sha>]
//   node eval/src/cli.js compare <before/report.json> <after/report.json> [--out <dir>] [--fail-on-regression]
// Offline: reads committed files, writes report files. Exit codes: 0 ok, 1 regression (with --fail-on-regression),
// 2 usage error, 3 evidence integrity failure.
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { stableJson } from './canon.js';
import { compareReports, renderComparison } from './compare.js';
import { renderMarkdown } from './render.js';
import { runEvaluation } from './run.js';

const root = path.join(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const [, , command, ...rest] = process.argv;
const flags = {};
const positional = [];
for (let i = 0; i < rest.length; i++) {
    if (rest[i].startsWith('--')) {
        const name = rest[i].slice(2);
        flags[name] = ['fail-on-regression'].includes(name) ? true : rest[++i];
    } else positional.push(rest[i]);
}
const usage = (m) => {
    console.error(`${m}\nUsage: cli.js run --config <name> --out <dir> [--inspector-commit <sha>] | cli.js compare <a/report.json> <b/report.json> [--out <dir>] [--fail-on-regression]`);
    process.exit(2);
};
const write = (dir, name, text) => {
    mkdirSync(dir, { recursive: true });
    writeFileSync(path.join(dir, name), text);
};

if (command === 'run') {
    if (!flags.config || !flags.out || positional.length) usage('run needs --config and --out');
    const report = runEvaluation({ root, config: flags.config, inspectorCommit: flags['inspector-commit'] ?? null });
    write(path.resolve(flags.out), 'report.json', stableJson(report));
    write(path.resolve(flags.out), 'report.md', renderMarkdown(report));
    console.log(`wrote ${path.join(flags.out, 'report.json')} and report.md; integrity ${report.integrity.verified ? 'verified' : 'FAILED'}`);
    process.exit(report.integrity.verified ? 0 : 3);
} else if (command === 'compare') {
    if (positional.length !== 2) usage('compare needs two report.json paths');
    const [a, b] = positional.map((p) => JSON.parse(readFileSync(path.resolve(p), 'utf8')));
    const c = compareReports(a, b);
    if (flags.out) {
        write(path.resolve(flags.out), 'compare.json', stableJson(c));
        write(path.resolve(flags.out), 'compare.md', renderComparison(c));
    } else process.stdout.write(renderComparison(c));
    process.exit(flags['fail-on-regression'] && c.summary.regressed > 0 ? 1 : 0);
} else usage('unknown command');
