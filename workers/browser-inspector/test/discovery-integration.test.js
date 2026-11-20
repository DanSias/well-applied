// M1.5: generic discovery inside the real inspect() pipeline, behind BROWSER_WORKER_GENERIC_DISCOVERY (default off). Real
// Chromium, local fixture servers only; the guard allows exactly those host:port pairs and refuses everything else.
import { test, describe, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import { inspect } from '../src/inspect.js';
import { genericDiscoveryRequested } from '../src/discovery/flag.js';
import { startSites, fixture } from './support/fixture-server.js';
import { loadCorpus, loadExpected } from './support/discovery-score.js';

const corpus = loadCorpus();
const FAST = { settleMs: 100, networkIdleMs: 800 };
const html = (name) => () => ({ body: fixture(name) });
const nonGet = []; // every non-GET request that REACHES a fixture server (there must be none)

let sites;
let lever;

before(async () => {
    const universal = Object.fromEntries(corpus.fixtures.filter((f) => !f.existing).map((f) => [`/u/${f.id}`, html(`universal/forms/${f.id}.html`)]));
    const classification = Object.fromEntries(
        JSON.parse(readFileSync(path.join(path.dirname(fileURLToPath(import.meta.url)), 'fixtures', 'universal', 'classification', 'cases.json'), 'utf8'))
            .cases.filter((c) => c.file.startsWith('classification/pages/'))
            .map((c) => [`/c/${c.id}`, html(`universal/${c.file}`)]),
    );
    sites = await startSites({
        board: {
            ...universal,
            ...classification,
            // A listing with one Apply link, to be pointed at whichever page the test wants reached through it.
            '/to/context-only-form': ({ origins }) => ({ body: `<title>Engineer</title><h1>Engineer</h1><a href="${origins.board}/c/context-only-form">Apply Now</a>` }),
            '/to/job-search-filters': ({ origins }) => ({ body: `<title>Engineer</title><h1>Engineer</h1><a href="${origins.board}/c/job-search-filters">Apply Now</a>` }),
            '/to/newsletter-signup': ({ origins }) => ({ body: `<title>Engineer</title><h1>Engineer</h1><a href="${origins.board}/c/newsletter-signup">Apply Now</a>` }),
            // The aggregator fixture links to itself by a relative path, so it must be served at that path.
            '/auth-gate-aggregator.html': html('universal/forms/auth-gate-aggregator.html'),
            '/phase1/unknown-form': html('phase1/unknown-ats-form.html'),
            '/__trap/apply': ({ method }) => (nonGet.push(`apply ${method}`), { body: 'trap' }),
            '/__trap/job-alert': ({ method }) => (nonGet.push(`job-alert ${method}`), { body: 'trap' }),
            '/__trap/signin': ({ method }) => (nonGet.push(`signin ${method}`), { body: 'trap' }),
        },
        ats: { '/acme/jobs/1': html('greenhouse-form.html') },
    });
    lever = await startSites({ site: { '/acme/11111111-1111-4111-8111-111111111111/apply': html('phase1/unknown-ats-form.html') } });
});
after(async () => {
    await sites.close();
    await lever.close();
});

const run = (url, genericDiscovery, extra = {}) => inspect(url, { guard: sites.guard(), launch: sites.launch(), timeouts: FAST, budgetMs: 60_000, genericDiscovery, ...extra });
const leverRun = (genericDiscovery) => {
    const origin = `http://jobs.lever.co:${lever.site('site').port}`;

    return inspect(`${origin}/acme/11111111-1111-4111-8111-111111111111/apply`, { guard: lever.guard([`jobs.lever.co:${lever.site('site').port}`]), launch: lever.launch(['MAP jobs.lever.co 127.0.0.1']), timeouts: FAST, budgetMs: 60_000, genericDiscovery });
};
const stable = (r) => ({ ...r, diagnostics: { ...r.diagnostics, total_ms: 0, navigation_ms: 0, extraction_ms: 0, timestamp: 0 } });

describe('the flag', () => {
    test('is off unless the environment says exactly BROWSER_WORKER_GENERIC_DISCOVERY=1', () => {
        assert.equal(genericDiscoveryRequested({}), false);
        for (const v of ['0', 'true', 'yes', '', 'on']) assert.equal(genericDiscoveryRequested({ BROWSER_WORKER_GENERIC_DISCOVERY: v }), false, v);
        assert.equal(genericDiscoveryRequested({ BROWSER_WORKER_GENERIC_DISCOVERY: '1' }), true);
    });
    test('defaults to the environment (off here), so nothing changes until it is turned on', async () => {
        assert.equal(process.env.BROWSER_WORKER_GENERIC_DISCOVERY, undefined);
        const r = await inspect(`${sites.origins.board}/phase1/unknown-form`, { guard: sites.guard(), launch: sites.launch(), timeouts: FAST, budgetMs: 60_000 });
        assert.equal(r.inspection_outcome, 'unsupported');
    });
});

describe('flag OFF: exactly the behaviour that exists today', () => {
    test('an unknown ATS form is unsupported / form_without_adapter, with no fields', async () => {
        const r = await run(`${sites.origins.board}/phase1/unknown-form`, false);
        assert.deepEqual([r.inspection_outcome, r.stop_reason, r.fields.length], ['unsupported', 'form_without_adapter', 0]);
        assert.ok(!r.warnings.includes('generic_discovery'));
    });
    test('a Lever form is unsupported / ats_not_extractable', async () => {
        const r = await leverRun(false);
        assert.deepEqual([r.inspection_outcome, r.stop_reason, r.ats, r.fields.length], ['unsupported', 'ats_not_extractable', 'lever', 0]);
    });
});

describe('flag ON: forms with no dedicated extractor are read, and only those', () => {
    test('an accessible form on an unknown ATS goes from unsupported to complete, with the fields it really has', async () => {
        const r = await run(`${sites.origins.board}/phase1/unknown-form`, true);
        assert.deepEqual([r.status, r.inspection_outcome, r.stop_reason, r.ats], ['succeeded', 'complete', null, null]);
        assert.deepEqual(r.fields.map((f) => [f.raw_label, f.control_type, f.required]), [['First name', 'text', true], ['Last name', 'text', true], ['Email', 'email', true], ['Phone', 'tel', false], ['Resume', 'file', true], ['Why do you want to work here?', 'textarea', false]]);
        assert.match(r.resolved_url, /\/phase1\/unknown-form$/);
        assert.ok(r.warnings.includes('generic_discovery'), 'the path taken is recorded');
        assert.equal(r.diagnostics.follows, 0);
    });

    test('a Lever form is read too, and keeps its ATS', async () => {
        const r = await leverRun(true);
        assert.deepEqual([r.inspection_outcome, r.ats, r.fields.length], ['complete', 'lever', 6]);
    });

    test('Greenhouse is untouched: the result is identical with the flag on or off', async () => {
        const off = await run(`${sites.origins.ats}/acme/jobs/1`, false);
        const on = await run(`${sites.origins.ats}/acme/jobs/1`, true);
        assert.equal(off.ats, 'greenhouse');
        assert.deepEqual(stable(on), stable(off));
        assert.ok(!on.warnings.includes('generic_discovery'));
    });

    test('every field it reports is in the existing contract: the same keys, the existing enums', async () => {
        const r = await run(`${sites.origins.board}/phase1/unknown-form`, true);
        for (const f of r.fields) {
            assert.deepEqual(Object.keys(f).sort(), ['control_type', 'external_field_id', 'extraction_source', 'label_source', 'options', 'position', 'raw_label', 'required', 'section']);
            assert.ok(['dom_label_for', 'dom_wrapping_label', 'dom_aria_label', 'dom_aria_labelledby', 'dom_placeholder', 'inferred_proximity', 'unresolved'].includes(f.label_source));
            assert.ok(['dom', 'aria', 'both'].includes(f.extraction_source));
        }
    });
});

// With generic discovery on, the classifier also recognises an application that lacks the strong signals (a resume field, or a name
// and an email), from application CONTEXT or from independent application CONTENT (src/page-context.js). The three corpus forms
// below have no such signal, so with the flag off they are listings, exactly as before.
const RECOGNISED = ['greenhouse-style-form', 'labels-vs-headings', 'multi-step'];
const CONTENT_ONLY = ['combobox-nested-search', 'conditional-hidden-fields', 'sensitive-demographics'];

describe('flag ON: a form is NOT called complete when content was seen but not read', () => {
    test('M1-F12: every recognised form fixture ends with an outcome its M0 expectation allows', async () => {
        for (const id of RECOGNISED) {
            const fx = corpus.fixtures.find((f) => f.id === id);
            const r = await run(`${sites.origins.board}/u/${id}`, true);
            assert.equal(r.status, 'succeeded', id);
            assert.ok(loadExpected(fx).m1.outcome_any_of.includes(r.inspection_outcome), `${id}: ${r.inspection_outcome}`);
        }
    });

    test('forms with only dropdowns, conditional fields or demographic questions: unchanged with the flag OFF, read with it ON', async () => {
        for (const id of CONTENT_ONLY) {
            const off = await run(`${sites.origins.board}/u/${id}`, false);
            assert.deepEqual([off.inspection_outcome, off.stop_reason, off.fields.length], ['application_not_found', 'no_apply_link', 0], `${id}: flag off must be unchanged`);

            const on = await run(`${sites.origins.board}/u/${id}`, true);
            assert.ok(on.fields.length > 0, `${id}: nothing was read`);
            assert.ok(['complete', 'partial'].includes(on.inspection_outcome), id);
            assert.ok(on.warnings.includes('application_form_inferred_from_content'), `${id}: how it was recognised is recorded`);
            assert.ok(on.warnings.includes('generic_discovery'), id);
        }
    });

    test('a multi-step form is partial, and says so', async () => {
        const r = await run(`${sites.origins.board}/u/multi-step`, true);
        assert.deepEqual([r.inspection_outcome, r.stop_reason], ['partial', null]);
        assert.deepEqual(r.warnings.filter((w) => w !== 'generic_discovery'), ['conditional_fields_not_inspected', 'more_steps_not_inspected']);
        assert.deepEqual(r.fields.map((f) => f.raw_label), ['First name', 'Last name', 'Email']);
    });

    test('controls a label points to that are not in the page make a form partial, and the fields found are still reported', async () => {
        const r = await run(`${sites.origins.board}/u/greenhouse-style-form`, true);
        assert.equal(r.inspection_outcome, 'partial');
        assert.ok(r.warnings.includes('conditional_fields_not_inspected'));
        assert.equal(r.fields.length, 19);
        const by = Object.fromEntries(r.fields.map((f) => [f.external_field_id, f]));
        assert.deepEqual([by.country.control_type, by.resume.raw_label, by.resume.label_source, by.gender.control_type], ['select', 'Resume/CV', 'inferred_proximity', 'select']);
    });

    test('a fully read form is complete', async () => {
        assert.equal((await run(`${sites.origins.board}/u/labels-vs-headings`, true)).inspection_outcome, 'complete');
    });

    test('a form with no readable fields keeps the existing partial / no_fields_extracted result', async () => {
        const empty = await startSites({ s: { '/f': () => ({ body: '<title>Apply</title><h1>Apply</h1><form><button type="submit">Submit application</button></form><p>Application form</p>' }) } });
        try {
            const r = await inspect(`${empty.origins.s}/f`, { guard: empty.guard(), launch: empty.launch(), timeouts: FAST, budgetMs: 60_000, genericDiscovery: true });
            if (r.inspection_outcome === 'partial') assert.equal(r.stop_reason, 'no_fields_extracted');
            else assert.notEqual(r.inspection_outcome, 'complete');
        } finally {
            await empty.close();
        }
    });
});

describe('flag ON: listing pages still end where they did, and nothing is ever activated', () => {
    for (const id of ['auth-gate-aggregator', 'apply-button-nonget']) {
        test(`M1-F11: ${id} is application_not_found with apply_is_button, and no fields`, async () => {
            const e = loadExpected(corpus.fixtures.find((f) => f.id === id)).m1;
            const path = id === 'auth-gate-aggregator' ? '/auth-gate-aggregator.html' : `/u/${id}`;
            const r = await run(`${sites.origins.board}${path}`, true);
            assert.deepEqual([r.inspection_outcome, r.stop_reason], [e.outcome, e.stop_reason]);
            for (const w of e.warnings_include) assert.ok(r.warnings.includes(w), w);
            assert.deepEqual(r.fields, []);
        });
    }
    test('M1-S3: no non-GET request ever reached a server, across every run above', () => {
        assert.deepEqual(nonGet, []);
    });
});

describe('flag ON: application CONTEXT decides what a page with no application hallmark is', () => {
    const page = (id) => `${sites.origins.board}/c/${id}`;
    const withOrigin = (url, startOrigin, flag = true) => run(url, flag, { startOrigin });

    test('a URL the person supplied is strong context: the dropdown-and-free-text page is read, and says why', async () => {
        const r = await withOrigin(page('context-only-form'), 'user');
        assert.ok(r.fields.length === 3, JSON.stringify(r.fields.map((f) => f.raw_label)));
        assert.deepEqual(r.fields.map((f) => f.control_type), ['select', 'select', 'text']);
        assert.ok(r.warnings.includes('application_form_inferred_from_context'));
        assert.ok(['complete', 'partial'].includes(r.inspection_outcome));
    });

    test('the same page found on a listing (no context) is NOT read: a URL on a job board says nothing', async () => {
        for (const origin of [undefined, null, 'source']) {
            const r = await withOrigin(page('context-only-form'), origin);
            assert.deepEqual([r.inspection_outcome, r.fields.length], ['application_not_found', 0], String(origin));
        }
    });

    test('and with the flag OFF, even a user-supplied URL behaves exactly as it does today', async () => {
        const r = await withOrigin(page('context-only-form'), 'user', false);
        assert.deepEqual([r.inspection_outcome, r.stop_reason, r.fields.length], ['application_not_found', 'no_apply_link', 0]);
    });

    test('a destination reached through an identified Apply link is strong context', async () => {
        const r = await run(`${sites.origins.board}/to/context-only-form`, true, { startOrigin: 'source' });
        assert.equal(r.diagnostics.follows, 1);
        assert.equal(r.fields.length, 3);
        assert.ok(r.warnings.includes('application_form_inferred_from_context'));
    });

    test('but an Apply link that leads to a job search, or a newsletter signup, is not an application', async () => {
        for (const id of ['job-search-filters', 'newsletter-signup']) {
            const r = await run(`${sites.origins.board}/to/${id}`, true, { startOrigin: 'source' });
            assert.deepEqual([r.inspection_outcome, r.fields.length], ['application_not_found', 0], id);
        }
    });

    test('even a user-supplied URL does not turn search, sign-up, contact, consent or alert pages into applications', async () => {
        for (const id of ['site-search', 'newsletter-signup', 'account-registration', 'create-account-to-apply', 'contact-form', 'survey-form', 'job-alerts-signup', 'cookie-consent', 'quote-request', 'job-search-filters', 'magic-link-sign-in']) {
            const r = await withOrigin(page(id), 'user');
            assert.deepEqual(r.fields, [], id);
            assert.notEqual(r.inspection_outcome, 'complete', id);
        }
    });
});

describe('flag ON: authentication and CAPTCHA outrank any application', () => {
    const page = (id) => `${sites.origins.board}/c/${id}`;

    test('a sign-in form, even one with extra dropdowns headed "continue your application", is an authentication barrier', async () => {
        for (const origin of ['user', 'source']) {
            for (const id of ['sign-in-form', 'sign-in-with-selects']) {
                const r = await run(page(id), true, { startOrigin: origin });
                assert.deepEqual([r.inspection_outcome, r.stop_reason, r.fields.length], ['authentication_required', 'login_wall', 0], `${id} (${origin})`);
            }
        }
    });

    test('a form behind a CAPTCHA notice is blocked, not read', async () => {
        for (const origin of ['user', 'source']) {
            const r = await run(page('captcha-gated-form'), true, { startOrigin: origin });
            assert.deepEqual([r.inspection_outcome, r.stop_reason, r.fields.length], ['blocked', 'captcha', 0], origin);
        }
    });

    test('with the flag OFF, those pages end exactly as they did before', async () => {
        const before = {};
        for (const id of ['sign-in-form', 'sign-in-with-selects', 'captcha-gated-form']) {
            const r = await run(page(id), false, { startOrigin: 'user' });
            before[id] = [r.inspection_outcome, r.stop_reason];
        }
        assert.deepEqual(before, { 'sign-in-form': ['authentication_required', 'login_wall'], 'sign-in-with-selects': ['application_not_found', 'no_apply_link'], 'captcha-gated-form': ['application_not_found', 'no_apply_link'] });
    });

    test('Greenhouse is identical with the flag on or off, in every context', async () => {
        for (const origin of [undefined, 'user', 'source', 'resolved']) {
            const off = await run(`${sites.origins.ats}/acme/jobs/1`, false, { startOrigin: origin });
            const on = await run(`${sites.origins.ats}/acme/jobs/1`, true, { startOrigin: origin });
            assert.deepEqual(stable(on), stable(off), String(origin));
            assert.ok(!on.warnings.some((w) => w.startsWith('application_form_inferred')));
        }
    });

    test('M1-S3 still holds across all of the above: no non-GET request ever reached a server', () => {
        assert.deepEqual(nonGet, []);
    });
});
