// Evidence on disk: screenshots and the JSON report. Everything lands in
// runs/<runId>/, which is gitignored: it contains applicant data.
import { mkdir, writeFile } from 'node:fs/promises';
import { join } from 'node:path';
import type { Page } from 'playwright';
import type { PrepareReport } from './contracts.ts';

export async function createRunDir(
    runsDir: string,
    runId: string,
): Promise<string> {
    const dir = join(runsDir, runId);
    // Not recursive on the final segment: refuses to reuse an existing run.
    await mkdir(runsDir, { recursive: true });
    await mkdir(dir);
    return dir;
}

export async function screenshot(
    page: Page,
    dir: string,
    name: string,
): Promise<string | null> {
    const path = join(dir, `${name}.png`);
    try {
        await page.screenshot({ path, fullPage: true, timeout: 15_000 });
        return `${name}.png`;
    } catch {
        return null;
    }
}

export async function writeReport(
    dir: string,
    report: PrepareReport,
): Promise<string> {
    const path = join(dir, 'report.json');
    await writeFile(path, JSON.stringify(report, null, 2) + '\n', {
        mode: 0o600,
    });
    return path;
}
