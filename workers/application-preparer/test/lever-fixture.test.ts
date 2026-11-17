// Tests of the Lever-style FIXTURE's own behaviour (not the engine): the
// resume parser's states, the location typeahead's clear-on-blur, and the
// hidden submit. They document what the fixture models and prove the
// guards hold on each path. Test code may drive the page directly; the
// worker itself still has no such capability.
import { after, before, describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { chromium, type Browser, type Page } from 'playwright';
import { leverResumeParseException } from '../src/adapters/index.ts';
import { SubmissionGuard, type UploadException } from '../src/guard.ts';
import { startServer, type TestServer } from './support/server.ts';
import {
    LEVER_AUTH_QUESTION,
    RESUME_PATH,
    fixtureHtml,
    runOn,
} from './support/run.ts';

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
    const context = await browser.newContext({
        viewport: { width: 1400, height: 1000 },
    });
    await guard.install(context);
    const page = await context.newPage();
    guard.setMainPage(page);
    await page.goto(`${server.origin}/lever-form.html`);
    guard.lockNavigation();
    server.mutations.length = 0;
    return { page, guard };
}

const state = (page: Page) =>
    page.evaluate(() => {
        const shown = (cls: string) =>
            (document.querySelector(`.resume-upload-${cls}`) as HTMLElement)
                .style.display !== 'none';
        return {
            failure: shown('failure'),
            working: shown('working'),
            success: shown('success'),
            filename: document.querySelector('.filename')!.textContent,
        };
    });

describe('Lever fixture: resume parser states', () => {
    it('shows the filename and "Analyzing resume..." while the parse is in flight, then "Success!"', async () => {
        server.parseDelayMs = 700;
        const { page, guard } = await open([
            leverResumeParseException(server.origin),
        ]);
        guard.openUploadWindow();
        await page.locator('#resume-upload-input').setInputFiles(RESUME_PATH);
        await page.waitForTimeout(250);
        assert.deepEqual(await state(page), {
            failure: false,
            working: true,
            success: false,
            filename: 'sample-resume.pdf',
        });
        await page.waitForTimeout(1200);
        assert.deepEqual(await state(page), {
            failure: false,
            working: false,
            success: true,
            filename: 'sample-resume.pdf',
        });
        guard.closeUploadWindow();
        assert.deepEqual(server.mutations, ['POST /parseResume']);
        server.parseDelayMs = 0;
        await page.context().close();
    });

    it('shows "Couldn\'t auto-read resume." when the parse request is refused by the guard', async () => {
        const { page } = await open([]); // no exception: the request is blocked
        await page.locator('#resume-upload-input').setInputFiles(RESUME_PATH);
        await page.waitForTimeout(500);
        assert.deepEqual(await state(page), {
            failure: true,
            working: false,
            success: false,
            filename: 'sample-resume.pdf',
        });
        assert.deepEqual(server.mutations, []);
        await page.context().close();
    });
});

describe('Lever fixture: location typeahead (the observed structure, the inferred blur behaviour)', () => {
    it('discards typed text on blur unless a suggestion (a value in the hidden input) was selected', async () => {
        const { page } = await open();
        await page.locator('#location-input').fill('Springfield');
        await page.locator('input[name="name"]').fill('Alex'); // moves focus away: blur
        assert.equal(
            await page.locator('#location-input').inputValue(),
            '',
            'unselected text is discarded',
        );
        assert.equal(await page.locator('#selected-location').inputValue(), '');

        // A person choosing a suggestion sets the hidden value; then the text sticks.
        await page.evaluate(() => {
            (
                document.getElementById('selected-location') as HTMLInputElement
            ).value = 'place-1';
        });
        await page.locator('#location-input').fill('Springfield');
        await page.locator('input[name="name"]').fill('Alex Example');
        assert.equal(
            await page.locator('#location-input').inputValue(),
            'Springfield',
        );
        await page.context().close();
    });
});

