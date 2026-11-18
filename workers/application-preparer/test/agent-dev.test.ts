// `composer run dev` support: readiness checks, one agent per checkout, and the dev-mode behaviour (stay idle and say why
// instead of exiting, so the supervisor never restart-loops) and clean shutdown on Ctrl+C. No live site, no queued attempt.
import { after, before, describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { acquireLock, dependencyProblems } from '../src/agent/readiness.ts';
import {
    startFakeLaravel,
    TOKEN,
    type FakeLaravel,
} from './support/fake-laravel.ts';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const sleep = (ms: number) => new Promise((r) => setTimeout(r, ms));

describe('dependency readiness', () => {
    it('is ready when Playwright resolves and its Chromium exists', async () => {
        assert.deepEqual(
            await dependencyProblems({
                chromiumPath: async () => '/x/chrome',
                exists: () => true,
            }),
            [],
        );
    });

    it('says to npm install when Playwright is not installed', async () => {
        const p = await dependencyProblems({
            chromiumPath: async () => {
                throw new Error('Cannot find package');
            },
            exists: () => true,
        });
        assert.equal(p.length, 1);
        assert.match(p[0]!, /npm install/);
    });

    it('says to install Chromium when it is missing', async () => {
        const p = await dependencyProblems({
            chromiumPath: async () => '/x/chrome',
            exists: () => false,
        });
        assert.match(p[0]!, /npx playwright install chromium/);
    });

    it('is ready in this checkout (dependencies and Chromium are installed for the suite)', async () => {
        assert.deepEqual(await dependencyProblems(), []);
    });
});

describe('one agent per checkout', () => {
    let dir: string;
    before(async () => (dir = await mkdtemp(join(tmpdir(), 'agent-lock-'))));
    after(() => rm(dir, { recursive: true, force: true }));

    it('refuses a second agent while the first is alive, and names its pid', () => {
        const path = join(dir, 'a.lock');
        const first = acquireLock(path, process.pid);
        assert.equal(first.ok, true);
        const second = acquireLock(path, process.pid + 1);
        assert.deepEqual(second, { ok: false, pid: process.pid });
        first.ok && first.release();
        assert.equal(existsSync(path), false);
    });

    it('takes over a lock left by a process that is gone', () => {
        const path = join(dir, 'stale.lock');
        writeFileSync(path, '2147483646'); // no such process
        const r = acquireLock(path, process.pid);
        assert.equal(r.ok, true);
        assert.equal(readFileSync(path, 'utf8'), String(process.pid));
        r.ok && r.release();
    });

    it('treats a garbled lock file as stale', () => {
        const path = join(dir, 'garbled.lock');
        writeFileSync(path, 'not a pid');
        const r = acquireLock(path, process.pid);
        assert.equal(r.ok, true);
        r.ok && r.release();
    });

    it("does not remove a lock that has since become someone else's", () => {
        const path = join(dir, 'mine.lock');
        const r = acquireLock(path, process.pid);
        writeFileSync(path, String(process.pid + 5));
        r.ok && r.release();
        assert.equal(existsSync(path), true);
    });
});

describe('start.ts under composer run dev', () => {
    let fake: FakeLaravel;
    let dir: string;
    before(async () => {
        fake = await startFakeLaravel({ leaseSeconds: 10 });
        dir = await mkdtemp(join(tmpdir(), 'agent-dev-'));
    });
    after(async () => {
        await fake.close();
        await rm(dir, { recursive: true, force: true });
    });

    let n = 0;
    const setup = (envText: string | null) => {
        n += 1;
        const envFile = join(dir, `env-${n}`);
        if (envText !== null) writeFileSync(envFile, envText);
        return { envFile, lockFile: join(dir, `lock-${n}`) };
    };
    const goodEnv = () =>
        `APP_KEY=base64:SECRETAPPKEY=\nDB_PASSWORD=super-secret-db-password\nAPP_URL=${fake.origin}\nPREPARER_WORKER_TOKEN=${TOKEN}\n`;

    const start = (
        args: string[],
        files: { envFile: string; lockFile: string },
        command: string[] = [process.execPath, join(ROOT, 'src', 'start.ts')],
    ) => {
        const child = spawn(command[0]!, [...command.slice(1), ...args], {
            env: {
                PATH: process.env.PATH ?? '',
                HOME: process.env.HOME ?? '',
                PREPARER_ENV_FILE: files.envFile,
                PREPARER_LOCK_FILE: files.lockFile,
                PREPARER_WORK_DIR: join(dir, 'work'),
                PREPARER_POLL_INTERVAL_MS: '200',
            },
            detached: true,
            cwd: ROOT,
        });
        const state = { out: '', code: undefined as number | null | undefined };
        child.stdout.on('data', (c) => (state.out += c));
        child.stderr.on('data', (c) => (state.out += c));
        const done = new Promise<void>((resolve) =>
            child.on('close', (code) => {
                state.code = code;
                resolve();
            }),
        );
        return { child, state, done };
    };
    const waitFor = async (cond: () => boolean, ms = 15_000) => {
        const t = Date.now();
        while (!cond() && Date.now() - t < ms) await sleep(50);
        assert.ok(cond(), 'timed out waiting');
    };
    const noSecrets = (out: string) => {
        for (const s of [TOKEN, 'super-secret-db-password', 'SECRETAPPKEY'])
            assert.ok(!out.includes(s), 'secret printed');
    };

    it('--check reports readiness without starting or polling anything', async () => {
        const before = fake.log.length;
        const r = start(['--check'], setup(goodEnv()));
        await r.done;
        assert.equal(r.state.code, 0, r.state.out);
        assert.match(r.state.out, /ready: settings and Chromium found/);
        assert.equal(fake.log.length, before, 'nothing was polled');
        noSecrets(r.state.out);
    });

    it('--check names every problem and exits 1', async () => {
        const r = start(['--check'], setup('APP_KEY=x\n'));
        await r.done;
        assert.equal(r.state.code, 1);
        assert.match(r.state.out, /no application URL/);
        assert.match(r.state.out, /no PREPARER_WORKER_TOKEN/);
    });

    it('--dev with a missing token says why and STAYS IDLE (no exit, no restart loop), then Ctrl+C ends it cleanly', async () => {
        const r = start(['--dev'], setup(`APP_URL=${fake.origin}\n`));
        await waitFor(() => /idle/.test(r.state.out));
        assert.match(r.state.out, /no PREPARER_WORKER_TOKEN/);
        await sleep(800);
        assert.equal(r.state.code, undefined, 'it must not exit on its own');
        process.kill(-r.child.pid!, 'SIGINT');
        await r.done;
        assert.equal(r.state.code, 0);
    });

    it('--dev polls for queued work only, starts no browser with an empty queue, and stops cleanly on Ctrl+C, releasing its lock', async () => {
        const files = setup(goodEnv());
        const before = fake.log.length;
        const r = start(['--dev'], files);
        await waitFor(() =>
            fake.log.slice(before).some((l) => /agent-runs\/claim 204/.test(l)),
        );
        assert.equal(
            existsSync(files.lockFile),
            true,
            'holds the lock while running',
        );
        const polled = fake.log.slice(before);
        // Only claim polls: no input, resume, heartbeat, screenshot or result calls, so nothing was prepared.
        assert.ok(
            polled.every((l) => /agent-runs\/claim/.test(l)),
            polled.join('\n'),
        );

        process.kill(-r.child.pid!, 'SIGINT');
        await r.done;
        assert.equal(r.state.code, 0, r.state.out);
        assert.equal(existsSync(files.lockFile), false, 'lock released');
        noSecrets(r.state.out);
    });

    it('a second agent in dev mode stays idle and says so; outside dev mode it exits 1; neither polls', async () => {
        const files = setup(goodEnv());
        const first = start(['--dev'], files);
        await waitFor(() => existsSync(files.lockFile));

        const before = fake.log.length;
        const dup = start(['--dev'], files);
        await waitFor(() => /already running/.test(dup.state.out));
        assert.match(dup.state.out, /idle/);
        const strict = start([], files);
        await strict.done;
        assert.equal(strict.state.code, 1);
        assert.match(strict.state.out, /already running/);

        process.kill(-dup.child.pid!, 'SIGINT');
        await dup.done;
        process.kill(-first.child.pid!, 'SIGINT');
        await first.done;
        assert.equal(first.state.code, 0);
        assert.ok(fake.log.slice(before).every((l) => /claim/.test(l)));
    });

    it('stops cleanly when Ctrl+C reaches the whole command the way the dev supervisor delivers it (sh -c, npm, node)', async () => {
        const files = setup(goodEnv());
        const before = fake.log.length;
        const r = start([], files, ['sh', '-c', 'cd . && npm run agent:dev']);
        await waitFor(
            () => fake.log.slice(before).some((l) => /claim 204/.test(l)),
            30_000,
        );

        process.kill(-r.child.pid!, 'SIGINT'); // the terminal's Ctrl+C goes to the whole process group
        await r.done;
        await sleep(300);
        assert.equal(
            existsSync(files.lockFile),
            false,
            'the agent released its lock',
        );
        let alive = true;
        try {
            process.kill(-r.child.pid!, 0);
        } catch {
            alive = false;
        }
        assert.equal(alive, false, 'no process of the group is left');
        noSecrets(r.state.out);
    });
});
