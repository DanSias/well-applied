// reCAPTCHA classification: a passive badge is not a challenge, and nothing that can need a person is
// assumed harmless. Markup is synthetic; frame contents come from srcdoc so no request leaves the machine,
// while the `src` attribute (what detection reads) looks like Google's.
import { after, before, describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { startServer, type TestServer } from './support/server.ts';
import { LEVER_AUTH_QUESTION, fixtureHtml, runOn } from './support/run.ts';

let server: TestServer;
let base: string;
before(async () => {
    server = await startServer();
    base = await fixtureHtml('lever-form.html');
});
after(async () => server.close());

const SLOT = '<div id="captcha-slot"></div>';
const ANCHOR = (query: string) =>
    `https://www.recaptcha.net/recaptcha/enterprise/anchor?k=synthetic&${query}`;
const BFRAME =
    'https://www.recaptcha.net/recaptcha/enterprise/bframe?hl=en&k=synthetic';
const frame = (src: string, style: string, title = 'reCAPTCHA') =>
    `<iframe title="${title}" src="${src}" srcdoc="<p>synthetic</p>" style="border:0;${style}"></iframe>`;
const FIXED = 'position:fixed;bottom:14px;right:0;z-index:5;';

let n = 0;
async function check(markup: string) {
    const path = `/recaptcha-${n++}.html`;
    const html = base.replace(SLOT, markup);
    assert.notEqual(html, base);
    server.add(path, html);
    const { report } = await runOn(server, path, 'lever', {
        input: { answers: { [LEVER_AUTH_QUESTION]: 'Yes' } },
        options: { parserWaitMs: 400 },
    });
    // The fixture's own hidden hCaptcha markup is always present (inactive); look only at reCAPTCHA elements.
    const details = report.blockers.captchaDetails.filter(
        (d) => /recaptcha/.test(d.srcPath) || d.role === 'badge',
    );
    return { report, details };
}

describe('passive reCAPTCHA badge', () => {
    it('REGRESSION (Discord): a displayed 256x60 invisible-size anchor frame is a passive badge, not an uncertain challenge', async () => {
        const { report, details } = await check(
            frame(ANCHOR('size=invisible'), `${FIXED}width:256px;height:60px`),
        );
        const d = details.find((x) => x.srcPath.endsWith('/anchor'))!;
        assert.equal(d.role, 'badge');
        assert.equal(d.sizeParam, 'invisible');
        assert.ok(d.signals.includes('passive_badge'));
        assert.equal(d.verdict, 'inactive');
        assert.equal(report.blockers.captcha, 'inactive_markup');
        assert.equal(
            report.outcome,
            'ready_for_review',
            JSON.stringify(report.reasons),
        );
        assert.ok(
            report.warnings.some((w) =>
                /captcha markup present but inactive/.test(w),
            ),
            'still reported as a warning',
        );
    });

    it('the badge container with its anchor frame inside is passive too', async () => {
        const { report, details } = await check(
            `<div class="grecaptcha-badge" style="${FIXED}width:256px;height:60px">${frame(ANCHOR('co=x'), 'width:256px;height:60px')}</div>`,
        );
        assert.ok(details.some((d) => d.inBadge && d.role === 'badge'));
        assert.equal(report.blockers.captcha, 'inactive_markup');
        assert.equal(
            report.outcome,
            'ready_for_review',
            JSON.stringify(report.reasons),
        );
    });

    it("a hidden challenge frame alongside the badge (reCAPTCHA's normal invisible layout) stays inactive", async () => {
        const hidden = `<div style="position:absolute;top:-10000px;left:-10000px;visibility:hidden">${frame(BFRAME, 'width:400px;height:580px')}</div>`;
        const { report, details } = await check(
            frame(ANCHOR('size=invisible'), `${FIXED}width:256px;height:60px`) +
                hidden,
        );
        assert.ok(
            details.some(
                (d) => d.role === 'challenge_frame' && d.verdict === 'inactive',
            ),
        );
        assert.equal(report.blockers.captcha, 'inactive_markup');
        assert.equal(
            report.outcome,
            'ready_for_review',
            JSON.stringify(report.reasons),
        );
    });
});

describe('interactive reCAPTCHA keeps blocking', () => {
    it('the "I\'m not a robot" checkbox (size=normal) is a challenge', async () => {
        const { report, details } = await check(
            frame(ANCHOR('size=normal'), 'width:304px;height:78px'),
        );
        const d = details.find((x) => x.srcPath.endsWith('/anchor'))!;
        assert.equal(d.role, 'checkbox_widget');
        assert.equal(d.verdict, 'challenge');
        assert.equal(report.blockers.captcha, 'challenge_visible');
        assert.equal(report.outcome, 'blocked');
    });

    it('an anchor frame with NO size parameter defaults to the checkbox, so it is a challenge', async () => {
        const { report } = await check(
            frame(ANCHOR('co=x'), 'width:304px;height:78px'),
        );
        assert.equal(report.blockers.captcha, 'challenge_visible');
        assert.equal(report.outcome, 'blocked');
    });

    it('the compact checkbox is a challenge', async () => {
        const { report } = await check(
            frame(ANCHOR('size=compact'), 'width:164px;height:144px'),
        );
        assert.equal(report.blockers.captcha, 'challenge_visible');
    });

    it('a visible challenge frame (bframe) is a challenge', async () => {
        const { report, details } = await check(
            frame(BFRAME, 'width:400px;height:580px'),
        );
        const d = details.find((x) => x.srcPath.endsWith('/bframe'))!;
        assert.equal(d.role, 'challenge_frame');
        assert.equal(d.verdict, 'challenge');
        assert.equal(report.blockers.captcha, 'challenge_visible');
        assert.equal(report.outcome, 'blocked');
    });

    it('a visible challenge frame is NOT excused by sitting inside a badge container', async () => {
        const { report } = await check(
            `<div class="grecaptcha-badge" style="width:256px;height:60px">${frame(BFRAME, 'width:400px;height:580px')}</div>`,
        );
        assert.equal(report.blockers.captcha, 'challenge_visible');
    });

    it('a passive badge does not mask a real checkbox shown with it', async () => {
        const { report } = await check(
            frame(ANCHOR('size=invisible'), `${FIXED}width:256px;height:60px`) +
                frame(ANCHOR('size=normal'), 'width:304px;height:78px'),
        );
        assert.equal(report.blockers.captcha, 'challenge_visible');
        assert.equal(report.outcome, 'blocked');
    });
});

describe('ambiguous reCAPTCHA markup is reported as uncertain, not assumed harmless', () => {
    it('an "invisible" anchor that is displayed far larger than a badge', async () => {
        const { report, details } = await check(
            frame(ANCHOR('size=invisible'), 'width:600px;height:400px'),
        );
        const d = details.find((x) => x.srcPath.endsWith('/anchor'))!;
        assert.ok(d.signals.includes('oversized_badge'));
        assert.equal(d.verdict, 'uncertain');
        assert.equal(report.blockers.captcha, 'uncertain');
        assert.equal(report.outcome, 'blocked');
        assert.match(report.reasons.join(' '), /captcha state is uncertain/);
    });

    it('a displayed reCAPTCHA frame of an unrecognised kind', async () => {
        const { report, details } = await check(
            frame(
                'https://www.recaptcha.net/recaptcha/enterprise/something-new',
                'width:120px;height:120px',
            ),
        );
        assert.equal(
            details.find((x) => x.srcPath.endsWith('/something-new'))!.verdict,
            'uncertain',
        );
        assert.equal(report.blockers.captcha, 'uncertain');
    });

    it('a displayed reCAPTCHA container with no frame loaded yet', async () => {
        const { report } = await check(
            '<div class="g-recaptcha" data-sitekey="synthetic" style="width:304px;height:78px"></div>',
        );
        assert.equal(report.blockers.captcha, 'uncertain');
    });

    it('the same unrecognised frame, hidden, is inactive', async () => {
        const { report } = await check(
            `<div style="visibility:hidden">${frame('https://www.recaptcha.net/recaptcha/enterprise/something-new', 'width:120px;height:120px')}</div>`,
        );
        assert.equal(report.blockers.captcha, 'inactive_markup');
    });
});
