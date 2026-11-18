// The per-attempt working directory: private (0700 directory, 0600 files), under PREPARER_WORK_DIR/<agent run id>.
//   resume/<name>.pdf      the exact resume bytes   } deleted as soon as the engine has finished
//   input.json             the applicant values     } (they are never needed again)
//   runs/<runId>/          the engine's own output: report.json and screenshots
//   delivery.json          the sanitized result payload + screenshot manifest, kept until Laravel confirms it
// Nothing here is ever logged. Cleanup is explicit and only after a confirmed outcome.
import {
    mkdir,
    readdir,
    readFile,
    rm,
    stat,
    writeFile,
} from 'node:fs/promises';
import { join } from 'node:path';

export class Workspace {
    readonly dir: string;
    readonly resumeDir: string;
    readonly inputPath: string;
    readonly runsDir: string;
    readonly deliveryPath: string;
    readonly runId: number;

    constructor(root: string, runId: number) {
        this.runId = runId;
        this.dir = join(root, String(runId));
        this.resumeDir = join(this.dir, 'resume');
        this.inputPath = join(this.dir, 'input.json');
        this.runsDir = join(this.dir, 'runs');
        this.deliveryPath = join(this.dir, 'delivery.json');
    }

    get engineRunId(): string {
        return `wa-${this.runId}`;
    }

    get engineRunDir(): string {
        return join(this.runsDir, this.engineRunId);
    }

    async create(): Promise<void> {
        await mkdir(join(this.dir, '..'), { recursive: true, mode: 0o700 });
        await rm(this.dir, { recursive: true, force: true });
        await mkdir(this.dir, { mode: 0o700 });
        await mkdir(this.resumeDir, { mode: 0o700 });
    }

    async writePrivate(path: string, data: string | Buffer): Promise<void> {
        await writeFile(path, data, { mode: 0o600 });
    }

    /** Removes the applicant values and the resume. The report and screenshots are kept for delivery. */
    async removeSensitiveInputs(): Promise<void> {
        await rm(this.inputPath, { force: true });
        await rm(this.resumeDir, { recursive: true, force: true });
    }

    async remove(): Promise<void> {
        await rm(this.dir, { recursive: true, force: true });
    }
}

export interface PendingEntry {
    runId: number;
    workspace: Workspace;
    ageMs: number;
    hasDelivery: boolean;
}

/** Every attempt directory left in the work dir (a crash, or a delivery that was never confirmed). */
export async function listWorkspaces(
    root: string,
    now = Date.now(),
): Promise<PendingEntry[]> {
    let names: string[];
    try {
        names = await readdir(root);
    } catch {
        return [];
    }
    const out: PendingEntry[] = [];
    for (const name of names) {
        if (!/^\d{1,12}$/.test(name)) continue;
        const ws = new Workspace(root, Number(name));
        const st = await stat(ws.dir).catch(() => null);
        if (!st?.isDirectory()) continue;
        const hasDelivery = await readFile(ws.deliveryPath).then(
            () => true,
            () => false,
        );
        out.push({
            runId: ws.runId,
            workspace: ws,
            ageMs: now - st.mtimeMs,
            hasDelivery,
        });
    }
    return out;
}
