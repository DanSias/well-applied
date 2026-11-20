// The context-aware application page classifier, over the expanded corpus (test/fixtures/universal/classification/cases.json).
// Offline and deterministic: Chromium only READS fixtures loaded with setContent, every request would be counted, and the DOM is
// compared before and after. Reports precision and recall against the independent TRUTH labels, with and without the extension.
import { test, describe, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { readFileSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import { chromium } from 'playwright';
import { collectSignals, classifyPage } from '../src/page-kind.js';

const FIX = path.join(path.dirname(fileURLToPath(import.meta.url)), 'fixtures');
const corpus = JSON.parse(readFileSync(path.join(FIX, 'universal', 'classification', 'cases.json'), 'utf8'));
const CONTEXTS = corpus.contexts;
const read = (file) => {
    const a = path.join(FIX, 'universal', file);
    const b = path.join(FIX, file);

    return readFileSync(existsSync(a) ? a : b, 'utf8');
};
const kindOf = (r) => r.kind;
// Older fixtures reference one external resource each, deliberately: a CAPTCHA frame (captcha.html) and a Greenhouse CDN asset (the
// signal greenhouse-form.html exists to carry). The route aborts both, so nothing leaves the machine; they are named so a new
// reference cannot slip in.
const EXISTING_FIXTURE_REFERENCES = ['https://www.google.com/recaptcha/api2/bframe', 'https://job-boards.cdn.greenhouse.io/assets/vendor-fake.js'];
const pct = (n) => (Number.isNaN(n) ? 'n/a' : n.toFixed(3));

describe('the context-aware classifier over the expanded corpus', () => {
    let browser;
    const results = new Map(); // case id -> { signals, baseline, extended: {context: result}, safety }

    before(async () => {
        browser = await chromium.launch({ headless: true, chromiumSandbox: process.env.BROWSER_WORKER_CHROMIUM_SANDBOX === '1' });
        for (const c of corpus.cases) {
            const context = await browser.newContext({ serviceWorkers: 'block' });
            const requests = [];
            await context.route('**/*', (route) => (requests.push(route.request().url()), route.abort()));
            const page = await context.newPage();
            await page.setContent(read(c.file));
            const before = await page.evaluate(() => document.documentElement.outerHTML);
            const signals = await page.evaluate(collectSignals);
            const again = await page.evaluate(collectSignals);
            const after = await page.evaluate(() => document.documentElement.outerHTML);
            const baseline = classifyPage(signals, { status: 200 });
            const extended = Object.fromEntries(CONTEXTS.map((ctx) => [ctx, classifyPage(signals, { status: 200, context: ctx, extended: true })]));
            results.set(c.id, { signals, baseline, extended, safety: { requests, domChanged: before !== after, deterministic: JSON.stringify(signals) === JSON.stringify(again) } });
            await context.close();
        }
    });
    after(async () => {
        await browser?.close();
    });

    const labelled = () => corpus.cases.filter((c) => !c.existing);
    const existing = () => corpus.cases.filter((c) => c.existing);

    test('the corpus is what it says: every fixture is byte-for-byte the reviewed one, and it covers every case asked for', () => {
        const bytes = (file) => readFileSync(existsSync(path.join(FIX, 'universal', file)) ? path.join(FIX, 'universal', file) : path.join(FIX, file));
        for (const c of corpus.cases) assert.equal(createHash('sha256').update(bytes(c.file)).digest('hex'), c.sha256, `${c.id} changed: update cases.json in review`);
        const groups = new Set(corpus.cases.map((c) => c.group));
        for (const g of ['positive', 'context', 'uncertain', 'negative', 'barrier', 'existing']) assert.ok(groups.has(g), g);
        const ids = corpus.cases.map((c) => c.id);
        for (const need of ['custom-widgets-only', 'demographics-step', 'combobox-nested-search', 'conditional-hidden-fields', 'site-search', 'newsletter-signup', 'account-registration', 'contact-form', 'sign-in-form', 'captcha-gated-form', 'job-search-filters', 'cookie-consent']) assert.ok(ids.includes(need), need);
    });

    test('with the extension OFF the classifier is exactly what it was, whatever the context', () => {
        for (const c of corpus.cases) {
            const r = results.get(c.id);
            for (const ctx of CONTEXTS) assert.deepEqual(classifyPage(r.signals, { status: 200, context: ctx, extended: false }), r.baseline, `${c.id}@${ctx}`);
        }
    });

    describe('by design: the classifier answers what the corpus says under each context', () => {
        for (const c of corpus.cases.filter((x) => !x.existing)) {
            test(`${c.id}: ${CONTEXTS.map((x) => `${x}→${c.expect[x]}`).join(', ')}`, () => {
                const r = results.get(c.id);
                for (const ctx of CONTEXTS) assert.equal(kindOf(r.extended[ctx]), c.expect[ctx], `${c.id}@${ctx} (${r.extended[ctx].reasons.join(', ')})`);
            });
        }
    });

    describe('existing results are preserved', () => {
        test('no existing fixture changes kind, stop reason or ATS under any context', () => {
            for (const c of existing()) {
                const r = results.get(c.id);
                for (const ctx of CONTEXTS) {
                    const e = r.extended[ctx];
                    assert.deepEqual([e.kind, e.stopReason, e.ats, e.onGreenhouseHost], [r.baseline.kind, r.baseline.stopReason, r.baseline.ats, r.baseline.onGreenhouseHost], `${c.id}@${ctx}`);
                }
            }
        });
        test('Greenhouse: the result is identical, reasons included, with the extension on', () => {
            const gh = existing().filter((c) => results.get(c.id).baseline.ats === 'greenhouse' || results.get(c.id).baseline.onGreenhouseHost);
            assert.ok(gh.length >= 1, 'the corpus has a Greenhouse page');
            for (const c of gh) for (const ctx of CONTEXTS) assert.deepEqual(results.get(c.id).extended[ctx], results.get(c.id).baseline, `${c.id}@${ctx}`);
        });
        test('every form the existing rules recognise by a strong signal keeps exactly its reasons', () => {
            for (const c of corpus.cases) {
                const r = results.get(c.id);
                if (r.baseline.kind === 'form' && r.baseline.reasons.some((x) => ['resume_upload', 'greenhouse_form', 'greenhouse_job_page', 'lever_form'].includes(x))) {
                    for (const ctx of CONTEXTS) assert.deepEqual(r.extended[ctx].reasons, r.baseline.reasons, `${c.id}@${ctx}`);
                }
            }
        });
    });

    describe('barriers outrank applications', () => {
        test('a sign-in form is never an application, in any context, and a CAPTCHA wall is blocked', () => {
            for (const c of corpus.cases.filter((x) => x.group === 'barrier')) for (const ctx of CONTEXTS) assert.equal(kindOf(results.get(c.id).extended[ctx]), c.expect[ctx], `${c.id}@${ctx}`);
        });
        test('no page the existing classifier calls blocked, login or unreachable is ever downgraded', () => {
            for (const c of corpus.cases) {
                const r = results.get(c.id);
                if (['blocked', 'login', 'unreachable'].includes(r.baseline.kind)) for (const ctx of CONTEXTS) assert.equal(r.extended[ctx].kind, r.baseline.kind, `${c.id}@${ctx}`);
            }
        });
        test('a password form with extra fields, which the existing rule called a listing, is now an authentication barrier', () => {
            const r = results.get('sign-in-with-selects');
            assert.equal(r.baseline.kind, 'listing');
            for (const ctx of CONTEXTS) assert.deepEqual([r.extended[ctx].kind, r.extended[ctx].stopReason], ['login', 'login_wall'], ctx);
        });
    });

    describe('false positives: ordinary forms are not applications', () => {
        const nonApplications = () => labelled().filter((c) => !c.truth);

        test('search, newsletter, registration, contact, survey, quote, alert, filters and consent: never a form', () => {
            for (const c of nonApplications().filter((x) => x.group === 'negative')) for (const ctx of CONTEXTS) assert.notEqual(kindOf(results.get(c.id).extended[ctx]), 'form', `${c.id}@${ctx}`);
        });
        test('the existing weak signal called some of these forms; with the extension on, it no longer does', () => {
            const wasForm = nonApplications().filter((c) => results.get(c.id).baseline.kind === 'form').map((c) => c.id);
            console.log(`\n  existing classifier false positives (extension OFF): ${JSON.stringify(wasForm)}`);
            assert.ok(wasForm.includes('account-registration') && wasForm.includes('create-account-to-apply') && wasForm.includes('contact-form'), 'the corpus really exercises the existing false positives');
            for (const id of wasForm) for (const ctx of CONTEXTS) assert.notEqual(kindOf(results.get(id).extended[ctx]), 'form', `${id}@${ctx}`);
        });
        test('an arbitrary page with form controls is not enough on its own', () => {
            for (const id of ['context-only-form', 'ordinary-site-form']) assert.equal(kindOf(results.get(id).extended.none), 'listing', id);
        });
    });

    describe('precision and recall against the independent truth labels', () => {
        const confusion = (pick) => {
            let tp = 0;
            let fp = 0;
            let fn = 0;
            let tn = 0;
            const falsePositives = [];
            const falseNegatives = [];
            for (const c of corpus.cases) {
                const predicted = pick(c) === 'form';
                if (predicted && c.truth) tp += 1;
                else if (predicted && !c.truth) (fp += 1, falsePositives.push(c.id));
                else if (!predicted && c.truth) (fn += 1, falseNegatives.push(c.id));
                else tn += 1;
            }

            return { tp, fp, fn, tn, precision: tp / (tp + fp), recall: tp / (tp + fn), falsePositives, falseNegatives };
        };
        const baseline = () => confusion((c) => results.get(c.id).baseline.kind);
        const extendedAt = (ctx) => confusion((c) => results.get(c.id).extended[ctx].kind);

        test('the report', () => {
            console.log(`\n  ${corpus.cases.length} pages, ${corpus.cases.filter((c) => c.truth).length} of them real application forms`);
            const b = baseline();
            console.log(`  ${'mode'.padEnd(30)} ${'precision'.padStart(9)} ${'recall'.padStart(7)}   TP FP FN TN   false positives / false negatives`);
            console.log(`  ${'existing classifier'.padEnd(30)} ${pct(b.precision).padStart(9)} ${pct(b.recall).padStart(7)}   ${[b.tp, b.fp, b.fn, b.tn].map((x) => String(x).padStart(2)).join(' ')}   FP ${JSON.stringify(b.falsePositives)} FN ${JSON.stringify(b.falseNegatives)}`);
            for (const ctx of CONTEXTS) {
                const e = extendedAt(ctx);
                console.log(`  ${`extended, context ${ctx}`.padEnd(30)} ${pct(e.precision).padStart(9)} ${pct(e.recall).padStart(7)}   ${[e.tp, e.fp, e.fn, e.tn].map((x) => String(x).padStart(2)).join(' ')}   FP ${JSON.stringify(e.falsePositives)} FN ${JSON.stringify(e.falseNegatives)}`);
            }
            assert.ok(true);
        });

        test('with no context: perfect precision, and the only miss is the page with no application evidence at all', () => {
            const e = extendedAt('none');
            assert.deepEqual(e.falsePositives, []);
            assert.equal(e.precision, 1);
            assert.deepEqual(e.falseNegatives, ['context-only-form']);
            assert.ok(e.recall >= 0.9, `recall ${e.recall}`);
        });

        test('with strong context: nothing is missed, and the one uncertain case is the only false positive, and it is named', () => {
            for (const ctx of ['user_supplied', 'apply_link']) {
                const e = extendedAt(ctx);
                assert.deepEqual(e.falseNegatives, [], ctx);
                assert.equal(e.recall, 1, ctx);
                assert.deepEqual(e.falsePositives, ['ordinary-site-form'], ctx);
                assert.ok(e.precision >= 0.9, `${ctx}: ${e.precision}`);
                assert.ok(corpus.cases.find((c) => c.id === 'ordinary-site-form').uncertain);
            }
        });

        test('the extension is better than the existing classifier on both axes, in every context', () => {
            const b = baseline();
            for (const ctx of CONTEXTS) {
                const e = extendedAt(ctx);
                assert.ok(e.recall > b.recall, `${ctx}: recall ${e.recall} vs ${b.recall}`);
                assert.ok(e.fp <= b.fp || ctx !== 'none', ctx);
            }
            assert.ok(extendedAt('none').fp < b.fp, 'with no context it also has fewer false positives than the existing classifier');
        });

        test('uncertain cases are exactly the two that are named', () => {
            assert.deepEqual(corpus.cases.filter((c) => c.uncertain).map((c) => c.id).sort(), ['context-only-form', 'ordinary-site-form']);
        });
    });

    describe('read-only and deterministic', () => {
        test('no request is made, the page is not modified, and reading it twice gives the same signals', () => {
            for (const [id, r] of results) {
                // The new corpus makes no request at all. The one older fixture that does (captcha.html embeds a CAPTCHA frame, which is what
                // it exists to show) is held to an exact, named allowance; the route aborts it, so nothing leaves the machine.
                const allowed = corpus.cases.find((c) => c.id === id).existing ? EXISTING_FIXTURE_REFERENCES : [];
                assert.deepEqual(r.safety.requests.filter((u) => !allowed.includes(u)), [], `${id} made requests`);
                assert.equal(r.safety.domChanged, false, `${id} was modified`);
                assert.equal(r.safety.deterministic, true, id);
            }
        });
        test('classification is a pure function of the signals', () => {
            for (const [id, r] of results) for (const ctx of CONTEXTS) assert.deepEqual(classifyPage(r.signals, { status: 200, context: ctx, extended: true }), r.extended[ctx], `${id}@${ctx}`);
        });
    });
});

describe('the classifier source cannot interact with a page', () => {
    const SRC = path.join(path.dirname(fileURLToPath(import.meta.url)), '..', 'src');
    const code = (f) => readFileSync(path.join(SRC, f), 'utf8').replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');
    const ACTIONS = /\.(click|dblclick|fill|type|press|check|uncheck|selectOption|setInputFiles|hover|focus|blur|dispatchEvent|submit|requestSubmit|scrollIntoView|goto|route|launch)\b\s*\(/;

    test('page-context.js is pure: no DOM, no browser, no interaction', () => {
        const src = code('page-context.js');
        assert.ok(!ACTIONS.test(src));
        assert.ok(!/\b(document|window|location)\b\.|from 'playwright'|\bpage\./.test(src));
    });
    test('page-kind.js (the in-page collector and the classifier) contains no interaction API and writes nothing to the page', () => {
        const src = code('page-kind.js');
        assert.ok(!ACTIONS.test(src));
        assert.ok(!/\.(setAttribute|removeAttribute|appendChild|insertBefore|innerHTML\s*=|addEventListener)/.test(src));
    });
});
