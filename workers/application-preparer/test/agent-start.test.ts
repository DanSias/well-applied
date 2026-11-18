// `npm run agent`: which settings are taken from the root .env, in what order, and that nothing else is read or printed.
import { after, before, describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { mkdtemp, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { readWantedFromEnvFile, resolveLaunch } from '../src/agent/launch.ts';
import {
    startFakeLaravel,
    TOKEN,
    type FakeLaravel,
} from './support/fake-laravel.ts';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');

const LARAVEL_ENV = `# Laravel's own file
APP_NAME="Well Applied"
APP_KEY=base64:SECRETAPPKEYSECRETAPPKEY=
APP_URL=http://localhost:8000
DB_PASSWORD=super-secret-db-password
OPENAI_API_KEY=sk-should-never-be-read
BROWSER_WORKER_TOKEN=inspector-secret
PREPARER_WORKER_TOKEN=file-token
`;

describe('reading the two settings from dotenv text', () => {
    it('reads APP_URL and PREPARER_WORKER_TOKEN and nothing else', () => {
        const out = readWantedFromEnvFile(LARAVEL_ENV);
        assert.deepEqual(out, {
            APP_URL: 'http://localhost:8000',
            PREPARER_WORKER_TOKEN: 'file-token',
        });
        assert.ok(!JSON.stringify(out).includes('secret'));
    });

    it('handles quotes, export, comments, CRLF and spacing', () => {
        assert.deepEqual(
            readWantedFromEnvFile(
                'export APP_URL = "http://localhost:8000" # note\r\nPREPARER_WORKER_TOKEN=\'tok en\'\r\n',
            ),
            {
                APP_URL: 'http://localhost:8000',
                PREPARER_WORKER_TOKEN: 'tok en',
            },
        );
        assert.deepEqual(
            readWantedFromEnvFile(
                'APP_URL=http://localhost:8000   # trailing comment',
            ),
            { APP_URL: 'http://localhost:8000' },
        );
        assert.deepEqual(
            readWantedFromEnvFile('# APP_URL=http://commented.test'),
            {},
        );
    });

    it('treats an empty value, or one that needs variable expansion, as not set', () => {
        assert.deepEqual(
            readWantedFromEnvFile('PREPARER_WORKER_TOKEN=\nAPP_URL=${OTHER}/x'),
            {},
        );
    });

    it('keeps the last of a repeated key, as dotenv does', () => {
        assert.deepEqual(
            readWantedFromEnvFile(
                'APP_URL=http://a.test\nAPP_URL=http://b.test',
            ),
            { APP_URL: 'http://b.test' },
        );
    });
});

describe('resolving with the shell', () => {
    it('maps APP_URL to CAREER_TOOLKIT_URL and takes the token, when the shell sets neither', () => {
        const r = resolveLaunch({}, LARAVEL_ENV);
        assert.deepEqual(r.set, {
            CAREER_TOOLKIT_URL: 'http://localhost:8000',
            PREPARER_WORKER_TOKEN: 'file-token',
        });
        assert.deepEqual(r.sources, {
            url: 'root .env (APP_URL)',
            token: 'root .env',
        });
    });

    it('lets CAREER_TOOLKIT_URL in the shell win over APP_URL', () => {
        const r = resolveLaunch(
            { CAREER_TOOLKIT_URL: 'http://127.0.0.1:9999' },
            LARAVEL_ENV,
        );
        assert.equal(r.set.CAREER_TOOLKIT_URL, undefined);
        assert.equal(r.sources.url, 'shell');
    });

    it("lets a shell token win over the file's", () => {
        const r = resolveLaunch(
            { PREPARER_WORKER_TOKEN: 'shell-token' },
            LARAVEL_ENV,
        );
        assert.equal(r.set.PREPARER_WORKER_TOKEN, undefined);
        assert.equal(r.sources.token, 'shell');
    });

    it('does not let an unrelated APP_URL in the shell redirect the agent', () => {
        const r = resolveLaunch({ APP_URL: 'http://evil.test' }, LARAVEL_ENV);
        assert.equal(r.set.CAREER_TOOLKIT_URL, 'http://localhost:8000');
    });

    it('adds only the two variables, whatever else the file holds', () => {
        assert.deepEqual(
            Object.keys(resolveLaunch({}, LARAVEL_ENV).set).sort(),
            ['CAREER_TOOLKIT_URL', 'PREPARER_WORKER_TOKEN'],
        );
    });

    it('reports what is missing, with no file at all', () => {
        assert.deepEqual(resolveLaunch({}, null).sources, {
            url: 'missing',
            token: 'missing',
        });
        assert.deepEqual(
            resolveLaunch(
                {
                    CAREER_TOOLKIT_URL: 'http://localhost:1',
                    PREPARER_WORKER_TOKEN: 't',
                },
                null,
            ).sources,
            { url: 'shell', token: 'shell' },
        );
    });
});

describe('npm run agent, as a process', () => {
    let fake: FakeLaravel;
    let dir: string;
    before(async () => {
        fake = await startFakeLaravel({ leaseSeconds: 10 });
        dir = await mkdtemp(join(tmpdir(), 'agent-start-'));
    });
    after(async () => {
        await fake.close();
        await rm(dir, { recursive: true, force: true });
    });

    const run = async (
        envText: string | null,
        shell: Record<string, string> = {},
    ) => {
        const file = join(dir, `env-${Math.random().toString(16).slice(2)}`);
        if (envText !== null) await writeFile(file, envText);
        // A minimal shell: nothing of the real environment leaks in, so the file is the only source.
        // Asynchronous on purpose: the fake Laravel lives in this process and must be free to answer the child.
        return new Promise<{ code: number | null; out: string }>((resolve) => {
            const child = spawn(
                process.execPath,
                [join(ROOT, 'src', 'start.ts'), '--once'],
                {
                    env: {
                        PATH: process.env.PATH ?? '',
                        HOME: process.env.HOME ?? '',
                        PREPARER_ENV_FILE: file,
                        PREPARER_WORK_DIR: join(dir, 'work'),
                        ...shell,
                    },
                },
            );
            let out = '';
            child.stdout.on('data', (c) => (out += c));
            child.stderr.on('data', (c) => (out += c));
            const timer = setTimeout(() => child.kill('SIGKILL'), 30_000);
            child.on('close', (code) => {
                clearTimeout(timer);
                resolve({ code, out });
            });
        });
    };

    const env = (token = TOKEN, url = fake.origin) =>
        `${LARAVEL_ENV}\nAPP_URL=${url}\nPREPARER_WORKER_TOKEN=${token}\n`;

    it('starts from APP_URL and the token in the root .env, polls once, and prints no secret', async () => {
        const before = fake.log.length;
        const { code, out } = await run(env());

        assert.equal(code, 0, out);
        assert.ok(
            fake.log
                .slice(before)
                .some((l) =>
                    /POST \/api\/worker\/preparer\/agent-runs\/claim 204/.test(
                        l,
                    ),
                ),
            'it authenticated and polled',
        );
        assert.match(
            out,
            /settings: url from root \.env \(APP_URL\), token from root \.env/,
        );
        for (const secret of [
            TOKEN,
            'super-secret-db-password',
            'SECRETAPPKEY',
            'sk-should-never-be-read',
            'inspector-secret',
            fake.origin,
        ])
            assert.ok(
                !out.includes(secret),
                `output contained a secret or value: ${secret.slice(0, 6)}…`,
            );
    });

    it('prefers CAREER_TOOLKIT_URL from the shell over APP_URL', async () => {
        const before = fake.log.length;
        const { code, out } = await run(env(TOKEN, 'http://127.0.0.1:9'), {
            CAREER_TOOLKIT_URL: fake.origin,
        });

        assert.equal(code, 0, out);
        assert.ok(fake.log.length > before);
        assert.match(out, /url from shell/);
    });

    it("prefers a shell token over the file's", async () => {
        const { code, out } = await run(env('wrong-file-token'), {
            PREPARER_WORKER_TOKEN: TOKEN,
        });

        assert.equal(code, 0, out);
        assert.match(out, /token from shell/);
    });

    it('stops with a clear message, and no secret, when a setting is missing', async () => {
        const { code, out } = await run(
            'APP_KEY=base64:SECRETAPPKEYSECRETAPPKEY=\nDB_PASSWORD=super-secret-db-password\n',
        );

        assert.equal(code, 1);
        assert.match(
            out,
            /missing the application URL .* and PREPARER_WORKER_TOKEN/,
        );
        assert.ok(
            !out.includes('SECRETAPPKEY') &&
                !out.includes('super-secret-db-password'),
        );
    });

    it('stops when there is no .env at all and the shell sets nothing', async () => {
        const { code, out } = await run(null);
        assert.equal(code, 1);
        assert.match(out, /cannot start/);
    });

    it("does not start with the inspector's token: the wrong token is refused by Laravel", async () => {
        const { code } = await run(env('inspector-secret'));
        assert.equal(code, 1);
    });
});
