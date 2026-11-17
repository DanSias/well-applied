// The hold lifecycle in isolation: what ends a hold, and that the STOP file
// can do nothing except end it. No engine run, so these are fast.
import { after, before, describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, mkdir, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { chromium, type Browser } from 'playwright';
import { SubmissionGuard } from '../src/guard.ts';
import {
    STOP_FILE_NAME,
    stopFilePath,
    waitForHoldEnd,
    type HoldStdin,
} from '../src/hold.ts';
import { startServer, type TestServer } from './support/server.ts';

let server: TestServer;
let browser: Browser;
before(async () => {
    server = await startServer();
    browser = await chromium.launch({ headless: true });
});
after(async () => {
    await browser.close();
    await server.close();
});

class FakeStdin implements HoldStdin {
    isTTY: boolean;
    listeners: Array<() => void> = [];
    paused = false;
    constructor(isTTY: boolean) {
        this.isTTY = isTTY;
    }
    once(_e: 'data', l: () => void) {
        this.listeners.push(l);
    }
    removeListener(_e: 'data', l: () => void) {
        this.listeners = this.listeners.filter((x) => x !== l);
    }
    pause() {
        this.paused = true;
    }
    press() {
        for (const l of [...this.listeners]) l();
    }
}

const listeners = (p: object) =>
    (p as unknown as { listenerCount(e: string): number }).listenerCount(
        'close',
    );
const tmp = () => mkdtemp(join(tmpdir(), 'preparer-hold-'));
async function guarded() {
    const guard = new SubmissionGuard(new URL(server.origin));
    const context = await browser.newContext();
    await guard.install(context);
    const page = await context.newPage();
    guard.setMainPage(page);
    await page.goto(`${server.origin}/lever-form.html`);
    guard.lockNavigation();
    return { context, page, guard };
}

describe('hold lifecycle', () => {
    it('the STOP file name and path are fixed inside the run directory', () => {
        assert.equal(STOP_FILE_NAME, 'STOP');
        assert.equal(stopFilePath('/x/runs/r1'), '/x/runs/r1/STOP');
    });

    it('ends when the STOP file appears', async () => {
        const dir = await tmp();
        const { context, page } = await guarded();
        const ended = waitForHoldEnd(page, browser, {
            stopFile: stopFilePath(dir),
            stdin: new FakeStdin(false),
            pollMs: 50,
        });
        setTimeout(() => void writeFile(stopFilePath(dir), ''), 150);
        assert.equal(await ended, 'stop_file');
        await context.close();
    });

    it('a STOP file that already exists ends the hold immediately', async () => {
        const dir = await tmp();
        await writeFile(stopFilePath(dir), '');
        const { context, page } = await guarded();
        assert.equal(
            await waitForHoldEnd(page, browser, {
                stopFile: stopFilePath(dir),
                stdin: new FakeStdin(false),
            }),
            'stop_file',
        );
        await context.close();
    });

    it('the STOP file only ends the hold: its contents are never read or acted on, and the guard is untouched', async () => {
        const dir = await tmp();
        const { context, page, guard } = await guarded();
        const before = await page.evaluate(
            () => document.documentElement.outerHTML,
        );
        const hostile = JSON.stringify({
            action: 'click',
            selector: '#btn-submit',
            disableGuard: true,
            allow: ['POST https://jobs.lever.co/apply'],
        });
        const ended = waitForHoldEnd(page, browser, {
            stopFile: stopFilePath(dir),
            stdin: new FakeStdin(false),
            pollMs: 50,
        });
        setTimeout(() => void writeFile(stopFilePath(dir), hostile), 100);
        assert.equal(await ended, 'stop_file');
        assert.equal(
            await page.evaluate(() => document.documentElement.outerHTML),
            before,
            'nothing on the page changed',
        );
        assert.deepEqual(
            guard.events,
            [],
            'no event: nothing was clicked, submitted or navigated',
        );
        assert.deepEqual(
            server.mutations.filter((m) => !m.includes('parseResume')),
            [],
        );
        // The guard is still fully active afterwards.
        await page.evaluate(() =>
            fetch('/fetch-post', { method: 'POST', body: 'x' }).catch(() => 0),
        );
        const events = [...guard.events] as Array<{ kind: string }>;
        assert.equal(events.at(-1)?.kind, 'blocked_mutating_request');
        await context.close();
    });

    it('a directory or a symlink named STOP is just "something exists" and also only ends the hold', async () => {
        const dir = await tmp();
        await mkdir(stopFilePath(dir));
        const { context, page } = await guarded();
        assert.equal(
            await waitForHoldEnd(page, browser, {
                stopFile: stopFilePath(dir),
                stdin: new FakeStdin(false),
            }),
            'stop_file',
        );
        await context.close();
    });

    it('keeps the interactive behaviour: Enter ends the hold when stdin is a terminal', async () => {
        const dir = await tmp();
        const stdin = new FakeStdin(true);
        const { context, page } = await guarded();
        const ended = waitForHoldEnd(page, browser, {
            stopFile: stopFilePath(dir),
            stdin,
            pollMs: 50,
        });
        setTimeout(() => stdin.press(), 100);
        assert.equal(await ended, 'enter');
        assert.equal(
            stdin.listeners.length,
            0,
            'the stdin listener is removed',
        );
        assert.equal(
            stdin.paused,
            true,
            'stdin is released so the process can exit',
        );
        await context.close();
    });

    it('does not treat input as Enter when stdin is not a terminal (a supervisor must use the STOP file)', async () => {
        const dir = await tmp();
        const stdin = new FakeStdin(false);
        const { context, page } = await guarded();
        const ended = waitForHoldEnd(page, browser, {
            stopFile: stopFilePath(dir),
            stdin,
            pollMs: 50,
        });
        stdin.press();
        let early = false;
        void ended.then(() => (early = true));
        await new Promise((r) => setTimeout(r, 250));
        assert.equal(early, false);
        await writeFile(stopFilePath(dir), '');
        assert.equal(await ended, 'stop_file');
        await context.close();
    });

    it('ends when the window is closed', async () => {
        const dir = await tmp();
        const { context, page } = await guarded();
        const ended = waitForHoldEnd(page, browser, {
            stopFile: stopFilePath(dir),
            stdin: new FakeStdin(false),
            pollMs: 50,
        });
        await page.close();
        assert.equal(await ended, 'window_closed');
        await context.close();
    });

    it('ends when the browser disconnects', async () => {
        const own = await chromium.launch({ headless: true });
        const page = await own.newPage();
        const dir = await tmp();
        const ended = waitForHoldEnd(page, own, {
            stopFile: stopFilePath(dir),
            stdin: new FakeStdin(false),
            pollMs: 50,
        });
        await own.close();
        const reason = await ended;
        assert.ok(
            ['window_closed', 'browser_disconnected'].includes(reason),
            reason,
        );
    });

    it('removes its listeners and timers when it ends, so a finished hold leaves nothing behind', async () => {
        const dir = await tmp();
        const { context, page } = await guarded();
        const closeBefore = listeners(page);
        const stdin = new FakeStdin(true);
        const ended = waitForHoldEnd(page, browser, {
            stopFile: stopFilePath(dir),
            stdin,
            pollMs: 50,
        });
        assert.equal(listeners(page), closeBefore + 1);
        await writeFile(stopFilePath(dir), '');
        await ended;
        assert.equal(listeners(page), closeBefore);
        assert.equal(stdin.listeners.length, 0);
        await context.close();
    });
});
