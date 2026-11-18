// `npm run agent`: starts the Laravel agent (src/agent.ts) using the repository's root `.env` for the two settings it
// needs, so no variables have to be exported by hand. See README.md "Laravel agent". The agent itself is unchanged: this
// only fills in CAREER_TOOLKIT_URL (from APP_URL) and PREPARER_WORKER_TOKEN when the shell has not set them, and reads
// nothing else from that file. It prints which source was used, never a value.
//
//   (no flag)  start, or exit 1 with a clear message if it cannot (missing setting, missing dependency, already running)
//   --dev      for `composer run dev`: the same checks, but when it cannot start it says why and STAYS IDLE until Ctrl+C,
//              so the dev supervisor does not restart it in a loop and the other services are never affected
//   --check    only report whether it could start (exit 0 / 1), without starting anything
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { resolveLaunch } from './agent/launch.ts';
import { acquireLock, dependencyProblems } from './agent/readiness.ts';

const dev = process.argv.includes('--dev');
const check = process.argv.includes('--check');

// workers/application-preparer/src -> the repository root. PREPARER_ENV_FILE overrides it (used by the tests).
const here = dirname(fileURLToPath(import.meta.url));
const envFile =
    process.env.PREPARER_ENV_FILE ?? join(here, '..', '..', '..', '.env');
const lockFile =
    process.env.PREPARER_LOCK_FILE ?? join(here, '..', '.agent.lock');

const say = (line: string) => process.stderr.write(`[agent] ${line}\n`);

/** Cannot start: explain, then exit 1, or (--dev) wait quietly for Ctrl+C. */
function cannotStart(problems: string[]): never | void {
    for (const p of problems) say(p);
    if (!dev) process.exit(1);
    say(
        'The preparer is idle (nothing else is affected). Fix the above and restart `composer run dev`.',
    );
    for (const signal of ['SIGINT', 'SIGTERM'] as const)
        process.on(signal, () => process.exit(0));
    setInterval(() => {}, 1 << 30);
}

let text: string | null = null;
try {
    text = readFileSync(envFile, 'utf8');
} catch {
    // No root .env (or unreadable): the shell must provide everything.
}

const { set, sources } = resolveLaunch(process.env, text);

const problems: string[] = [];
if (sources.url === 'missing')
    problems.push(
        'Cannot start: no application URL. Set APP_URL in the repository .env (or CAREER_TOOLKIT_URL in the shell).',
    );
if (sources.token === 'missing')
    problems.push(
        'Cannot start: no PREPARER_WORKER_TOKEN. Set it in the repository .env (it must differ from BROWSER_WORKER_TOKEN).',
    );
problems.push(...(await dependencyProblems()));

if (check) {
    for (const p of problems) say(p);
    if (problems.length === 0) say('ready: settings and Chromium found.');
    process.exit(problems.length === 0 ? 0 : 1);
}

if (problems.length > 0) {
    cannotStart(problems);
} else {
    const lock = acquireLock(lockFile);

    if (!lock.ok) {
        cannotStart([
            `Another preparer agent is already running (pid ${lock.pid}); not starting a second one.`,
        ]);
    } else {
        // Release the lock the moment a stop signal arrives, not only on a normal exit: a supervisor may kill the process
        // right after signalling it, and a stale lock is recovered but never ideal. (The agent's own handlers still run.)
        for (const signal of ['SIGINT', 'SIGTERM', 'SIGHUP'] as const)
            process.on(signal, lock.release);
        for (const [key, value] of Object.entries(set))
            process.env[key] = value;
        say(`settings: url from ${sources.url}, token from ${sources.token}`);
        await import('./agent.ts');
    }
}