describe('Lever fixture: hidden submit and captcha markup', () => {
    it('has a hidden type=submit button, a hidden response input, and a zero-height captcha container', async () => {
        const { page } = await open();
        const facts = await page.evaluate(() => {
            const b = document.getElementById(
                'hcaptchaSubmitBtn',
            ) as HTMLButtonElement;
            return {
                type: b.type,
                visible: b.checkVisibility({ checkVisibilityCSS: true }),
                responseType: (
                    document.getElementById(
                        'hcaptchaResponseInput',
                    ) as HTMLInputElement
                ).type,
                containerHeight: document
                    .getElementById('h-captcha')!
                    .getBoundingClientRect().height,
            };
        });
        assert.deepEqual(facts, {
            type: 'submit',
            visible: false,
            responseType: 'hidden',
            containerHeight: 0,
        });
        await page.context().close();
    });

    it('the visible Submit fires the hidden submit; the guard blocks it and nothing reaches the server', async () => {
        const { page, guard } = await open([
            leverResumeParseException(server.origin),
        ]);
        // Native validation would stop an empty form before any submit event; disable it so the
        // guard, not the browser, is what is being tested.
        await page.evaluate(() => {
            (
                document.getElementById('application-form') as HTMLFormElement
            ).noValidate = true;
            document.getElementById('btn-submit')!.click();
        });
        await page.waitForTimeout(300);
        assert.ok(guard.events.some((e) => e.kind === 'submit_event_blocked'));
        assert.deepEqual(server.mutations, []);
        await page.context().close();
    });

    it('clicking the hidden submit directly is blocked too, as is requestSubmit with it as the submitter', async () => {
        const { page, guard } = await open();
        await page.evaluate(() => {
            (
                document.getElementById('application-form') as HTMLFormElement
            ).noValidate = true;
            (
                document.getElementById(
                    'hcaptchaSubmitBtn',
                ) as HTMLButtonElement
            ).click();
        });
        await page.evaluate(() => {
            const f = document.getElementById(
                'application-form',
            ) as HTMLFormElement;
            f.requestSubmit(
                document.getElementById(
                    'hcaptchaSubmitBtn',
                ) as HTMLButtonElement,
            );
        });
        await page.waitForTimeout(300);
        assert.ok(
            guard.events.filter((e) => e.kind === 'submit_event_blocked')
                .length >= 2,
        );
        assert.deepEqual(server.mutations, []);
        await page.context().close();
    });
});

describe('Lever fixture through the engine: hidden submit paths stay guarded', () => {
    const answers = { [LEVER_AUTH_QUESTION]: 'Yes' };
    const variant = async (path: string, script: string) => {
        const html = (await fixtureHtml('lever-form.html')).replace(
            '</body>',
            `<script>${script}</script></body>`,
        );
        server.add(path, html);
        return path;
    };

    it('a page script that fires the hidden submit is a safety failure and posts nothing', async () => {
        const path = await variant(
            '/hidden-submit-fired.html',
            `setTimeout(() => { document.getElementById('application-form').noValidate = true; document.getElementById('hcaptchaSubmitBtn').click(); }, 300)`,
        );
        const r = await runOn(server, path, 'lever', { input: { answers } });
        assert.equal(r.report.outcome, 'safety_failure');
        assert.ok(
            r.report.safetyEvents.some(
                (e) => e.kind === 'submit_event_blocked',
            ),
        );
        assert.deepEqual(server.unexpected(), []);
    });

    it('a script that fills the hidden captcha response and then submits is stopped the same way', async () => {
        const path = await variant(
            '/hidden-response-submit.html',
            `setTimeout(() => { document.getElementById('application-form').noValidate = true; document.getElementById('hcaptchaResponseInput').value = 'x'; document.getElementById('application-form').requestSubmit(); }, 300)`,
        );
        const r = await runOn(server, path, 'lever', { input: { answers } });
        assert.equal(r.report.outcome, 'safety_failure');
        assert.deepEqual(server.unexpected(), []);
    });

    it('the clean fixture reports the hidden submit but is ready, and it was never activated', async () => {
        const r = await runOn(server, '/lever-form.html', 'lever', {
            input: { answers },
        });
        assert.equal(
            r.report.outcome,
            'ready_for_review',
            JSON.stringify(r.report.reasons),
        );
        assert.equal(
            r.report.controls.filter((c) => c.hidden && c.role === 'submit')
                .length,
            1,
        );
        assert.equal(r.report.submission.blockedAttempts, 0);
        assert.deepEqual(server.unexpected(), []);
    });
});
