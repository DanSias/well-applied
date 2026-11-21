// Which inspector produced the results being scored. The only module that starts a process (read-only git queries).
import { execFileSync } from 'node:child_process';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import path from 'node:path';
import { sha256 } from './canon.js';

const walk = (dir) => readdirSync(dir).sort().flatMap((n) => (statSync(path.join(dir, n)).isDirectory() ? walk(path.join(dir, n)) : [path.join(dir, n)]));

/** A hash of the inspector's own source tree: equal hashes mean the same inspector, whatever the commit says. */
export const sourceHash = (workerRoot) => {
    const src = path.join(workerRoot, 'src');

    return sha256(walk(src).map((f) => `${path.relative(src, f).split(path.sep).join('/')}\0${sha256(readFileSync(f))}`).join('\n'));
};

const git = (cwd, ...args) => {
    try {
        return execFileSync('git', args, { cwd, encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }).trim();
    } catch {
        return null;
    }
};

export function inspectorProvenance(workerRoot, overrideCommit = null) {
    const status = git(workerRoot, 'status', '--porcelain', '--', 'src');

    return { commit: overrideCommit ?? git(workerRoot, 'rev-parse', 'HEAD') ?? 'unknown', source_tree_modified: status === null ? null : status !== '', source_sha256: sourceHash(workerRoot) };
}
