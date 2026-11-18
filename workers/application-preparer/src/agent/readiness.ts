// Is the agent able to start, and is another one already running? Kept apart from launch.ts (pure settings) because these
// touch the machine: installed dependencies, the Chromium download, and a lock file. Used by src/start.ts only.
import { existsSync, readFileSync, unlinkSync, writeFileSync } from 'node:fs';

export interface Dependencies {
    /** Resolves Playwright's Chromium executable path, or throws if Playwright is not installed. */
    chromiumPath: () => Promise<string>;
    exists: (path: string) => boolean;
}

export const realDependencies: Dependencies = {
    chromiumPath: async () =>
        (await import('playwright')).chromium.executablePath(),
    exists: existsSync,
};

/** What is missing for the agent to run a browser, as sentences a developer can act on. Empty when ready. */
export async function dependencyProblems(
    deps: Dependencies = realDependencies,
): Promise<string[]> {
    let path: string;
    try {
        path = await deps.chromiumPath();
    } catch {
        return [
            "The worker's dependencies are not installed. Run: cd workers/application-preparer && npm install",
        ];
    }
    return deps.exists(path)
        ? []
        : [
              "Playwright's Chromium is not installed. Run: cd workers/application-preparer && npx playwright install chromium",
          ];
}

export type LockResult =
    | { ok: true; release: () => void }
    | { ok: false; pid: number };

const alive = (pid: number): boolean => {
    try {
        process.kill(pid, 0);
        return true;
    } catch (e) {
        // EPERM means the process exists but is not ours: still running.
        return (e as NodeJS.ErrnoException).code === 'EPERM';
    }
};

/**
 * One agent per checkout. A lock file holds the running agent's pid; a lock left by a process that is gone (a crash, SIGKILL)
 * is stale and is taken over. Released when this process exits, only if it is still ours.
 */
export function acquireLock(
    path: string,
    pid: number = process.pid,
): LockResult {
    for (let attempt = 0; attempt < 3; attempt++) {
        try {
            writeFileSync(path, String(pid), { flag: 'wx', mode: 0o600 });
            const release = () => {
                try {
                    if (readFileSync(path, 'utf8').trim() === String(pid))
                        unlinkSync(path);
                } catch {
                    // Already gone.
                }
            };
            process.once('exit', release);
            return { ok: true, release };
        } catch (e) {
            if ((e as NodeJS.ErrnoException).code !== 'EEXIST') throw e;
        }

        let holder = 0;
        try {
            holder = Number(readFileSync(path, 'utf8').trim());
        } catch {
            continue;
        }
        if (
            Number.isInteger(holder) &&
            holder > 0 &&
            holder !== pid &&
            alive(holder)
        )
            return { ok: false, pid: holder };
        try {
            unlinkSync(path);
        } catch {
            // Someone else removed it first; try again.
        }
    }
    throw new Error('could not take the agent lock');
}
