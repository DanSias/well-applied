// A stand-in for the preparation engine, for protocol tests that do not need a browser. It is a real child process (so
// process-group kills and the environment scrub are exercised for real), and it writes what the real engine writes:
// runs/<runId>/report.json plus screenshots. The real engine is exercised separately against fixture forms.
import { writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import type { EngineSpec } from '../../src/agent/engine.ts';

export interface FakeEngineScript {
    /** Run time before finishing. */
    sleepMs?: number;
    /** Exit code (default 3, like an input_required report). */
    exit?: number;
    /** Write no report at all. */
    noReport?: boolean;
    /** Report fields to override. */
    report?: Record<string, unknown>;
    /** Where to record that the engine ran and what it was given (a marker file per start). */
    markerDir: string;
}

export function fakeEngineSource(): string {
    return `
const fs = require('node:fs');
const path = require('node:path');
const [,, inputPath, runsDir, scriptPath] = process.argv;
const script = JSON.parse(fs.readFileSync(scriptPath, 'utf8'));
const input = JSON.parse(fs.readFileSync(inputPath, 'utf8'));
fs.appendFileSync(path.join(script.markerDir, 'starts'), 'x');
fs.writeFileSync(path.join(script.markerDir, 'env.json'), JSON.stringify(Object.keys(process.env)));
fs.writeFileSync(path.join(script.markerDir, 'input-keys.json'), JSON.stringify({ applicant: Object.keys(input.applicant), runId: input.runId, resumeExists: fs.existsSync(input.resumePdfPath), resumeName: path.basename(input.resumePdfPath) }));
process.on('SIGTERM', () => { fs.writeFileSync(path.join(script.markerDir, 'sigterm'), '1'); process.exit(143); });
setTimeout(() => {
  if (!script.noReport) {
    const dir = path.join(runsDir, input.runId);
    fs.mkdirSync(dir, { recursive: true });
    const png = Buffer.concat([Buffer.from([0x89,0x50,0x4e,0x47,0x0d,0x0a,0x1a,0x0a]), Buffer.from('shot')]);
    fs.writeFileSync(path.join(dir, 'before-01of01-y00000.png'), png);
    fs.writeFileSync(path.join(dir, 'after_fill-01of01-y00000.png'), Buffer.concat([png, Buffer.from('2')]));
    const report = Object.assign({
      schemaVersion: 2, runId: input.runId, ats: 'greenhouse', requestedUrl: 'https://job-boards.greenhouse.io/x/jobs/1', finalUrl: 'https://job-boards.greenhouse.io/x/jobs/1',
      outcome: 'input_required', reasons: ['unresolved_required: Why do you want to join?'], warnings: [],
      summary: { total: 2, filledVerified: 1, unresolvedRequired: 1 }, questionSummary: { total: 2, needsAnswer: 1 },
      fields: [{ label: 'Email', kind: 'text', required: true, status: 'filled_verified', classification: 'known', mappedTo: 'email', sensitive: false, expected: 'alex.example@example.test', actual: 'alex.example@example.test', control: { visible: true }, options: ['a'] }],
      questions: [{ label: 'Why?', labelSource: 'dom_label_for', kind: 'textarea', required: true, status: 'unresolved_required', review: 'needs_answer', sensitive: false, provenance: { source: 'none' }, selected: ['x'], options: ['y'] }],
      resume: { sha256: 'a'.repeat(64), bytes: 10, uploaded: true, verified: true, verification: 'upload_accepted', upload: { outcome: 'accepted_filename_visible', response: { status: 201, exception: 'greenhouse-resume-upload', destination: 'https://s3.example/' } }, parser: { status: 'not_observable' } },
      blockers: { captcha: 'inactive_markup', authentication: false, multistep: false, captchaDetails: [{ tag: 'div' }] },
      submission: { assertion: 'worker_did_not_submit', blockedAttempts: 0, suspected: false },
      safetyEvents: [{ kind: 'allowed_exception_used', detail: 'x', at: 'now', phase: 'preparation' }],
      diagnostics: { durationMs: 5, headless: true, held: false },
      screenshots: [
        { file: 'before-01of01-y00000.png', phase: 'before', index: 1, of: 1, scrollY: 0, viewportWidth: 1400, viewportHeight: 1000, documentHeight: 1000 },
        { file: 'after_fill-01of01-y00000.png', phase: 'after_fill', index: 1, of: 1, scrollY: 0, viewportWidth: 1400, viewportHeight: 1000, documentHeight: 1000 },
      ],
      controls: [{ text: 'Submit application' }], finalState: { x: 1 },
    }, script.report || {});
    fs.writeFileSync(path.join(dir, 'report.json'), JSON.stringify(report));
  }
  process.exit(script.exit === undefined ? 3 : script.exit);
}, script.sleepMs || 0);
`;
}

export function fakeEngineSpec(
    script: FakeEngineScript,
): (ctx: { inputPath: string; runsDir: string }) => EngineSpec {
    const scriptPath = join(script.markerDir, 'script.json');
    writeFileSync(scriptPath, JSON.stringify(script));
    const enginePath = join(script.markerDir, 'engine.cjs');
    writeFileSync(enginePath, fakeEngineSource());
    return ({ inputPath, runsDir }) => ({
        command: process.execPath,
        args: [enginePath, inputPath, runsDir, scriptPath],
    });
}

export const tmp = tmpdir;
