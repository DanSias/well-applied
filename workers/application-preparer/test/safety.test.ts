// End-to-end safety scenarios through the real engine. Each variant is the
// Lever-style fixture with something added. The recurring assertion is made
// from the SERVER's side: it never received a mutating request.
import { after, before, describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { mkdtemp, readFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { prepareApplication } from '../src/prepare.ts';
import { startServer, type TestServer } from './support/server.ts';
import {
    fixtureHtml,
    LEVER_AUTH_QUESTION,
    RESUME_PATH,
    runOn,
    syntheticInput,
} from './support/run.ts';

let server: TestServer;
let base: string;
const ANSWERS = { [LEVER_AUTH_QUESTION]: 'Yes' };

before(async () => {
    server = await startServer();
    base = await fixtureHtml('lever-form.html');
});
after(async () => server.close());

function variant(path: string, edit: (html: string) => string): string {
    const html = edit(base);
    assert.notEqual(html, base, `variant ${path} changed nothing`);
    server.add(path, html);
    return path;
}
const ready = (path: string, options = {}) =>
    runOn(server, path, 'lever', { input: { answers: ANSWERS }, options });

describe('hostile or unexpected pages', () => {
    it('a page that submits its own form after load is a safety failure and reaches no server', async () => {
        const path = variant('/auto-submit.html', (h) =>
            h.replace(
                '</body>',
                `<script>setTimeout(() => document.getElementById('application-form').submit(), 1200)</script></body>`,
            ),
        );
        const { report } = await ready(path);
        assert.equal(report.outcome, 'safety_failure');
        assert.ok(
            report.safetyEvents.some((e) => e.kind === 'submit_event_blocked'),
        );
        assert.equal(report.submission.blockedAttempts >= 1, true);
        assert.deepEqual(server.unexpected(), []);
    });

    it('a page that POSTs when a field is filled is a safety failure even though every field verified', async () => {
        const path = variant('/post-on-input.html', (h) =>
            h.replace(
                '</body>',
                `<script>document.querySelector('[name=email]').addEventListener('input', () => fetch('/example-company/0000/apply', { method: 'POST', body: 'x' }))</script></body>`,
            ),
        );
        const { report } = await ready(path);
        assert.equal(report.outcome, 'safety_failure');
        assert.ok(
            report.safetyEvents.some(
                (e) => e.kind === 'blocked_mutating_request',
            ),
        );
        assert.equal(
            report.fields.find((f) => f.name === 'email')?.status,
            'filled_verified',
        );
        assert.deepEqual(server.unexpected(), []);
    });

    it('a page that navigates away is a safety failure', async () => {
        const path = variant('/navigates.html', (h) =>
            h.replace(
                '</body>',
                `<script>setTimeout(() => { location.href = '/elsewhere.html'; }, 1800)</script></body>`,
            ),
        );
        const { report } = await ready(path);
        assert.equal(report.outcome, 'safety_failure');
        assert.ok(
            report.safetyEvents.some((e) => e.kind === 'unexpected_navigation'),
        );
    });

    it('a page that already looks like a confirmation is flagged as a suspected submission', async () => {
        const path = variant('/confirmation.html', (h) =>
            h.replace(
                '<div class="content">',
                '<div class="content"><h1>Thank you for applying!</h1>',
            ),
        );
        const { report } = await ready(path);
        assert.equal(report.outcome, 'safety_failure');
        assert.equal(report.submission.suspected, true);
        assert.ok(
            report.safetyEvents.some((e) => e.kind === 'suspected_submission'),
        );
    });

    it('closes a popup the page opens', async () => {
        const path = variant('/popup-open.html', (h) =>
            h.replace(
                '</body>',
                `<script>setTimeout(() => window.open('/popup.html'), 1200)</script></body>`,
            ),
        );
        const { report } = await ready(path);
        assert.equal(report.outcome, 'safety_failure');
        assert.ok(
            report.safetyEvents.some((e) => e.kind === 'unexpected_navigation'),
        );
    });
});

describe('fail closed on ambiguity', () => {
    describe('captcha states', () => {
        const slot = '<div id="captcha-slot"></div>';
        const withCaptcha = (path: string, markup: string) =>
            variant(path, (h) => h.replace(slot, markup));
        const frame = (title: string, style: string) =>
            `<iframe title="${title}" srcdoc="<p>captcha</p>" style="${style}"></iframe>`;
        const CHECKBOX =
            'Widget containing checkbox for hCaptcha security challenge';
        const CHALLENGE = 'Main content of the hCaptcha challenge';

        it('REGRESSION: sized but hidden/off-screen hCaptcha markup is inactive, not a blocker', async () => {
            // The first live Lever run flagged the page as having a blocking captcha although
            // none was visible. The old check ignored visibility:hidden, opacity and position.
            const path = withCaptcha(
                '/captcha-inactive.html',
                [
                    // sized container, hidden by visibility
                    '<div class="h-captcha" data-sitekey="x" style="width:303px;height:78px;visibility:hidden"></div>',
                    // sized iframe pushed off-screen, as hCaptcha does for its challenge
                    `<div style="position:absolute;top:-10000px;left:-10000px">${frame(CHALLENGE, 'width:400px;height:600px')}</div>`,
                    // opacity 0, display none, aria-hidden, declared invisible, zero size
                    `<div style="opacity:0">${frame(CHECKBOX, 'width:303px;height:78px')}</div>`,
                    `<div style="display:none">${frame(CHECKBOX, 'width:303px;height:78px')}</div>`,
                    `<div aria-hidden="true">${frame(CHECKBOX, 'width:303px;height:78px')}</div>`,
                    '<div class="h-captcha" data-size="invisible" data-sitekey="x" style="width:303px;height:78px"></div>',
                    frame(CHECKBOX, 'width:0;height:0;border:0'),
                    '<div class="grecaptcha-badge" style="width:256px;height:60px"></div>',
                ].join(''),
            );
            const { report } = await ready(path);
            assert.equal(
                report.blockers.captcha,
                'inactive_markup',
                JSON.stringify(report.blockers.captchaDetails),
            );
            assert.equal(report.blockers.captchaDetails.length >= 8, true);
            assert.ok(
                report.blockers.captchaDetails.every(
                    (d) => d.verdict === 'inactive',
                ),
            );
            assert.ok(
                report.blockers.captchaDetails.some((d) =>
                    d.signals.includes('not_rendered_visible'),
                ),
            );
            assert.ok(
                report.blockers.captchaDetails.some((d) =>
                    d.signals.includes('offscreen'),
                ),
            );
            assert.ok(
                report.blockers.captchaDetails.some((d) =>
                    d.signals.includes('declared_invisible'),
                ),
            );
            assert.ok(
                report.blockers.captchaDetails.some((d) =>
                    d.signals.includes('aria_hidden'),
                ),
            );
            assert.ok(
                report.blockers.captchaDetails.some((d) =>
                    d.signals.includes('zero_size'),
                ),
            );
            assert.equal(
                report.outcome,
                'ready_for_review',
                JSON.stringify(report.reasons),
            );
            assert.ok(
                report.warnings.some((w) =>
                    /captcha markup present but inactive/.test(w),
                ),
            );
        });

        it('a displayed checkbox widget is a confirmed blocker', async () => {
            const path = withCaptcha(
                '/captcha-checkbox.html',
                frame(CHECKBOX, 'width:303px;height:78px;border:0'),
            );
            const { report } = await ready(path);
            assert.equal(report.blockers.captcha, 'challenge_visible');
            assert.equal(report.outcome, 'blocked');
            assert.match(
                report.reasons.join(' '),
                /visible captcha challenge needs a human/,
            );
            assert.equal(
                report.blockers.captchaDetails.find(
                    (d) => d.verdict === 'challenge',
                )?.role,
                'checkbox_widget',
            );
            assert.deepEqual(server.unexpected(), []);
        });

        it('a displayed challenge frame is a confirmed blocker', async () => {
            const path = withCaptcha(
                '/captcha-challenge.html',
                frame(CHALLENGE, 'width:400px;height:520px;border:0'),
            );
            const { report } = await ready(path);
            assert.equal(report.blockers.captcha, 'challenge_visible');
            assert.equal(
                report.blockers.captchaDetails.find(
                    (d) => d.verdict === 'challenge',
                )?.role,
                'challenge_frame',
            );
            assert.equal(report.outcome, 'blocked');
        });

        it('displayed markup that cannot be classified is reported as uncertain, not as a blocker or as absent', async () => {
            const path = withCaptcha(
                '/captcha-uncertain.html',
                '<div class="h-captcha" data-sitekey="x" style="width:300px;height:80px"></div>',
            );
            const { report } = await ready(path);
            assert.equal(report.blockers.captcha, 'uncertain');
            assert.equal(report.outcome, 'blocked');
            assert.match(
                report.reasons.join(' '),
                /captcha state is uncertain/,
            );
            assert.doesNotMatch(report.reasons.join(' '), /needs a human/);
        });

        it('the fixture\'s own hidden hCaptcha-style markup is inactive_markup; with no markup at all it is "none"', async () => {
            const withMarkup = await ready('/lever-form.html');
            assert.equal(withMarkup.report.blockers.captcha, 'inactive_markup');
            const path = variant('/no-captcha.html', (h) =>
                h.replace(
                    /<!-- captcha-begin -->[\s\S]*?<!-- captcha-end -->/,
                    '',
                ),
            );
            const { report } = await ready(path);
            assert.equal(report.blockers.captcha, 'none');
            assert.deepEqual(report.blockers.captchaDetails, []);
        });

        it('never records page text, only tag, title, host, size and signals', async () => {
            const path = withCaptcha(
                '/captcha-fields.html',
                frame(CHECKBOX, 'width:303px;height:78px;border:0'),
            );
            const { report } = await ready(path);
            assert.deepEqual(
                Object.keys(report.blockers.captchaDetails[0]!).sort(),
                [
                    'height',
                    'inBadge',
                    'role',
                    'signals',
                    'sizeParam',
                    'srcHost',
                    'srcPath',
                    'tag',
                    'title',
                    'verdict',
                    'width',
                ],
            );
        });
    });

    it('Next/Continue (multistep) is a blocker; it is never clicked', async () => {
        const path = variant('/multistep.html', (h) =>
            h.replace(
                /<button\b[^>]*id="btn-submit"/,
                '<button type="button" id="next">Continue</button>$&',
            ),
        );
        const { report } = await ready(path);
        assert.equal(report.outcome, 'blocked');
        assert.equal(report.blockers.multistep, true);
        assert.ok(report.controls.some((c) => c.role === 'navigation'));
    });

    it('a login field is a blocker', async () => {
        const path = variant('/login.html', (h) =>
            h.replace(
                '<ul>',
                '<ul><li><label>Password<input type="password" name="pw"></label></li>',
            ),
        );
        const { report } = await ready(path);
        assert.equal(report.outcome, 'blocked');
        assert.equal(report.blockers.authentication, true);
    });

    it('no recognisable resume field is a blocker', async () => {
        const path = variant('/no-resume.html', (h) =>
            h.replace(
                /<li class="application-question resume">[\s\S]*?<\/li>/,
                '',
            ),
        );
        const { report } = await ready(path);
        assert.equal(report.outcome, 'blocked');
        assert.match(report.reasons.join(' '), /no resume upload field/);
    });

    it('a widget that empties the file input leaves the upload unverified (blocked), not assumed good', async () => {
        const path = variant('/clearing-widget.html', (h) =>
            h.replace(
                '</body>',
                `<span id="shown"></span><script>
              const i = document.getElementById('resume-upload-input');
              i.addEventListener('change', () => { document.getElementById('shown').textContent = i.files[0].name; i.value = ''; });
            </script></body>`,
            ),
        );
        const { report } = await ready(path);
        assert.equal(report.resume.verification, 'page_text');
        assert.equal(report.resume.verified, false);
        assert.equal(report.outcome, 'blocked');
        assert.match(report.reasons.join(' '), /could not be verified/);
    });

    it('a field whose label only resembles a standard one is refused, not guessed', async () => {
        const path = variant('/preferred-name.html', (h) =>
            h.replace(
                '<h4>Links</h4>',
                '<ul><li class="application-question"><label><div class="application-label">Preferred name <span class="required">✱</span></div><div class="application-field"><input type="text" name="pref"></div></label></li></ul><h4>Links</h4>',
            ),
        );
        const { report } = await ready(path);
        const f = report.fields.find((x) => x.name === 'pref');
        assert.equal(f?.classification, 'uncertain');
        assert.equal(f?.status, 'unresolved_uncertain');
        assert.equal(f?.actual, undefined);
        assert.equal(report.outcome, 'input_required');
    });
});

describe('resume-parse exception through the engine (the Lever rule, local origin)', () => {
    it('the fixture parses on file select; with the Lever exception the run is ready and exactly that POST reached the server', async () => {
        const r = await ready('/lever-form.html');
        assert.equal(
            r.report.outcome,
            'ready_for_review',
            JSON.stringify(r.report.reasons),
        );
        assert.deepEqual(server.mutations, ['POST /parseResume']);
        assert.deepEqual(
            r.report.safetyEvents.map((e) => e.kind),
            ['allowed_exception_used'],
        );
        assert.match(
            r.report.safetyEvents[0]!.detail,
            /lever-resume-parse: POST .*\/parseResume \[(xhr|fetch), multipart\/form-data/,
        );
        assert.equal(r.report.resume.verification, 'content_sha256');
    });

    it('without the exception the same request is blocked and fails the run; nothing reaches the server', async () => {
        const { report } = await ready('/lever-form.html', {
            uploadExceptions: [],
        });
        assert.equal(report.outcome, 'safety_failure');
        assert.deepEqual(server.mutations, []);
        assert.ok(
            report.safetyEvents.some(
                (e) =>
                    e.kind === 'blocked_mutating_request' &&
                    /\/parseResume \[/.test(e.detail),
            ),
        );
        assert.equal(
            report.resume.verified,
            true,
            'the upload verified; the blocked request is what failed the run',
        );
    });

    it('a parse sent with a JSON body is refused, and the reason names what was seen', async () => {
        const path = variant('/parse-json.html', (h) =>
            h.replace(
                /\{\s*method:\s*'POST',\s*body,?\s*\}/,
                "{ method: 'POST', body: '{}', headers: { 'content-type': 'application/json' } }",
            ),
        );
        const { report } = await ready(path);
        assert.equal(report.outcome, 'safety_failure');
        assert.deepEqual(server.mutations, []);
        assert.match(
            report.safetyEvents.map((e) => e.detail).join(' '),
            /refused: content-type application\/json/,
        );
    });

    it('a parse sent to a near-matching path is refused', async () => {
        for (const [name, from, to] of [
            ['longer', "'/parseResume'", "'/parseResume/extra'"],
            ['query', "'/parseResume'", "'/parseResume?x=1'"],
            ['case', "'/parseResume'", "'/parseresume'"],
        ] as const) {
            const path = variant(`/parse-${name}.html`, (h) =>
                h.replace(from, to),
            );
            const { report } = await ready(path);
            assert.equal(report.outcome, 'safety_failure', name);
            assert.deepEqual(server.mutations, [], name);
        }
    });

    it("a parse the PAGE sends on its own, outside the worker's upload, is refused", async () => {
        const path = variant('/parse-early.html', (h) =>
            h.replace(
                '</body>',
                `<script>setTimeout(() => { const b = new FormData(); b.append('x', new Blob(['y'])); fetch('/parseResume', { method: 'POST', body: b }).catch(() => {}); }, 100)</script></body>`,
            ),
        );
        const { report } = await ready(path);
        // The early POST is refused; the worker's own upload-time parse is still allowed once.
        assert.equal(report.outcome, 'safety_failure');
        assert.match(
            report.safetyEvents.map((e) => e.detail).join(' '),
            /not during a worker-initiated resume attach/,
        );
        assert.deepEqual(
            server.mutations,
            ['POST /parseResume'],
            'only the allowed upload-time request arrived',
        );
    });

    it('a second parse in the same run is refused (maxUses: 1)', async () => {
        const path = variant('/parse-twice.html', (h) =>
            h.replace(
                "document.querySelector('.filename').textContent = file.name;",
                "document.querySelector('.filename').textContent = file.name; fetch('/parseResume', { method: 'POST', body: (() => { const b = new FormData(); b.append('r', file); return b; })() }).catch(() => {});",
            ),
        );
        const { report } = await ready(path);
        assert.equal(report.outcome, 'safety_failure');
        assert.match(
            report.safetyEvents.map((e) => e.detail).join(' '),
            /already used 1x/,
        );
        assert.deepEqual(server.mutations, ['POST /parseResume']);
    });

    it('does not authorise submission: the form POST to its apply endpoint stays blocked even with the exception', async () => {
        const path = variant('/parse-plus-submit.html', (h) =>
            h.replace(
                '</body>',
                `<script>document.getElementById('resume-upload-input').addEventListener('change', () => setTimeout(() => fetch('/example-company/0000/apply', { method: 'POST', body: new FormData(document.getElementById('application-form')) }).catch(() => {}), 50))</script></body>`,
            ),
        );
        const { report } = await ready(path);
        assert.equal(report.outcome, 'safety_failure');
        assert.ok(
            report.safetyEvents.some(
                (e) =>
                    e.kind === 'blocked_mutating_request' &&
                    /example-company\/0000\/apply/.test(e.detail),
            ),
        );
        assert.deepEqual(
            server.unexpected(),
            [],
            'the apply endpoint never received anything',
        );
    });

    it('the Cloudflare beacon is recorded as a bot-detection request, stays blocked and does not fail the run', async () => {
        const path = variant('/cloudflare.html', (h) =>
            h.replace(
                '</body>',
                `<script>setTimeout(() => fetch('/cdn-cgi/challenge-platform/h/b/jsd/oneshot/f8df4d4a0cd6/0.5:123:abc_DEF-9/a5bf64858f23572e', { method: 'POST', body: 'x' }).catch(() => {}), 200)</script></body>`,
            ),
        );
        const { report } = await ready(path);
        assert.equal(
            report.outcome,
            'ready_for_review',
            JSON.stringify(report.reasons),
        );
        assert.ok(
            report.safetyEvents.some(
                (e) => e.kind === 'blocked_bot_detection_request',
            ),
            'preserved in the report',
        );
        assert.deepEqual(server.unexpected(), [], 'still blocked');
    });
});

describe('human hold', () => {
    it('keeps the guard active: a human click on Submit is blocked and turns the run into a safety failure', async () => {
        const result = await ready('/lever-form.html', {
            hold: true,
            holdUntil: async (page: import('playwright').Page) => {
                // The "human" presses Submit. (A fixed cookie banner can intercept a coordinate click, so
                // press it through the DOM; the guard must catch the resulting submit either way.)
                await page.evaluate(() =>
                    document.getElementById('btn-submit')!.click(),
                );
                await page.waitForTimeout(500);
            },
        });
        const { report } = result;
        assert.equal(report.diagnostics.held, true);
        assert.equal(report.outcome, 'safety_failure');
        const ev = report.safetyEvents.find(
            (e) => e.kind === 'submit_event_blocked',
        );
        assert.equal(ev?.phase, 'human_hold');
        assert.deepEqual(server.unexpected(), []);
        const onDisk = JSON.parse(await readFile(result.reportPath, 'utf8'));
        assert.equal(
            onDisk.outcome,
            'safety_failure',
            'the report on disk reflects the hold',
        );
    });
});

describe('inputs and hygiene', () => {
    it('refuses an unsupported host without launching a browser', async () => {
        const input = await syntheticInput('https://example.com/careers/apply');
        const r = await prepareApplication(input, {
            headless: true,
            runsDir: await mkdtemp(join(tmpdir(), 'preparer-test-')),
        });
        assert.equal(r.report.outcome, 'unsupported');
        assert.equal(r.report.diagnostics.durationMs < 1000, true);
    });

    it('refuses a non-PDF resume, a bad runId and a reused runId', async () => {
        const html = join(dirname(RESUME_PATH), '..', 'package.json');
        await assert.rejects(
            runOn(server, '/lever-form.html', 'lever', {
                input: { resumePdfPath: html },
            }),
            /not a PDF/,
        );
        await assert.rejects(
            runOn(server, '/lever-form.html', 'lever', {
                input: { runId: '../escape' },
            }),
            /runId/,
        );
        const first = await runOn(server, '/lever-form.html', 'lever', {
            input: { runId: 'dup-run' },
        });
        await assert.rejects(
            prepareApplication(
                await syntheticInput(`${server.origin}/lever-form.html`, {
                    runId: 'dup-run',
                }),
                {
                    headless: true,
                    allowLocalFixture: true,
                    ats: 'lever',
                    runsDir: first.runsDir,
                },
            ),
            /EEXIST/,
        );
    });

    it('the report never contains a query string or the applicant URL fragment', async () => {
        const { report } = await runOn(
            server,
            '/lever-form.html?token=SECRET#frag',
            'lever',
        );
        const text = JSON.stringify(report);
        assert.ok(!text.includes('SECRET') && !text.includes('frag'));
    });

    it('git ignores runs, profiles and screenshots (they contain applicant data)', () => {
        const cwd = dirname(dirname(fileURLToPath(import.meta.url)));
        for (const p of [
            'runs/x/report.json',
            'runs/x/01-before.png',
            'profiles/a/Cookies',
            'x.profile/Default',
        ]) {
            const r = spawnSync('git', ['check-ignore', '-q', p], { cwd });
            assert.equal(r.status, 0, `${p} must be gitignored`);
        }
    });
});
