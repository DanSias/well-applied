// Usage: node src/cli.ts <input.json> [--hold] [--headless] [--runs-dir DIR]
//                                      [--allow-local-fixture --ats lever|greenhouse]
// Exit codes: 0 ready_for_review | 3 needs a human / input / unsupported
//             | 4 safety_failure | 1 failed or bad usage.
import { readFile } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import type { AtsName, PrepareInput } from './contracts.ts';
import { prepareApplication } from './prepare.ts';

const args = process.argv.slice(2);
const file = args.find((a) => !a.startsWith('--'));
const flag = (name: string) => args.includes(`--${name}`);
const value = (name: string) => {
    const i = args.indexOf(`--${name}`);
    return i >= 0 ? args[i + 1] : undefined;
};

if (!file) {
    process.stderr.write('usage: node src/cli.ts <input.json> [--hold] [--headless] [--runs-dir DIR]\n');
    process.exit(1);
}

try {
    const input = JSON.parse(await readFile(file, 'utf8')) as PrepareInput;
    // A relative resume path is relative to the input file, not the cwd.
    input.resumePdfPath = resolve(dirname(resolve(file)), input.resumePdfPath);
    const ats = value('ats');
    if (ats !== undefined && ats !== 'lever' && ats !== 'greenhouse') throw new Error('--ats must be lever or greenhouse');

    const { report, reportPath } = await prepareApplication(input, {
        hold: flag('hold'),
        headless: flag('headless'),
        runsDir: value('runs-dir'),
        allowLocalFixture: flag('allow-local-fixture'),
        ats: ats as AtsName | undefined,
    });
    process.stdout.write(
        `${JSON.stringify({ runId: report.runId, outcome: report.outcome, reasons: report.reasons, summary: report.summary, resume: report.resume.verification, reportPath }, null, 2)}\n`,
    );
    process.exit(report.outcome === 'ready_for_review' ? 0 : report.outcome === 'safety_failure' ? 4 : report.outcome === 'failed' ? 1 : 3);
} catch (e) {
    process.stderr.write(`error: ${(e as Error).message}\n`);
    process.exit(1);
}
