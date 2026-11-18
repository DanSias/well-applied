// Runs the existing, unchanged preparation engine (src/cli.ts) as a CHILD PROCESS.
//
// A child process is the boundary that lets the agent stop a browser it does not own the handle to: on a lost lease, the
// execution limit or a shutdown it kills the whole process group (node and Chromium). It also keeps the worker token out
// of the browser's process: the child gets a minimal environment with no PREPARER_* variable. The engine's request
// guard, submission safeguards and no-capability rules are untouched; this only starts it and waits.
//
// The engine is never restarted here. There is no retry: whatever the exit, the caller delivers what exists.
import { spawn } from 'node:child_process';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import type { AtsName } from '../contracts.ts';

const CLI = join(dirname(fileURLToPath(import.meta.url)), '..', 'cli.ts');

export interface EngineSpec {
    command: string;
    args: string[];
}

export interface EngineOptions {
    inputPath: string;
    runsDir: string;
    headless: boolean;
    fixture: { ats: AtsName } | null;
    limitMs: number;
    /** Aborting kills the engine (lease lost, shutdown). */
    signal: AbortSignal;
    /** Tests only: replace the engine command. */
    spec?: (ctx: { inputPath: string; runsDir: string }) => EngineSpec;
    killGraceMs?: number;
    /** Tests only: told the child's pid (also the id of its process group). */
    onSpawn?: (pid: number) => void;
}

export type EngineEnd =
    | { kind: 'exited'; code: number | null }
    | { kind: 'timeout' }
    | { kind: 'aborted' };

/** Only what a browser needs to run; never a token, never anything PREPARER_* or Well Applied related. */
function minimalEnv(): NodeJS.ProcessEnv {
    const keep = [
        'PATH',
        'HOME',
        'TMPDIR',
        'LANG',
        'LC_ALL',
        'USER',
        'DISPLAY',
        'PLAYWRIGHT_BROWSERS_PATH',
        'XDG_RUNTIME_DIR',
    ];
    return Object.fromEntries(
        keep.flatMap((k) =>
            process.env[k] === undefined ? [] : [[k, process.env[k]]],
        ),
    );
}

export function runEngine(o: EngineOptions): Promise<EngineEnd> {
    const spec: EngineSpec = o.spec
        ? o.spec({ inputPath: o.inputPath, runsDir: o.runsDir })
        : {
              command: process.execPath,
              args: [
                  CLI,
                  o.inputPath,
                  '--runs-dir',
                  o.runsDir,
                  ...(o.headless ? ['--headless'] : []),
                  ...(o.fixture
                      ? ['--allow-local-fixture', '--ats', o.fixture.ats]
                      : []),
              ],
          };

    return new Promise((resolve) => {
        if (o.signal.aborted) return resolve({ kind: 'aborted' });
        // detached: its own process group, so the whole tree can be stopped at once.
        const child = spawn(spec.command, spec.args, {
            detached: true,
            stdio: 'ignore',
            env: minimalEnv(),
        });
        if (child.pid !== undefined) o.onSpawn?.(child.pid);
        let end: EngineEnd | null = null;

        const kill = (signal: NodeJS.Signals) => {
            try {
                if (child.pid !== undefined) process.kill(-child.pid, signal);
            } catch {
                // Already gone.
            }
        };
        const stop = (why: EngineEnd) => {
            if (end) return;
            end = why;
            kill('SIGTERM');
            setTimeout(() => kill('SIGKILL'), o.killGraceMs ?? 5000).unref();
        };

        const timer = setTimeout(() => stop({ kind: 'timeout' }), o.limitMs);
        const onAbort = () => stop({ kind: 'aborted' });
        o.signal.addEventListener('abort', onAbort, { once: true });

        child.on('error', () => {
            clearTimeout(timer);
            o.signal.removeEventListener('abort', onAbort);
            resolve({ kind: 'exited', code: null });
        });
        child.on('exit', (code) => {
            clearTimeout(timer);
            o.signal.removeEventListener('abort', onAbort);
            // Make sure nothing the engine started outlives it.
            kill('SIGKILL');
            resolve(end ?? { kind: 'exited', code });
        });
    });
}
