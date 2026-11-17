import { after, before, describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { chromium, type Browser, type Page } from 'playwright';
import { SubmissionGuard, type UploadException } from '../src/guard.ts';
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

async function open(
    exceptions: UploadException[] = [],
): Promise<{ page: Page; guard: SubmissionGuard }> {
    const guard = new SubmissionGuard(new URL(server.origin), exceptions);
    const context = await browser.newContext();
    await guard.install(context);
    const page = await context.newPage();
    guard.setMainPage(page);
    await page.goto(`${server.origin}/hostile.html`);
    guard.lockNavigation();
    server.mutations.length = 0;
    return { page, guard };
}

const attempt = (page: Page, name: string) =>
    page.evaluate(async (n) => {
        await (window as unknown as { __tryAll: Record<string, () => unknown> })
            .__tryAll[n]!();
    }, name);
const settle = (page: Page) => page.waitForTimeout(300);

describe('submission guard', () => {
    it('blocks a submit event (a click on a submit button)', async () => {
        const { page, guard } = await open();
        await attempt(page, 'submitEvent');
        await settle(page);
        assert.deepEqual(server.mutations, []);
        assert.ok(guard.events.some((e) => e.kind === 'submit_event_blocked'));
        assert.equal(guard.hasFailure(), true);
        await page.context().close();
    });

    it('blocks form.submit() and form.requestSubmit()', async () => {
        const { page, guard } = await open();
        await attempt(page, 'formSubmit');
        await attempt(page, 'requestSubmit');
        await settle(page);
        assert.deepEqual(server.mutations, []);
        assert.equal(
            guard.events.filter((e) => e.kind === 'submit_event_blocked')
                .length >= 2,
            true,
        );
        await page.context().close();
    });

    it('blocks a script-initiated POST with fetch, even with the page-event layer bypassed', async () => {
        const { page, guard } = await open();
        await attempt(page, 'fetchPost');
        await settle(page);
        assert.deepEqual(
            server.mutations,
            [],
            'the server must never see the request',
        );
        assert.deepEqual(
            guard.events.map((e) => e.kind),
            ['blocked_mutating_request'],
        );
        await page.context().close();
    });

    it('blocks sendBeacon', async () => {
        const { page, guard } = await open();
        await attempt(page, 'beacon');
        await settle(page);
        assert.deepEqual(server.mutations, []);
        assert.equal(guard.events[0]?.kind, 'blocked_mutating_request');
        await page.context().close();
    });

    it('blocks a GET form submission and any other navigation after the form has loaded', async () => {
        const { page, guard } = await open();
        await attempt(page, 'getForm');
        await settle(page);
        await attempt(page, 'navigate');
        await settle(page);
        // An aborted main-frame navigation leaves Chromium on its error page (documented in the README: fail closed,
        // the form state is lost, nothing is submitted). What matters is that the target was never reached.
        assert.ok(
            !page.url().includes('elsewhere') &&
                !page.url().includes('get-form'),
            `navigated to ${page.url()}`,
        );
        assert.ok(
            guard.events.filter((e) => e.kind === 'unexpected_navigation')
                .length >= 1,
        );
        await page.context().close();
    });

    it('closes popups', async () => {
        const { page, guard } = await open();
        await attempt(page, 'popup');
        await settle(page);
        assert.equal(page.context().pages().length, 1);
        assert.ok(guard.events.some((e) => e.kind === 'unexpected_navigation'));
        await page.context().close();
    });

    it('records third-party mutations as blocked, but not as a safety failure', async () => {
        const guard = new SubmissionGuard(
            new URL('https://jobs.lever.co/x/1/apply'),
        );
        const context = await browser.newContext();
        await guard.install(context);
        const page = await context.newPage();
        guard.setMainPage(page);
        await context.route(
            'https://jobs.lever.co/x/1/apply',
            (r) =>
                r.fulfill({
                    contentType: 'text/html',
                    body: '<html><body>form</body></html>',
                }),
            {},
        );
        // The guard's catch-all route was registered first; Playwright runs the most recently
        // registered matching route first, so the fulfil above serves the page and the guard
        // still sees every other request.
        await page.goto('https://jobs.lever.co/x/1/apply');
        guard.lockNavigation();
        await page.evaluate(() =>
            fetch('https://analytics.example.test/collect', {
                method: 'POST',
                body: 'x',
            }).catch(() => 0),
        );
        assert.deepEqual(
            guard.events.map((e) => e.kind),
            ['blocked_third_party_request'],
        );
        assert.equal(guard.hasFailure(), false);
        await context.close();
    });

    describe('upload exceptions', () => {
        const exception = (
            over: Partial<UploadException> = {},
        ): UploadException => ({
            name: 'test-resume-parse',
            method: 'POST',
            origin: '',
            pathPattern: /^\/parse-resume$/,
            why: 'fixture: the form posts the chosen file here to pre-fill',
            ...over,
        });

        it('allows exactly the named method + origin + path, and records its use', async () => {
            const { page, guard } = await open([
                exception({ origin: server.origin }),
            ]);
            await attempt(page, 'upload');
            await settle(page);
            assert.deepEqual(server.mutations, ['POST /parse-resume']);
            assert.deepEqual(
                guard.events.map((e) => e.kind),
                ['allowed_exception_used'],
            );
            assert.equal(guard.hasFailure(), false);
            await page.context().close();
        });

        it('does not allow a longer path, another path, another method or another origin', async () => {
            const { page, guard } = await open([
                exception({ origin: server.origin }),
            ]);
            await attempt(page, 'uploadElsewhere'); // /parse-resume/extra
            await attempt(page, 'fetchPost'); // /fetch-post
            await settle(page);
            assert.deepEqual(server.mutations, []);
            assert.equal(
                guard.events.filter(
                    (e) => e.kind === 'blocked_mutating_request',
                ).length,
                2,
            );

            const wrongOrigin = await open([
                exception({ origin: 'http://127.0.0.1:1' }),
            ]);
            await attempt(wrongOrigin.page, 'upload');
            await settle(wrongOrigin.page);
            assert.deepEqual(server.mutations, []);
            await page.context().close();
            await wrongOrigin.page.context().close();
        });

        it('refuses an unanchored pattern or a missing justification at construction time', () => {
            assert.throws(
                () =>
                    new SubmissionGuard(new URL(server.origin), [
                        exception({ pathPattern: /parse-resume/ }),
                    ]),
                /anchored/,
            );
            assert.throws(
                () =>
                    new SubmissionGuard(new URL(server.origin), [
                        exception({ why: ' ' }),
                    ]),
                /justification/,
            );
        });
    });
});
