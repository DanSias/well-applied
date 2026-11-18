// Resolves the two settings the agent needs when it is started with `npm run agent`, from the repository's root `.env`
// (Laravel's own file) and the shell, WITHOUT loading anything else from it. Pure, so it can be tested.
//
//   APP_URL                (root .env) -> CAREER_TOOLKIT_URL, unless CAREER_TOOLKIT_URL is already set in the shell
//   PREPARER_WORKER_TOKEN  (root .env) -> PREPARER_WORKER_TOKEN, unless it is already set in the shell
//
// Nothing else in that file (the application key, database credentials, mail or API secrets) is ever read into the result.

const WANTED = ['APP_URL', 'PREPARER_WORKER_TOKEN'] as const;
type Wanted = (typeof WANTED)[number];

/** The values of APP_URL and PREPARER_WORKER_TOKEN from dotenv-style text; every other key is skipped, never kept. */
export function readWantedFromEnvFile(
    text: string,
): Partial<Record<Wanted, string>> {
    const out: Partial<Record<Wanted, string>> = {};

    for (const raw of text.split(/\r?\n/)) {
        const line = raw.trim();
        if (line === '' || line.startsWith('#')) continue;

        const match =
            /^(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/.exec(line);
        const key = match?.[1];
        if (
            match === null ||
            key === undefined ||
            !(WANTED as readonly string[]).includes(key)
        )
            continue;

        let value = (match[2] ?? '').trim();
        const quote = value[0];
        if ((quote === '"' || quote === "'") && value.indexOf(quote, 1) > 0) {
            value = value.slice(1, value.indexOf(quote, 1));
        } else {
            // Unquoted: an inline comment starts at a space followed by #.
            value = value.replace(/\s+#.*$/, '').trim();
        }

        // Laravel expands ${VAR}; this reader does not, so it refuses rather than guess.
        if (value === '' || value.includes('${')) continue;

        out[key as Wanted] = value;
    }

    return out;
}

export interface LaunchResolution {
    /** The only variables to add to the agent's environment (never anything already set in the shell). */
    set: Record<string, string>;
    /** Where each setting came from, for a log line. Never contains a value. */
    sources: {
        url: 'shell' | 'root .env (APP_URL)' | 'missing';
        token: 'shell' | 'root .env' | 'missing';
    };
}

/**
 * Explicit shell values win: CAREER_TOOLKIT_URL over APP_URL, and a shell PREPARER_WORKER_TOKEN over the file's. A shell
 * APP_URL is not consulted (only CAREER_TOOLKIT_URL is the override), so an unrelated APP_URL in the shell cannot redirect
 * the agent.
 */
export function resolveLaunch(
    shell: NodeJS.ProcessEnv,
    rootEnvText: string | null,
): LaunchResolution {
    const file = rootEnvText === null ? {} : readWantedFromEnvFile(rootEnvText);
    const set: Record<string, string> = {};

    const shellUrl = shell.CAREER_TOOLKIT_URL;
    const shellToken = shell.PREPARER_WORKER_TOKEN;

    let url: LaunchResolution['sources']['url'] = 'missing';
    if (shellUrl !== undefined && shellUrl !== '') {
        url = 'shell';
    } else if (file.APP_URL !== undefined) {
        set.CAREER_TOOLKIT_URL = file.APP_URL;
        url = 'root .env (APP_URL)';
    }

    let token: LaunchResolution['sources']['token'] = 'missing';
    if (shellToken !== undefined && shellToken !== '') {
        token = 'shell';
    } else if (file.PREPARER_WORKER_TOKEN !== undefined) {
        set.PREPARER_WORKER_TOKEN = file.PREPARER_WORKER_TOKEN;
        token = 'root .env';
    }

    return { set, sources: { url, token } };
}
