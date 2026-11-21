// Evidence integrity. The manifest pins the SHA-256 of every file the evaluation reads. A file that differs, or is absent,
// is reported as such and the case is NOT scored from it: altered evidence is never silently accepted.
import { existsSync, readFileSync } from 'node:fs';
import path from 'node:path';
import { sha256 } from './canon.js';

/** @returns {{role:string, path:string, class:string, sha256:string, status:'verified'|'altered'|'missing', actual_sha256:string|null}[]} */
export function verifyEvidence(entries, root) {
    return entries.map((e) => {
        const file = path.join(root, e.path);
        if (!existsSync(file)) return { role: e.role, path: e.path, class: e.class, sha256: e.sha256, status: 'missing', actual_sha256: null };
        const actual = sha256(readFileSync(file));

        return { role: e.role, path: e.path, class: e.class, sha256: e.sha256, status: actual === e.sha256 ? 'verified' : 'altered', actual_sha256: actual };
    });
}

/** Reads a verified evidence file as JSON. Refuses anything that is not verified, so no adapter can see altered bytes. */
export function readVerifiedJson(verified, role, root) {
    const v = verified.find((e) => e.role === role);
    if (!v) throw new Error(`no evidence with role ${role}`);
    if (v.status !== 'verified') throw new Error(`evidence ${role} is ${v.status}`);

    return JSON.parse(readFileSync(path.join(root, v.path), 'utf8'));
}
