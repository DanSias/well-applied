// M1.1: the scoring harness is itself tested, and the EXISTING extractor is measured against the M1 criteria as a baseline.
// The baseline is expected to be red: it is the number M1 has to beat. Offline; Chromium only reads fixtures.
import { test, describe, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { loadCorpus, loadCriteria, loadExpected, scoreFixture, aggregate, verdicts, meets, matchRows, runOnFixture } from './support/discovery-score.js';
import { legacyScorableRows } from './support/legacy-baseline.js';

const corpus = loadCorpus();
const criteria = loadCriteria();
// Discovery runs only on pages the classifier calls forms; listing pages are covered by the pipeline checks (F11).
const FORM_FIXTURES = corpus.fixtures.filter((f) => criteria.applies_to.includes(f.id) && f.kind === 'form');

/** Rows that match the expectations exactly: the harness must score these perfectly. */
const perfectRows = (expected) =>
    expected.m1.fields.map((f) => ({ key: f.match.dom_ids[0], label: f.label, kind: f.kind, required: f.required, sensitive: f.sensitive ?? false, label_confidence: f.label_confidence ?? 'structural', options: f.options ?? null, css: null }));

describe('the scoring harness', () => {
    const replica = corpus.fixtures.find((f) => f.id === 'greenhouse-style-form');
    const expected = loadExpected(replica);
    const score = async (rows) => aggregate([await scoreFixture({ fixtureId: replica.id, expected, rows, page: null })]);

    test('parses every threshold the criteria use', () => {
        assert.equal(meets(0.96, '>= 0.95'), true);
        assert.equal(meets(0.94, '>= 0.95'), false);
        assert.equal(meets(0.02, '<= 0.02'), true);
        assert.equal(meets(1, '== 1.00'), true);
        assert.equal(meets(1, '== 0'), false);
        assert.throws(() => meets(1, 'roughly one'));
    });

    test('matches by DOM id first, then by normalised label, and a row matches at most one field', () => {
        const fields = [{ match: { dom_ids: ['a'], label: 'First Name' } }, { match: { dom_ids: ['b'], label: 'Email' } }];
        const { pairs, missing, unmatchedRows } = matchRows(fields, [{ key: 'zzz', label: 'EMAIL*' }, { key: 'a', label: 'whatever' }, { key: 'q', label: 'extra' }]);
        assert.deepEqual(pairs.map((p) => [p.exp.match.dom_ids[0], p.index]), [['a', 1], ['b', 0]]);
        assert.equal(missing.length, 0);
        assert.deepEqual(unmatchedRows.map((u) => u.r.label), ['extra']);
    });

    test('perfect rows score perfectly on every functional metric', async () => {
        const v = verdicts(await score(perfectRows(expected)), criteria);
        assert.deepEqual(v.filter((x) => !x.pass), []);
    });

    // Each mutation must trip exactly the metric it is about, or the metric is not measuring anything.
    const mutations = [
        ['M1-F1', 'a field is not found', (r) => r.slice(1)],
        ['M1-F2', 'a label is wrong', (r) => r.map((x, i) => (i === 0 ? { ...x, label: 'Given name' } : x))],
        ['M1-F3', 'a required flag is wrong', (r) => r.map((x, i) => (i === 0 ? { ...x, required: !x.required } : x))],
        ['M1-F4', 'a kind is wrong', (r) => r.map((x, i) => (i === 0 ? { ...x, kind: 'tel' } : x))],
        ['M1-F4 select', 'a dropdown is reported as text', (r) => r.map((x) => (x.kind === 'select' ? { ...x, kind: 'text' } : x))],
        ['M1-F5', 'one control becomes two rows', (r) => [...r, { ...r.find((x) => x.key === 'country'), label: null }]],
        ['M1-F6', 'an unexpected row appears', (r) => [...r, { key: 'junk', label: 'junk', kind: 'text', required: false, sensitive: false, label_confidence: 'structural', options: null, css: null }]],
        ['M1-F7', 'a sensitive question is not flagged', (r) => r.map((x) => (x.key === 'gender' ? { ...x, sensitive: false } : x))],
        ['M1-F8', 'a non-sensitive question is flagged', (r) => r.map((x) => (x.key === 'phone' ? { ...x, sensitive: true } : x))],
        ['M1-F9', 'an inferred label is presented as structural', (r) => r.map((x) => (x.key === 'resume' ? { ...x, label_confidence: 'structural' } : x))],
        ['M1-F10', 'options states are wrong (two of ten)', (r) => r.map((x) => (['country', 'gender'].includes(x.key) ? { ...x, options: { state: 'known_in_dom', count: 3 } } : x))],
    ];
    for (const [id, what, mutate] of mutations) {
        test(`${id} is tripped when ${what}`, async () => {
            const v = verdicts(await score(mutate(perfectRows(expected))), criteria);
            assert.ok(v.some((x) => x.id === id && !x.pass), `${id} should fail`);
        });
    }

    test('must_not_report is enforced against the real DOM, by element identity', async () => {
        const browser = await chromium.launch({ headless: true, chromiumSandbox: process.env.BROWSER_WORKER_CHROMIUM_SANDBOX === '1' });
        try {
            const run = await runOnFixture(browser, replica, async () => []);
            const rows = [...perfectRows(expected), { key: null, label: null, kind: 'input', required: true, sensitive: false, label_confidence: 'unresolved', options: null, css: "input[aria-hidden='true'][tabindex='-1']" }];
            const c = await scoreFixture({ fixtureId: replica.id, expected, rows, page: run.page });
            assert.ok(c.integrity.some((i) => /country_hidden_validation_input/.test(i)), JSON.stringify(c.integrity));
            await run.close();
        } finally {
            await browser.close();
        }
    });
});

describe('the existing extractor, measured against the M1 criteria (the baseline M1 must beat)', () => {
    let browser;
    const results = [];

    before(async () => {
        browser = await chromium.launch({ headless: true, chromiumSandbox: process.env.BROWSER_WORKER_CHROMIUM_SANDBOX === '1' });
        for (const fixture of FORM_FIXTURES) {
            const expected = loadExpected(fixture);
            const run = await runOnFixture(browser, fixture, legacyScorableRows);
            results.push({ fixture, expected, counters: await scoreFixture({ fixtureId: fixture.id, expected, rows: run.rows, page: run.page }), safety: run.safety });
            await run.close();
        }
    });
    after(async () => {
        await browser?.close();
    });

    test('reports the baseline (informational table)', () => {
        const v = verdicts(aggregate(results.map((r) => r.counters)), criteria);
        const fmt = (x) => (Number.isInteger(x) ? String(x) : x.toFixed(3));
        console.log('\n  LEGACY BASELINE vs M1 criteria (the numbers M1 must beat)');
        for (const x of v) console.log(`   ${x.pass ? 'pass' : 'FAIL'}  ${x.id.padEnd(13)} ${x.name.padEnd(42)} ${fmt(x.value).padStart(6)}  (needs ${x.threshold})`);
        console.log('   per fixture: ' + results.map((r) => `${r.fixture.id} ${r.counters.matched}/${r.counters.expected_total} rows=${r.counters.rows_total} extra=${r.counters.extra_rows.length} integrity=${r.counters.integrity.length}`).join(' | '));
        assert.ok(v.length > 0);
    });

    test('is red: it fails the criteria M1 exists to meet', () => {
        const v = Object.fromEntries(verdicts(aggregate(results.map((r) => r.counters)), criteria).map((x) => [x.id, x]));
        // It has no sensitive notion, reports dropdowns as text, splits controls, and mislabels generic file inputs.
        assert.equal(v['M1-F7'].pass, false, 'legacy flags no sensitive question');
        assert.equal(v['M1-F4 select'].pass, false, 'legacy reports comboboxes as text');
        assert.equal(v['M1-F5'].pass, false, 'legacy reports hidden validation inputs and splits groups');
        assert.equal(v['M1-F9'].pass, false);
        assert.equal(v['M1-F10'].pass, false);
    });

    test('what it does well is real: required flags, and most labels, on plain forms', () => {
        const plain = results.find((r) => r.fixture.id === 'unknown-ats-form');
        assert.equal(plain.counters.matched, plain.counters.expected_total, 'finds every field of an ordinary accessible form');
        assert.equal(plain.counters.required_ok, plain.counters.matched);
    });

    test('is read-only here too: no request, no page change', () => {
        for (const r of results) {
            assert.deepEqual(r.safety.requests, [], r.fixture.id);
            assert.equal(r.safety.domChanged, false, r.fixture.id);
        }
    });
});
