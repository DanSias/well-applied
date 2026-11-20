// M1.5: generic discovery inside the real inspect() pipeline, behind BROWSER_WORKER_GENERIC_DISCOVERY (default off). Real
// Chromium, local fixture servers only; the guard allows exactly those host:port pairs and refuses everything else.
import { test, describe, before, after } from 'node:test';
import assert from 'node:assert/strict';
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
    sites = await startSites({
        board: {
            ...universal,
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

// The classifier (src/page-kind.js) calls a page a form only on a strong application signal: a resume field, or a name and an
// email field on a page that talks about applying. M1 wires discovery to the pages it already recognises as forms, and does
// not widen that gate. These three corpus forms are read by discovery (see discovery-corpus.test.js) but never reach it here.
const RECOGNISED = ['greenhouse-style-form', 'labels-vs-headings', 'multi-step'];
const NOT_RECOGNISED = ['combobox-nested-search', 'conditional-hidden-fields', 'sensitive-demographics'];

describe('flag ON: a form is NOT called complete when content was seen but not read', () => {
    test('M1-F12: every recognised form fixture ends with an outcome its M0 expectation allows', async () => {
        for (const id of RECOGNISED) {
            const fx = corpus.fixtures.find((f) => f.id === id);
            const r = await run(`${sites.origins.board}/u/${id}`, true);
            assert.equal(r.status, 'succeeded', id);
            assert.ok(loadExpected(fx).m1.outcome_any_of.includes(r.inspection_outcome), `${id}: ${r.inspection_outcome}`);
        }
    });

    test('KNOWN GAP, unchanged by the flag: forms with no resume, email or name-and-email hallmark are not recognised as forms', async () => {
        for (const id of NOT_RECOGNISED) {
            const off = await run(`${sites.origins.board}/u/${id}`, false);
            const on = await run(`${sites.origins.board}/u/${id}`, true);
            assert.deepEqual([on.inspection_outcome, on.stop_reason, on.fields.length], ['application_not_found', 'no_apply_link', 0], id);
            assert.deepEqual(stable(on).warnings, stable(off).warnings, `${id}: the flag changed a page it should not touch`);
            assert.ok(!on.warnings.includes('generic_discovery'), id);
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
