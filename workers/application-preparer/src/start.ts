// `npm run agent`: starts the Laravel agent (src/agent.ts) using the repository's root `.env` for the two settings it
// needs, so no variables have to be exported by hand. See README.md "Laravel agent". The agent itself is unchanged: this
// only fills in CAREER_TOOLKIT_URL (from APP_URL) and PREPARER_WORKER_TOKEN when the shell has not set them, and reads
// nothing else from that file. It prints which source was used, never a value.
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { resolveLaunch } from './agent/launch.ts';

// workers/application-preparer/src -> the repository root. PREPARER_ENV_FILE overrides it (used by the tests).
const here = dirname(fileURLToPath(import.meta.url));
const envFile =
    process.env.PREPARER_ENV_FILE ?? join(here, '..', '..', '..', '.env');

let text: string | null = null;
try {
    text = readFileSync(envFile, 'utf8');
} catch {
    // No root .env (or unreadable): the shell must provide everything.
}

const { set, sources } = resolveLaunch(process.env, text);

if (sources.url === 'missing' || sources.token === 'missing') {
    const missing = [
        sources.url === 'missing'
            ? 'the application URL (APP_URL in the repository .env, or CAREER_TOOLKIT_URL in the shell)'
            : null,
        sources.token === 'missing'
            ? 'PREPARER_WORKER_TOKEN (in the repository .env, or in the shell)'
            : null,
    ].filter((m) => m !== null);
    process.stderr.write(
        `[agent] cannot start: missing ${missing.join(' and ')}.\n`,
    );
    process.exit(1);
}

for (const [key, value] of Object.entries(set)) process.env[key] = value;

process.stderr.write(
    `[agent] settings: url from ${sources.url}, token from ${sources.token}\n`,
);

await import('./agent.ts');
